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
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\iCreateData;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\DataConnectionFactory;
use axenox\Deployer\Actions\Traits\BuildProjectTrait;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Facades\HttpFileServerFacade;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use axenox\Deployer\Common\Pipelines\DeploymentResult;
use axenox\Deployer\Common\Pipelines\DeploymentTask;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Common\Pipelines\PreparedDeploymentResult;
use axenox\Deployer\Common\Pipelines\NativeDeploymentPackage;
use axenox\Deployer\Common\Pipelines\DeploymentLicenseConfig;
use axenox\Deployer\Factories\PipelineFactory;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use axenox\Deployer\Pipelines\Deploy\ManualTransfer;

/**
 * Deploys a build to a specific host.
 * 
 * This action requires an instance of a build and an instance of a host as parameters.
 * The action may either be called via the PowerUI frontend or via CLI-command.
 * The parameters for the CLI-call are the names of the host and build, which have to be existing instances of
 * `axenox.Deployer.host` and `axenox.Deployer.build` objects.
 * This action will then extract the data, required for the deployment, from the given objects, create a working directory
 * and call the actual command for the deployment process. 
 * Upon finishing the deployment process, the action will delete every temporary file / directories created.
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

        $prototypes = [];
        for($hostIndex = 0; $hostIndex < $hostCount; $hostIndex++) {
            $prototypes[$hostIndex] = trim($this->getHostData($task, 'prototype_file', $hostIndex) ?? '');
            $deployData->setCellValue('status', $hostIndex, 50);
            $deployData->setCellValue('host', $hostIndex, $this->getHostData($task, 'uid', $hostIndex));
            $deployData->setCellValue('build', $hostIndex, $this->getBuildData($task, 'uid'));
            $deployData->setCellValue('started_on', $hostIndex, date(DateTimeDataType::DATETIME_FORMAT_INTERNAL));
            $deployData->setCellValue('deploy_recipe_file', $hostIndex, $prototypes[$hostIndex]);
        }
        
        // Do not use the transaction to force force creating a separate one for this operation.
        $deployData->dataCreate(false);
        
        for($hostIndex = 0; $hostIndex < $hostCount; $hostIndex++) {
            try {
                // Generated by GitHub Copilot - Nov-05-2026
                if ($prototypes[$hostIndex] === '') {
                    $message = 'No deployment pipeline selected. Run the Deployer pipeline migration and configure the host; legacy recipes are no longer executed.';
                    throw new \RuntimeException($message);
                }
                yield from $this->runSelectedPipeline($task, $deployData, $hostIndex, $prototypes[$hostIndex]);
            } catch (\Throwable $error) {
                // UXON/command exceptions can carry host secrets. Never retain their payload.
                $message = $prototypes[$hostIndex] === ''
                    ? $message
                    : 'Selected deployment pipeline failed (' . get_class($error) . '); no legacy fallback.';
                // Generated by GitHub Copilot - Nov-05-2026
                if ($error instanceof \InvalidArgumentException && in_array($error->getMessage(), [
                    'Host installation root does not match the selected operating system.',
                    'Host installation root must be an absolute non-traversing path.'
                ], true)) {
                    $message .= ' Invalid host installation root (path_abs_to_api). For Windows use an absolute drive path such as C:\\Apps\\MyApplication; for Linux/Unix use an absolute filesystem path. URL paths are not installation roots. Confirm the native target layout before retrying.';
                }
                $deployData->setCellValue('status', $hostIndex, 90);
                $deployData->setCellValue('completed_on', $hostIndex, DateTimeDataType::now());
                $deployData->setCellValue('log', $hostIndex, $message);
                $this->getWorkbench()->getLogger()->logException(new \RuntimeException($message));
                $deployData->dataUpdate(false);
                yield $message;
            }
        }
    }

    /**
     * Runs configured hosts without recipe generation or pipeline model access.
     *
     * @param TaskInterface $task
     * @param DataSheetInterface $deployData
     * @param int $hostIndex
     * @param string $prototype
     * @return \Generator
     */
    protected function runSelectedPipeline(TaskInterface $task, DataSheetInterface $deployData, int $hostIndex, string $prototype): \Generator
    {
        $configuration = UxonObject::fromJson($this->getHostData($task, 'deploy_config', $hostIndex) ?: '{}');
        $hostAliases = $this->getHostAttributeAliases();
        foreach (array_keys($configuration->toArray()) as $key) {
            $normalized = strtolower(str_replace('_', '', $key));
            if (str_starts_with($normalized, 'host') || in_array($normalized, $hostAliases, true)
                || in_array($normalized, ['connection', 'relativedeploypath'], true)) {
                throw new \InvalidArgumentException('Host attributes cannot be configured through pipeline UXON.');
            }
        }
        $pipeline = PipelineFactory::createPipelineFromUxon($this->getWorkbench(), $prototype, $configuration);
        if (! $pipeline instanceof DeploymentPipelineInterface) {
            throw new \InvalidArgumentException('Selected prototype is not a deployment pipeline.');
        }
        // Generated by GitHub Copilot - Nov-05-2026
        if (is_callable([$pipeline, 'setConnection'])) {
            $connectionUid = $this->getHostData($task, 'data_connection', $hostIndex);
            if (! $connectionUid) {
                throw new \InvalidArgumentException('Selected deployment pipeline requires a host data connection.');
            }
            $pipeline->setConnection(DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionUid));
        }
        foreach ([
            'setHostPathToInstallationRoot' => 'path_abs_to_api',
            'setHostPhpExecutable' => 'php_cli',
            'setHostOperatingSystem' => 'operating_system'
        ] as $setter => $attribute) {
            if (is_callable([$pipeline, $setter])) {
                $value = $this->getHostData($task, $attribute, $hostIndex);
                $pipeline->$setter($attribute === 'php_cli' ? ($value ?: 'php') : ($value ?? ''));
            }
        }
        $nativeTask = new DeploymentTask(
            (string) $deployData->getCellValue('uid', $hostIndex),
            (string) $this->getHostData($task, 'uid', $hostIndex)
        );
        if ($pipeline instanceof ManualTransfer) {
            if ((int) $this->getBuildData($task, 'status') !== 99) {
                throw new \InvalidArgumentException('Manual transfer requires a successful build.');
            }
            $settings = $pipeline->exportUxonObject()->toArray();
            $pipeline->setConfig(new UxonObject(DeploymentLicenseConfig::merge(
                $settings['config'],
                $this->getProjectData($task, 'licenses_config')
            )));
            $nativeTask = new ReleaseDeploymentTask(
                $nativeTask->deploymentId,
                $nativeTask->hostId,
                (string) $this->getBuildData($task, 'uid'),
                (string) $this->getBuildData($task, 'name'),
                NativeDeploymentPackage::projectPath($this->getBasePath(), $this->getProjectFolderRelativePath($task)),
                // Generated by GitHub Copilot - Nov-05-2026
                self::getHostAlias((string) $this->getHostData($task, 'name', $hostIndex))
            );
        }
        $log = '';
        $outcome = null;
        try {
            $generator = $pipeline->run($nativeTask);
            foreach ($generator as $message) {
                if (! is_string($message)) {
                    throw new \RuntimeException('Deployment pipelines must yield text only.');
                }
                $log .= $message;
                $deployData->setCellValue('log', $hostIndex, $log);
                $deployData->dataUpdate(false);
                yield $this->escapeCliMessage($message);
            }
            $outcome = $generator->getReturn();
            if (! $outcome instanceof DeploymentResult) {
                throw new \RuntimeException('Deployment pipeline did not return a DeploymentResult.');
            }
            if ($outcome instanceof PreparedDeploymentResult) {
                // No file-facade/public link: packages can contain secrets.
                $log .= $outcome->instructions . "\nPrivate package: " . $outcome->artifactPath
                    . "\nSHA-256: " . $outcome->sha256 . "\n";
            }
            $deployData->setCellValue('status', $hostIndex, $outcome->getStatus());
            if ($outcome->getStatus() >= 90) {
                $deployData->setCellValue('completed_on', $hostIndex, DateTimeDataType::now());
            }
            $deployData->setCellValue('log', $hostIndex, $log);
            $deployData->dataUpdate(false);
        } catch (\Throwable $error) {
            if ($outcome instanceof PreparedDeploymentResult) {
                $outcome->discard();
            }
            throw $error;
        }
        if ($outcome instanceof PreparedDeploymentResult) {
            yield $this->escapeCliMessage($outcome->instructions . "\nPrivate package: " . $outcome->artifactPath
                . "\nSHA-256: " . $outcome->sha256 . "\n");
        }
        return $outcome;
    }

    /**
     * Normalizes all actual host attribute aliases, not just this prototype's inputs.
     *
     * @return string[]
     */
    protected function getHostAttributeAliases(): array
    {
        $aliases = [];
        foreach ($this->getWorkbench()->model()->getObject('axenox.Deployer.host')->getAttributes() as $attribute) {
            $aliases[] = strtolower(str_replace('_', '', $attribute->getAlias()));
        }
        return $aliases;
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
        // Generated by GitHub Copilot - Nov-04-2026
        if (preg_match_all('/"(' . preg_quote($this->getBasePath(), '/') . '[^"]*)"/', $msg, $urlMatches) > 0) {
            foreach (array_unique($urlMatches[1]) as $urlPath) {
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
                'project_group',
                'licenses_config'
            ]);
            $ds->getFilters()->addConditionFromString('uid', $projectUid, ComparatorDataType::EQUALS);
            $ds->dataRead();
            $this->projectData = $ds;
        }
        return $this->projectData->getCellValue($projectAttributeAlias, 0) ?? '';
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
                'deploy_config',
                'prototype_file'
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
     * Shares project defaults while matching host overrides by package identity and license name.
     *
     * Numeric array merging could otherwise attach one license's terms to another license.
     *
     * @param array $deployConfig
     * @param string|null $projectLicensesJson
     * @return array
     */
    protected function mergeProjectLicensesConfig(array $deployConfig, ?string $projectLicensesJson) : array
    {
        // Generated by GitHub Copilot - Nov-05-2026
        $configuration = DeploymentLicenseConfig::merge(
            $deployConfig['default_app_config'] ?? [],
            $projectLicensesJson
        );
        if ($configuration !== [] || isset($deployConfig['default_app_config'])) {
            $deployConfig['default_app_config'] = $configuration;
        }
        return $deployConfig;
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

    /**
     * Keeps historical artifact naming readable; never used as native attempt identity.
     *
     * @param string $hostName
     * @return string
     */
    public static function getHostAlias(string $hostName) : string
    {
        $hostAlias = str_replace(' ', '_', $hostName);
        $hostAlias = preg_replace('/[^a-z0-9_]/i', '', $hostAlias);
        return $hostAlias;
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