<?php
namespace axenox\Deployer\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Exceptions\Actions\ActionInputError;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Tasks\ResultMessageStream;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use axenox\Deployer\DataTypes\BuildRecipeDataType;
use axenox\Deployer\Common\Execution\LegacyDeploymentExecutor;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\iCreateData;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Factories\DataConnectionFactory;
use axenox\Deployer\Actions\Traits\BuildProjectTrait;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Facades\HttpFileServerFacade;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\DataTypes\JsonDataType;

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Common' . DIRECTORY_SEPARATOR . 'Execution' . DIRECTORY_SEPARATOR . 'DeploymentPackageBuilder.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Common' . DIRECTORY_SEPARATOR . 'Execution' . DIRECTORY_SEPARATOR . 'LegacyDeploymentExecutor.php';

/**
 * Deploys a build to a specific host.
 * 
 * This action requires an instance of a build and an instance of a host as parameters.
 * The action may either be called via the PowerUI frontend or via CLI-command.
 * The parameters for the CLI-call are the names of the host and build, which have to be existing instances of
 * `axenox.Deployer.host` and `axenox.Deployer.build` objects.
 * The action extracts deployment data from the selected build and host, then creates the selected
 * deployment artifact. Self-extractor artifacts remain in the project build folder for transfer or download.
 * 
 * ## Commandline Usage:
 * 
 * ```
 * action axenox.Deployer:Deploy [BuildName] [HostName]
 * ```
 * 
 * For example:
 * ```
 * action axenox.Deployer:Deploy 1.1.2+20191108145134 sdrexf2
 * ```
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class Deploy extends AbstractActionDeferred implements iCanBeCalledFromCLI, iCreateData
{
    use BuildProjectTrait;
    
    private $projectData = null;

    private $buildData = null;

    private $hostData = null;
    
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\AbstractAction::init()
     */
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setInputRowsMax(1);
        $this->setInputObjectAlias('axenox.Deployer.deployment');
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        $this->validateHostsBelongToBuildProject($task);
        // $buildData based on object axenox.Deployer.deployment
        try {
            $deployData = $this->getInputDataSheet($task);
        } catch (ActionInputMissingError $e) {
            $deployData = DataSheetFactory::createFromObject($this->getInputObjectExpected());
            $hostCount = $this->getHostCount($task);
            for ($hostIndex = 0; $hostIndex < $hostCount; $hostIndex++) {
                $deployData->addRow([
                    'build' => $this->getBuildData($task, 'name'),
                    'host' => $this->getHostData($task, 'name', $hostIndex)
                ]);
            }
        }
        return [$task, $deployData];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(TaskInterface $task = null, DataSheetInterface $deployData = null) : \Generator
    {
        if ($task === null) {
            throw new InvalidArgumentException('Missing argument $task in deferred action call!');
        }
        
        if ($deployData === null) {
            throw new InvalidArgumentException('Missing argument $deployData in deferred action call!');
        }
        
        $hostCount = $this->getHostCount($task);

        for($hostIndex = 0; $hostIndex < $hostCount; $hostIndex++) {
            $deployData->setCellValue('status', $hostIndex, 50);
            $deployData->setCellValue('host', $hostIndex, $this->getHostData($task, 'uid', $hostIndex));
            $deployData->setCellValue('build', $hostIndex, $this->getBuildData($task, 'uid'));
            $deployData->setCellValue('started_on', $hostIndex, date(DateTimeDataType::DATETIME_FORMAT_INTERNAL));
            $deployData->setCellValue('deploy_recipe_file', $hostIndex, $this->getLegacyDeployRecipeReference($task));
        }
        
        // Use a separate transaction so each deployment record is persisted independently.
        $deployData->dataCreate(false);
        
        for($hostIndex = 0; $hostIndex < $hostCount; $hostIndex++) {
            $seconds = time();
            $log = '';
            try {
                $buildName = $this->getBuildData($task, 'name');
                $hostName = $this->getHostData($task, 'name', $hostIndex);
                $projectFolder = $this->getProjectFolderRelativePath($task);
                $recipe = $this->getProjectData($task, 'deployment_recipe');
                $basePath = $this->getBasePath();
                $archivePath = $basePath . $projectFolder . DIRECTORY_SEPARATOR
                    . $this->getFolderNameForBuilds() . DIRECTORY_SEPARATOR . $buildName . '.tar.gz';
                $deployConfigJson = $this->getHostData($task, 'deploy_config', $hostIndex) ?? '{}';
                try {
                    $deployConfig = JsonDataType::decodeJson($deployConfigJson);
                } catch (\Throwable $e) {
                    throw new ActionRuntimeError($this, 'Cannot parse deployment configuration: not a valid JSON!');
                }
                if (! is_array($deployConfig)) {
                    throw new ActionRuntimeError($this, 'Cannot parse deployment configuration: expected a JSON object.');
                }
                $basicDeployPath = $this->getHostData($task, 'path_abs_to_api', $hostIndex) ?? '';
                if (($deployConfig['default_app_config']['System.config.json'] ?? null) !== null
                    && ($deployConfig['default_app_config']['System.config.json']['SERVER.INSTALLATION_NAME'] ?? null) === null) {
                    $deployFolderName = FilePathDataType::findFileName($basicDeployPath, false);
                    $deployConfig['default_app_config']['System.config.json']['SERVER.INSTALLATION_NAME'] = $deployFolderName;
                }

                $connectionConfig = [];
                if ($recipe === 'LocalBldUpdaterPush') {
                    $connectionConfig = $this->getHostConnectionConfig($task, $hostIndex);
                }

                $executor = new LegacyDeploymentExecutor();
                $execution = $executor->execute(
                    (string) $recipe,
                    (string) $buildName,
                    self::getHostAlias($hostName),
                    $archivePath,
                    $basePath . $projectFolder . DIRECTORY_SEPARATOR . $this->getFolderNameForBuilds(),
                    $basePath . Filemanager::FOLDER_NAME_VENDOR . DIRECTORY_SEPARATOR . $this->getApp()->getDirectory()
                        . DIRECTORY_SEPARATOR . 'Recipes' . DIRECTORY_SEPARATOR . 'SelfExtractingPHP'
                        . DIRECTORY_SEPARATOR . 'SelfExtractingDeployment.php',
                    [
                        'basic_deploy_path' => $basicDeployPath,
                        'relative_deploy_path' => $this->getHostData($task, 'path_rel_to_releases', $hostIndex) ?? '',
                        'php_path' => $this->getHostData($task, 'php_cli', $hostIndex) ?? 'php'
                    ],
                    $deployConfig,
                    $connectionConfig
                );

                yield $this->escapeCliMessage(PHP_EOL . '========== Starting deployment (' . ($hostIndex + 1) . '/' . $hostCount . ') for host: "' . $hostName . '" ==========');
                foreach ($execution as $output) {
                    $output = $this->replaceFilePathsWithHyperlinks($output);
                    yield $this->escapeCliMessage($output);
                    $log .= $output;
                    $deployData->setCellValue('log', $hostIndex, $log);
                    $deployData->dataUpdate(false);
                }
                $executionResult = $execution->getReturn();
                $seconds = time() - $seconds;
                if ($executionResult['published']) {
                    $deployData->setCellValue('status', $hostIndex, 60);
                    $msg = '✔ SUCCEEDED publishing build ' . $buildName . ' for download by ' . $hostName . ' in ' . $seconds . ' seconds.';
                } else {
                    $deployData->setCellValue('status', $hostIndex, 99);
                    $deployData->setCellValue('completed_on', $hostIndex, DateTimeDataType::now());
                    $msg = '✔ SUCCEEDED preparing deployment of build ' . $buildName . ' for ' . $hostName . ' in ' . $seconds . ' seconds.';
                }
                yield $msg;
                $log .= $msg;

                $deployData->setCellValue('log', $hostIndex, $log);
                $deployData->dataUpdate(false);
            } catch (\Throwable $e) {
                $log .= PHP_EOL . '✘ ERRROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
                $deployData->setCellValue('log', $hostIndex, $log);
                $deployData->setCellValue('status', $hostIndex, 90); // failed
                $deployData->setCellValue('completed_on', $hostIndex, DateTimeDataType::now());
                $this->getWorkbench()->getLogger()->logException($e);
                $deployData->dataUpdate(false);
            }
        }
    }
    
    /**
     * Replaces C:\... with http://... links for files within the project folder
     * 
     * @param string $msg
     * @return string
     */
    protected function replaceFilePathsWithHyperlinks(string $msg) : string
    {
        $urlMatches = [];
        if (preg_match_all('/"(' . preg_quote($this->getBasePath(), '/') . '[^"]*)"/', $msg, $urlMatches) > 0) {
            foreach ($urlMatches[1] as $urlPath) {
                $url = HttpFileServerFacade::buildUrlToDownloadFile($this->getWorkbench(), $urlPath, false);
                $msg = str_replace($urlPath, $url, $msg);
            }
        }
        return $msg;
    }
    
    protected function escapeCliMessage(string $msg) : string
    {
        // TODO handle strange empty spaces in composer output
        return str_replace(["\r", "\n"], PHP_EOL, $msg);
    }
     
    /**
     * This function takes an task, and an attribute as parameter, and returns the data of
     * the tasks project data, stored under the attribute passed as a parameter.
     *
     * @param TaskInterface $task
     * @param string $projectAttributeAlias
     * @throws ActionInputMissingError
     * @return string
     */
    protected function getProjectData(TaskInterface $task, string $projectAttributeAlias) : string
    {
        if ($this->projectData === null) {
            $projectUid = $this->getBuildData($task, 'project');
            
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.project');
            $ds->getColumns()->addMultiple([
                'alias',
                'build_recipe',
                'build_recipe_custom_path',
                'default_composer_json',
                'default_composer_auth_json',
                'deployment_recipe',
                'deployment_recipe_custom_path',
                'name',
                'project_group'
            ]);
            $ds->getFilters()->addConditionFromString('uid', $projectUid, ComparatorDataType::EQUALS);
            $ds->dataRead();
            $this->projectData = $ds;
        }
        return $this->projectData->getCellValue($projectAttributeAlias, 0);
    }

    /**
     * This function takes an task, and an attribute as parameter, and returns the data of
     * the tasks host data, stored under the attribute passed as a parameter.
     *
     * @param TaskInterface $task
     * @param string $option
     * @param int $index
     * @return string|null
     */
    protected function getHostData(TaskInterface $task, string $option, int $index = 0) : ?string
    {
        if ($this->hostData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.host');
            $ds->getColumns()->addMultiple([
                'data_connection',
                'last_build_deployed',
                'name',
                'operating_system',
                'path_abs_to_api',
                'path_rel_to_releases',
                'php_cli',
                'project',
                'stage',
                'deploy_config'
            ]);
            $hostName = null;
            $hostUid = null;
            
            if ($task->hasParameter('host')) {
                $hostName = $task->getParameter('host');
                $hostNameDelimiter = $this->getWorkbench()->model()->getObject('axenox.Deployer.host')->getAttribute('name')->getValueListDelimiter();
                $hostNames = array_filter(
                    array_unique(
                        explode($hostNameDelimiter, $hostName)
                    )
                );
                $ds->getFilters()->addConditionFromValueArray('name', $hostNames);
            } else {
                $inputData = $this->getInputDataSheet($task);
                if ($col = $inputData->getColumns()->get('host')) {
                    $hostUids = $col->getValues();
                    $ds->getFilters()->addConditionFromValueArray('uid', $hostUids);
                }
            }
            
            // Make sure, filters are set - otherwise we will deploy to ALL hosts
            if ($ds->getFilters()->isEmpty(true)) {
                throw new ActionInputMissingError($this, 'Cannot deploy build: missing host reference!', '78810KV');
            }
            
            $ds->dataRead();
            if ($ds->isEmpty()) {
                throw new ActionInputError($this, "Host with name/UID '" . ($hostName ?? $hostUid) . "' not found!", '78810KV');
            }
            $this->hostData = $ds;
        }
        return $this->hostData->getCellValue($option, $index);
    }

    /**
     * Gets the host count.
     * 
     * @param TaskInterface $task
     * @return int
     */
    protected function getHostCount(TaskInterface $task) : int
    {
        if ($this->hostData === null) {
            // Filling the hostData if its empty first:
            $this->getHostData($task, 'name');
        }

        return count($this->hostData->getColumnValues('name'));
    }
    
    /**
     * This function takes an task, and an attribute as parameter, and returns the data of
     * the tasks build data, stored under the attribute passed as a parameter.
     *
     * @param TaskInterface $task
     * @param string $projectAttributeAlias
     * @return string|null
     */
    protected function getBuildData(TaskInterface $task, string $projectAttributeAlias) : ?string
    {
        if ($this->buildData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.build');
            $ds->getColumns()->addMultiple([
                'build_datatime',
                'build_recipe_path',
                'comment',
                'composer_auth_json',
                'composer_json',
                'log',
                'name',
                'notes',
                'project',
                'status',
                'version'
            ]);

            if ($task->hasParameter('build')) {
                $buildName = $task->getParameter('build');
                $buildNameDelimiter = $this->getWorkbench()->model()->getObject('axenox.Deployer.build')->getAttribute('name')->getValueListDelimiter();
                $buildName = array_unique(explode($buildNameDelimiter, $buildName))[0];
                
                $ds->getFilters()->addConditionFromString('name', $buildName, ComparatorDataType::EQUALS);
            } else {
                $inputData = $this->getInputDataSheet($task);
                if ($col = $inputData->getColumns()->get('build')) {
                    $buildUid = $col->getValue(0);
                    $ds->getFilters()->addConditionFromString('uid', $buildUid, ComparatorDataType::EQUALS);
                }
            }
            
            if (! $buildUid && $buildName === null) {
                throw new ActionInputMissingError($this, 'Cannot deploy build: missing build reference!', '7880ZT2');
            }
            
            $ds->dataRead();
            if ($ds->isEmpty()) {
                throw new ActionInputError($this, "Build with name/UID '" . ($buildName ?? $buildUid) . "' not found!", '7880ZT2');
            }
            $this->buildData = $ds;
        }
        return $this->buildData->getCellValue($projectAttributeAlias, 0);
    }
 
    /**
     *
     * @param TaskInterface $task
     * @param int $hostIndex
     * Exports a host's data connection configuration for the updater push executor.
     *
     * @param TaskInterface $task
     * @param int $hostIndex
     * @return array<string, mixed>
     */
    protected function getHostConnectionConfig(TaskInterface $task, int $hostIndex): array
    {
        $connectionUid = $this->getHostData($task, 'data_connection', $hostIndex);
        if (! $connectionUid) {
            throw new ActionRuntimeError($this, 'Updater push requires a configured HTTP data connection.');
        }
        $connection = DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionUid);
        $configuration = $connection->exportUxonObject()->toArray();
        if (! is_array($configuration)) {
            throw new ActionRuntimeError($this, 'Cannot read the configured updater data connection.');
        }
        return $configuration;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments() : array
    {
        return [
            (new ServiceParameter($this))
            ->setName('build')
            ->setDescription('Name of the build to deploy')
            ->setRequired(true),
            (new ServiceParameter($this))
            ->setName('host')
            ->setDescription('Identifier of the host to deploy on')
            ->setRequired(true)
        ];
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() : array
    {
        return [];
    }

    public static function getHostAlias(string $hostName) : string
    {
        $hostAlias = str_replace(' ', '_', $hostName);
        $hostAlias = preg_replace('/[^a-z0-9_]/i', '', $hostAlias);
        return $hostAlias;
    }
    
    /**
     * Returns the historical recipe reference saved on the deployment record.
     * 
     * @param TaskInterface $task
     * @return string|null
     */
    protected function getLegacyDeployRecipeReference(TaskInterface $task) : ?string
    {
        $recipe = $this->getProjectData($task, 'deployment_recipe');
        
        if (in_array($recipe, ['CustomDeploy', BuildRecipeDataType::CUSTOM_BUILD], true)) {
            return $this->projectData->getCellValue('deployment_recipe_custom_path', 0);
        }
        $recipesPath = Filemanager::FOLDER_NAME_VENDOR . DIRECTORY_SEPARATOR . $this->getApp()->getDirectory()
            . DIRECTORY_SEPARATOR . 'Recipes' . DIRECTORY_SEPARATOR . 'Deploy' . DIRECTORY_SEPARATOR;
        return $recipesPath . $recipe . '.php';
    }

    /**
     * Validates that all selected deployment hosts belong to the same project as the selected build.
     * 
     * Note: In case of a faulty filtering of the hosts, the deployment will start for ALL hosts,
     * including the hosts from different projects. So this validation is crucial to prevent unwanted deployments to wrong hosts.
     * If the host skip logic is implemented, with faulty filtering,
     * the deployment can still happen to unwanted hosts that belongs to the same project, for example the "PROD" host.
     * So make sure that these cases are caught, bevor reworking this solution.
     *
     * @param TaskInterface $task
     * @throws ActionInputError If one of the selected hosts belongs to another project than the build.
     * @return void
     */
    protected function validateHostsBelongToBuildProject(TaskInterface $task) : void
    {
        $buildProject = $this->getBuildData($task, 'project');
        $hostCount = $this->getHostCount($task);

        for ($hostIndex = 0; $hostIndex < $hostCount; $hostIndex++) {
            $hostProject = $this->getHostData($task, 'project', $hostIndex);

            if ($buildProject !== $hostProject) {
                $hostName = $this->getHostData($task, 'name', $hostIndex);
                throw new ActionInputError(
                    $this,
                    'Cannot deploy build to host "' . $hostName . '": host belongs to a different project.'
                );
            }
        }
    }
}