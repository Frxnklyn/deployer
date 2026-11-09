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
use axenox\Deployer\Pipelines\Deploy\ManualTransfer;
use axenox\Deployer\Factories\HostDeploymentPipelineFactory;
use axenox\Deployer\Common\Deployment\DeploymentRecordWriter;

/**
 * Runs the deployment pipeline selected for each host in the action input.
 *
 * The action resolves and validates build/host records, creates deployment
 * records, supplies run-specific task data and authoritative host settings,
 * then persists yielded progress and pipeline results. Host `deploy_config`
 * is imported as pipeline-specific UXON; host paths, PHP executable,
 * operating system and connection are supplied separately from the host
 * record. A selected prototype is never replaced by legacy recipe execution.
 *
 * @author Andrej Kabachnik
 */
class Deploy extends AbstractActionDeferred implements iCanBeCalledFromCLI, iCreateData
{
    use BuildProjectTrait;
    
    private $projectData = null;

    private $buildData = null;

    private $hostData = null;
    
    /**
     * Configures this action for one or more deployment rows.
     *
     * @return void
     */
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setInputRowsMax(1);
        $this->setInputObjectAlias('axenox.Deployer.deployment');
    }
    
    /**
     * Validates host/project selection and prepares one deployment row per host.
     *
     * If no input sheet is provided, build and host task parameters are used to
     * construct the deployment sheet for deferred execution.
     *
     * @param TaskInterface $task Current deployment task.
     * @param DataTransactionInterface $transaction Transaction supplied by Core.
     * @param ResultMessageStreamInterface $result Result stream supplied by Core.
     * @return array{0: TaskInterface, 1: DataSheetInterface} Task and deployment sheet.
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
     * Creates deployment records and runs each selected host pipeline.
     *
     * Progress is yielded as text; failures are recorded on the corresponding
     * row and do not trigger a legacy recipe fallback.
     *
     * @param TaskInterface|null $task Deferred task prepared by Core.
     * @param DataSheetInterface|null $deployData Deployment rows to create and update.
     * @return \Generator<int, string, mixed, void> Progress messages for each host deployment.
     * @throws InvalidArgumentException If deferred task or deployment data is missing.
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
     * Runs one selected host pipeline and persists its streamed output and result.
     *
     * The host configuration is passed as pipeline-specific UXON while the
     * factory service injects authoritative host-owned values separately.
     *
     * @param TaskInterface $task Current deployment task.
     * @param DataSheetInterface $deployData Deployment records being updated.
     * @param int $hostIndex Zero-based index of the selected host row.
     * @param string $prototype Selected deployment pipeline prototype.
     * @return \Generator<int, string, mixed, DeploymentResult> Progress messages and the pipeline result.
     * @throws \InvalidArgumentException If the selected pipeline rejects the release inputs.
     * @throws \RuntimeException If yielded output or the returned outcome violates the pipeline contract.
     */
    protected function runSelectedPipeline(TaskInterface $task, DataSheetInterface $deployData, int $hostIndex, string $prototype): \Generator
    {
        $configuration = UxonObject::fromJson($this->getHostData($task, 'deploy_config', $hostIndex) ?: '{}');
        $pipeline = HostDeploymentPipelineFactory::create(
            $this->getWorkbench(), $prototype, $configuration,
            fn (string $attribute) => $this->getHostData($task, $attribute, $hostIndex),
            $this->getHostAttributeAliases()
        );
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
            $values = ['status' => $outcome->getStatus(), 'log' => $log];
            if ($outcome->getStatus() >= 90) {
                $values['completed_on'] = DateTimeDataType::now();
            }
            DeploymentRecordWriter::update($deployData, $values, $hostIndex);
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
     * @return string[] Normalized aliases with underscores removed.
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
     * Replaces quoted project-local file paths with file-server download URLs.
     * 
     * @param string $msg Message that may contain quoted local file paths.
     * @return string Message with matching file paths replaced by URLs.
     */
    protected function replaceFilePathsWithHyperlinks(string $msg) : string
    {
        $urlMatches = [];
        if (preg_match_all('/"(' . preg_quote($this->getBasePath(), '/') . '[^"]*)"/', $msg, $urlMatches) > 0) {
            foreach (array_unique($urlMatches[1]) as $urlPath) {
                $url = HttpFileServerFacade::buildUrlToDownloadFile($this->getWorkbench(), $urlPath, false);
                $msg = str_replace($urlPath, $url, $msg);
            }
        }
        return $msg;
    }
    /**
     * Normalizes line breaks in a CLI progress message for the action stream.
     *
     * @param string $msg Raw pipeline message.
     * @return string Message with carriage returns and line feeds normalized.
     */
    protected function escapeCliMessage(string $msg) : string
    {
        // TODO handle strange empty spaces in composer output
        return str_replace(["\r", "\n"], PHP_EOL, $msg);
    }
     
    /**
     * Returns a project attribute selected by the build's project reference.
     *
     * @param TaskInterface $task Current deployment task.
     * @param string $projectAttributeAlias Project model attribute alias to read.
     * @return string Attribute value, or an empty string when the stored value is null.
     * @throws ActionInputMissingError If the build has no project reference.
     * @throws ActionInputError If the referenced project cannot be found.
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
     * Returns a host attribute for the selected host row.
     *
     * Hosts are selected by task parameter or deployment input sheet and cached
     * for the duration of this action instance.
     *
     * @param TaskInterface $task Current deployment task.
     * @param string $option Host model attribute alias to read.
     * @param int $index Zero-based host row index.
     * @return string|null Attribute value, or null if the stored value is null.
     * @throws ActionInputMissingError If no host reference is supplied.
     * @throws ActionInputError If no matching host can be found.
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
     * @param array<string, mixed> $deployConfig Deployment configuration to update.
     * @param string|null $projectLicensesJson Optional project license configuration JSON.
     * @return array<string, mixed> Configuration with the project license defaults merged into app config.
     */
    protected function mergeProjectLicensesConfig(array $deployConfig, ?string $projectLicensesJson) : array
    {
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
     * Returns the number of hosts selected for this action.
     *
     * @param TaskInterface $task Current deployment task.
     * @return int Number of selected host rows.
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
     * Returns a build attribute selected by task parameter or deployment input row.
     *
     * @param TaskInterface $task Current deployment task.
     * @param string $projectAttributeAlias Build model attribute alias to read.
     * @return string|null Attribute value from the selected build.
     * @throws ActionInputMissingError If no build reference is supplied.
     * @throws ActionInputError If the referenced build cannot be found.
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
     * Describes required build and host CLI arguments.
     *
     * @return ServiceParameter[] Required positional arguments for the deployment action.
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
     * Returns the optional named CLI arguments supported by this action.
     *
     * @return ServiceParameter[] Empty because Deploy has no optional CLI options.
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
     * Ensures every selected host belongs to the project containing the build.
     *
     * @param TaskInterface $task Current deployment task.
     * @return void
     * @throws ActionInputError If any selected host belongs to another project than the build.
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