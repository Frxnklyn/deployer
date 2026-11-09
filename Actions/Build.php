<?php
namespace axenox\Deployer\Actions;

use axenox\Deployer\DataTypes\BuildablePhpVersionDataType;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\HexadecimalNumberDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\iCreateData;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\CommonLogic\Filemanager;
use axenox\Deployer\Actions\Traits\BuildProjectTrait;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\Actions\ActionInputError;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\TaskFactory;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use exface\Core\DataTypes\JsonDataType;
use axenox\Deployer\Common\Pipelines\BuildTask;
use axenox\Deployer\Common\Pipelines\ComposerBuildBom;
use axenox\Deployer\Common\Pipelines\BuildResult;
use axenox\Deployer\Factories\PipelineFactory;
use axenox\Deployer\Interfaces\Pipelines\BuildPipelineInterface;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;

/**
 * Creates a build by running the selected build-variant pipeline.
 *
 * The action resolves build/project inputs, supplies run-specific paths and
 * credentials, persists pipeline output and audits a successful build. The
 * build variant's `prototype_file` selects the app-owned pipeline; its
 * `config_uxon` is passed as pipeline-specific configuration, not as a source
 * of project or host runtime attributes. A per-run `composer_json` parameter,
 * when supplied, replaces the variant's Composer manifest for that run.
 *
 * @author Andrej Kabachnik
 */
class Build extends AbstractActionDeferred implements iCanBeCalledFromCLI, iCreateData
{
    use BuildProjectTrait;
    
    private $projectData = null;
    private $buildVariantData = null;

    /**
     * Configures this action for exactly one build input row.
     *
     * @return void
     */
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setInputRowsMax(1);
        $this->setInputObjectAlias('axenox.Deployer.build');
    }
    
    /**
     * Resolves the build row from input data or constructs it from task parameters.
     *
     * @param TaskInterface $task Current action task.
     * @param DataTransactionInterface $transaction Transaction supplied by Core.
     * @param ResultMessageStreamInterface $result Result stream supplied by Core.
     * @return array{0: TaskInterface, 1: DataSheetInterface} Task and one-row build sheet for deferred execution.
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        // $buildData based on object axenox.Deployer.build
        try {
            $buildData = $this->getInputDataSheet($task);
        } catch (ActionInputMissingError $e) {
            $buildData = DataSheetFactory::createFromObject($this->getInputObjectExpected());
            $buildData->addRow([
                'version' => $this->getVersion($task),
                'project' => $this->getProjectData($task, 'uid'),
                'comment' => $this->getComment($task),
                'notes' => $this->getNotes($task),
                'php_version' => $this->getBuildData($task, 'php_version', 'php', BuildablePhpVersionDataType::getRuntimeVersion()),
                'build_variant' => $this->getBuildVariantData($task, 'uid'),
                'composer_json' => $this->getBuildVariantData($task, 'prototype_file') ? null : $this->getComposerJson($task),
                'composer_auth_json' => $this->getBuildVariantData($task, 'prototype_file') ? null : $this->getBuildVariantData($task, 'composer_auth_json')
            ]);
        }
        
        return [$task, $buildData];
    }
    
    /**
     * Runs the selected build pipeline, records its progress and audits success.
     *
     * Progress text is yielded to the caller; the generator's return value is
     * the generated build name. Pipeline errors mark the build failed without
     * allowing a legacy recipe fallback.
     *
     * @param TaskInterface|null $task Deferred task prepared by Core.
     * @param DataSheetInterface|null $buildData One-row build record to create and update.
     * @return \Generator<int, string, mixed, string> Progress messages and the resulting build name.
     * @throws InvalidArgumentException If deferred task or build data is missing.
     */
    protected function performDeferred(TaskInterface $task = null, DataSheetInterface $buildData = null) : \Generator
    {
        if ($task === null) {
            throw new InvalidArgumentException('Missing argument $task in deferred action call!');
        }
        
        if ($buildData === null) {
            throw new InvalidArgumentException('Missing argument $buildData in deferred action call!');
        }
        
        $buildName = $this->generateBuildName($task);
        
        $log = '';
        
        $msg = 'Building ' . $buildName . '...' . PHP_EOL;
        yield $msg;
        $log .= $msg;
        
        // Create build entry and mark it as "in progress"
        $buildData->setCellValue('status', 0, 50);
        $buildData->setCellValue('name', 0, $buildName);
        // Do not use the transaction to force force creating a separate one for this operation.
        $buildData->dataCreate(false);
        
        $prototype = null;
        try {
            $prototype = $this->getBuildVariantData($task, 'prototype_file');
            if ($prototype === null || trim($prototype) === '') {
                throw new \RuntimeException('No build pipeline selected. Run the Deployer pipeline migration and configure the build variant; legacy recipes are no longer executed.');
            }
            $generator = $this->runSelectedPipeline($task, $buildData, $buildName, $prototype);
            foreach ($generator as $msg) {
                yield $msg;
                $log .= $msg;
                $buildData->setCellValue('log', 0, $log);
                $buildData->dataUpdate(false);
            }
            $buildData->setCellValue('log', 0, $log);
            $buildData->dataUpdate(false);
        } catch (\Throwable $e) {
            if ($prototype !== null && $prototype !== '') {
                // UXON/CLI exceptions can retain authentication in their debug context.
                $message = 'Selected build pipeline failed (' . get_class($e) . ').';
                if (get_class($e) === \RuntimeException::class) {
                    $message .= ' ' . $e->getMessage();
                }
                $e = new \RuntimeException($message);
            }
            $msg = PHP_EOL . '✘ ERROR: ' . $e->getMessage();
            $log .= $msg;
            $buildData->setCellValue('log', 0, $log);
            $buildData->setCellValue('status', 0, 90); // failed
            $this->getWorkbench()->getLogger()->logException($e);
            $buildData->dataUpdate(false);
            yield $msg;
        }

        if ((int) $buildData->getCellValue('status', 0) === 99) {
            $msg = PHP_EOL . 'Auditing ' . $buildName . '...' . PHP_EOL;
            yield $msg;
            $log .= $msg;

            try {
                /** @var AuditBuild $auditAction */
                $auditAction = ActionFactory::createFromString($this->getWorkbench(), AuditBuild::class);
                $auditAction->setScanners($this->getApp()->getConfig()->getOption('BUILD.AUDIT.SCANNERS'));
                $auditTask = TaskFactory::createFromDataSheet($buildData->copy());
                $auditTask->setActionSelector($auditAction->getAliasWithNamespace());
                $auditResult = $auditAction->handle($auditTask);
                $msg = $auditResult->getMessage() . PHP_EOL;
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
                $msg = 'ERROR auditing ' . $buildName . ': ' . $e->getMessage() . PHP_EOL;
            }

            yield $msg;
            $log .= $msg;
            $buildData->setCellValue('log', 0, $log);
            $buildData->dataUpdate(false);
        }

        return $buildName;
    }

    /**
     * Resolves the selected prototype, supplies action-owned runtime inputs and persists evidence.
     *
     * Selected pipelines never enter legacy preparation, even when selection/configuration fails.
     *
     * @param TaskInterface $task Current build task and project inputs.
     * @param DataSheetInterface $buildData Build record populated with pipeline evidence.
     * @param string $buildName Generated build identity.
     * @param string $prototype Selected build pipeline prototype.
     * @return \Generator<int, string, mixed, BuildResult> Pipeline progress and structured build outcome.
     * @throws ActionInputError If the selected prototype is not a build pipeline.
     * @throws ActionRuntimeError If yielded output or the returned outcome violates the pipeline contract.
     */
    protected function runSelectedPipeline(TaskInterface $task, DataSheetInterface $buildData, string $buildName, string $prototype): \Generator
    {
        $configuration = $this->getBuildVariantData($task, 'config_uxon');
        $pipeline = PipelineFactory::createPipelineFromUxon(
            $this->getWorkbench(),
            $prototype,
            UxonObject::fromJson($configuration === null || $configuration === '' ? '{}' : $configuration)
        );
        if (! $pipeline instanceof BuildPipelineInterface) {
            throw new ActionInputError($this, 'Selected prototype is not a build pipeline.');
        }
        $projectPath = $this->getBasePath() . $this->getProjectFolderRelativePath($task);
        $archivePath = $projectPath . DIRECTORY_SEPARATOR . $this->getFolderNameForBuilds();
        Filemanager::pathConstruct($archivePath);
        $phpVersion = $this->getBuildData($task, 'php_version', 'php', BuildablePhpVersionDataType::getRuntimeVersion());
        $nativeTask = new BuildTask(
            $projectPath,
            $archivePath,
            $buildName,
            $phpVersion === BuildablePhpVersionDataType::getRuntimeVersion()
                ? CliCommandRunner::findPhpExecutable() : BuildablePhpVersionDataType::getExecutable($phpVersion, $this->getWorkbench()),
            $this->getBasePath() . 'composer.phar',
            $task->hasParameter('composer_json') ? $this->getComposerOverride($task) : null,
            $this->getApp()->getConfig()->getOption('COMPOSER.AUTH')->toJson(),
            $this->getProjectData($task, 'licenses_config'),
            $this->getComment($task),
            $this->getNotes($task)
        );
        if ($pipeline instanceof \axenox\Deployer\Pipelines\Build\ComposerInstall
            && $pipeline->exportUxonObject()->getProperty('asset_packagist_cache_fix') === true) {
            $nativeTask->previousComposerLock = $this->getPreviousSuccessfulComposerLock($task);
        }
        $generator = $pipeline->run($nativeTask);
        foreach ($generator as $text) {
            if (! is_string($text)) {
                throw new ActionRuntimeError($this, 'Build pipelines must yield text only.');
            }
            yield $text;
        }
        $outcome = $generator->getReturn();
        if (! $outcome instanceof BuildResult) {
            throw new ActionRuntimeError($this, 'Build pipeline did not return a BuildResult.');
        }
        $buildData->setCellValue('status', 0, $outcome->getStatus());
        $buildData->setCellValue('composer_json', 0, $outcome->getComposerJson());
        $buildData->setCellValue('composer_lock', 0, $outcome->getComposerLock());
        $buildData->setCellValue('package_bom', 0, $outcome->getPackageBom());
        $buildData->setCellValue('comment', 0, $nativeTask->getComment());
        $buildData->setCellValue('notes', 0, $nativeTask->getNotes());
        return $outcome;
    }

    /**
     * Compatibility entrypoint for dependency capture, independent of recipe execution.
     *
     * Delegates to the native ComposerBuildBom and the installed PackageManager
     * BOM API: locked production/dev identities, an existing vendor/licenses.json, bundled
     * includes.json files, config/Licenses.config.json and optional project configuration.
     * Configuration may select licenses and terms but cannot change locked package versions.
     * Local license discovery always runs; no remote queries or installation Workbench are required.
     *
     * @param string $projectPath Installed project directory whose dependencies are captured.
     * @param string|null $projectLicensesJson Project license configuration captured at build start.
     * @return LicenseBOM PackageManager license BOM built from the installed package inventory.
     */
    public static function buildRecipeBom(string $projectPath, ?string $projectLicensesJson = null) : LicenseBOM
    {
        return ComposerBuildBom::captureInstallation($projectPath, $projectLicensesJson);
    }

    /**
     * Reduces the staged license inventory to the build's database representation.
     * 
     * The caller supplies the original JSON before cleaning up its build workspace.
     * Invalid inventories fail rather than recording an apparently clean empty build.
     * Retains URN identities, bundle ownership and compact license evidence without inline terms.
     * The staged source is left unchanged.
     * 
     * @param string $filePath Path to the staged package BOM JSON file.
     * @return string Validated compact BOM snapshot for persistence.
     * @throws ActionRuntimeError If the source file cannot be read.
     * @throws InvalidArgumentException If the decoded inventory has no valid package list.
     */
    protected function buildPackageBom(string $filePath) : string
    {
        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new ActionRuntimeError($this, 'Cannot read the build package BOM: ' . $filePath);
        }
        $data = JsonDataType::decodeJson($json);
        if (! is_array($data['packages'] ?? null)) {
            throw new InvalidArgumentException('Build package BOM must contain a packages array.');
        }
        foreach ($data['packages'] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || $package['name'] === '') {
                throw new InvalidArgumentException('Build package BOM packages must have a nonempty name.');
            }
        }
        $bom = ComposerBuildBom::fromInventory($data);
        return ComposerBuildBom::buildSnapshot($bom);
    }
    
    /**
     * Returns a project attribute selected by task parameter or input build row.
     *
     * @param TaskInterface $task Current build task.
     * @param string $projectAttributeAlias Project model attribute alias to read.
     * @return string Attribute value, or an empty string when the model value is null.
     * @throws ActionInputMissingError If no project reference is supplied.
     * @throws ActionInputError If the referenced project cannot be found.
     */
    protected function getProjectData(TaskInterface $task, string $projectAttributeAlias): string
    {
        if ($this->projectData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.project');
            $ds->getColumns()->addMultiple([
                'alias',
                'build_recipe',
                'build_recipe_custom_path',
                'licenses_config'
            ]);

            if ($task->hasParameter('project')) {
                $projectAlias = $task->getParameter('project');
                $ds->getFilters()->addConditionFromString('alias', $projectAlias, ComparatorDataType::EQUALS);
            } else {
                $inputData = $this->getInputDataSheet($task);
                if ($col = $inputData->getColumns()->get('project')) {
                    $projectUid = $col->getValue(0);
                    $ds->getFilters()->addConditionFromString('uid', $projectUid, ComparatorDataType::EQUALS);
                }
            }
            
            if (! $projectUid && $projectAlias === null) {
                throw new ActionInputMissingError($this, 'Cannot create build: missing project reference!', '784EI40');
            }
            $ds->dataRead();
            if ($ds->isEmpty()) {
                throw new ActionInputError($this, "Project with alias/UID '" . ($projectAlias ?? $projectUid) . "' not found!", '784EI40');
            }
            $this->projectData = $ds;
        }
        return $this->projectData->getCellValue($projectAttributeAlias, 0) ?? '';
    }
    
    /**
     * Returns a build-variant attribute selected by name or UID and scoped to the project.
     *
     * @param TaskInterface $task Current build task.
     * @param string $buildVariantAttributeAlias Build-variant attribute alias to read.
     * @return mixed Attribute value from the selected build variant.
     * @throws ActionInputMissingError If no build variant is supplied.
     * @throws ActionInputError If the selected variant does not belong to the project or does not exist.
     */
    protected function getBuildVariantData(TaskInterface $task, string $buildVariantAttributeAlias)
    {
        if ($this->buildVariantData === null) {
            if ($task->hasParameter('variant')) {
                $buildVariant = $task->getParameter('variant');
            } else {
                $inputData = $this->getInputDataSheet($task);
                if ($col = $inputData->getColumns()->get('build_variant')) {
                    $buildVariant = $col->getCellValue(0);
                }
            }
            
            if ($buildVariant === null) {
                throw new ActionInputMissingError($this, 'Cannot create build: No Build Variant provided!', '7FGBG9F');
            } elseif ($buildVariant === '') {
                throw new ActionInputMissingError($this, 'Cannot create build: Invalid/empty Build Variant provided!', '7FGBG9F');
            }
            
            $projectUid = $this->getProjectData($task, 'uid');
            $projectAlias = $this->getProjectData($task, 'alias');
            
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.build_variant');
            $ds->getColumns()->addMultiple([
                'uid',
                'project',
                'name',
                'composer_json',
                'composer_auth_json',
                'prototype_file',
                'config_uxon'
            ]);
            $ds->getFilters()->addConditionFromString('project', $projectUid, ComparatorDataType::EQUALS);
            if (HexadecimalNumberDataType::isHexNumber($buildVariant)) {
                $ds->getFilters()->addConditionFromString('uid', $buildVariant, ComparatorDataType::EQUALS);
            } else {
                $ds->getFilters()->addConditionFromString('name', $buildVariant, ComparatorDataType::EQUALS);
            }
            $ds->dataRead();
            if ($ds->isEmpty()) {
                throw new ActionInputError($this, "There is no build variant with alias or UID '{$buildVariant}' associated with the project '{$projectAlias}'", '7FGBUMO');
            }
            $this->buildVariantData = $ds;
        }
        return $this->buildVariantData->getCellValue($buildVariantAttributeAlias, 0);
    }
    
    /**
     * Generates the persisted build name from its version and current timestamp.
     *
     * @param TaskInterface $task Current build task containing the required version.
     * @return string Version followed by a `+` and a `YmdHis` timestamp.
     */
    protected function generateBuildName(TaskInterface $task) : string
    {
        $timestamp = date('YmdHis');
        
        return $this->getVersion($task) . '+' . $timestamp;

    } 
    
    /**
     * Reads the required build version from the task or input row.
     *
     * @param TaskInterface $task Current build task.
     * @return string Non-empty build version string.
     * @throws ActionInputMissingError If the version is missing or empty.
     */
    protected function getVersion(TaskInterface $task) : string 
    {
        $version = $this->getBuildData($task, 'version', 'version');
        
        if ($version === null) {
            throw new ActionInputMissingError($this, 'Cannot create build: No version number provided!', '784EENG');
        } else if ($version === '') {
            throw new ActionInputMissingError($this, 'Cannot create build: Invalid/empty version number provided!', '784EENG');
        }
        
        return $version;        
    }
  
    /**
     * Reads the optional build comment, defaulting to an empty string.
     *
     * @param TaskInterface $task Current build task.
     * @return string Comment text.
     */
    protected function getComment(TaskInterface $task) : string
    {
        return $this->getBuildData($task, 'comment', 'comment', '');
    }

    /**
     * Reads an input value, preferring the named task parameter over its input column.
     *
     * @param TaskInterface $task Current build task.
     * @param string $buildAttribute Attribute alias in the build input sheet.
     * @param string $taskParam Task parameter name to check first.
     * @param mixed $default Value returned when no parameter or column value exists.
     * @return mixed|null Resolved value or the supplied default.
     */
    protected function getBuildData(TaskInterface $task, string $buildAttribute, string $taskParam, $default = null)
    {
        $val = null;
        if ($task->hasParameter($taskParam)) {
            $val = $task->getParameter($taskParam);
        } else {
            try {
                $inputData = $this->getInputDataSheet($task);
                if ($col = $inputData->getColumns()->get($buildAttribute)) {
                    $val = $col->getCellValue(0);
                }
            } catch (ActionInputMissingError $e) {
                $val = $default;
            }
        }
        return $val ?? $default;
    }
    
    /**
     * Reads the optional build notes, defaulting to an empty string.
     *
     * @param TaskInterface $task Current build task.
     * @return string Notes text.
     */
    protected function getNotes(TaskInterface $task) : string
    {
        return $this->getBuildData($task, 'notes', 'notes', '');
    }
    
    /**
     * Validates a complete per-run replacement without reading or mutating variant settings.
     *
     * @param TaskInterface $task Current build task with a `composer_json` parameter.
     * @return string Validated JSON object supplied for this run.
     * @throws ActionInputError If the override is not a valid JSON object string.
     */
    protected function getComposerOverride(TaskInterface $task): string
    {
        $composerJson = $task->getParameter('composer_json');
        if (! is_string($composerJson)) {
            throw new ActionInputError($this, 'The composer_json override must be a JSON object encoded as a string.');
        }
        try {
            $document = json_decode($composerJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ActionInputError($this, 'The composer_json override must contain valid JSON.');
        }
        if (! $document instanceof \stdClass) {
            throw new ActionInputError($this, 'The composer_json override must contain a JSON object.');
        }
        return $composerJson;
    }

    /**
     * Resolves history by variant and successful status, never by archive-directory order.
     *
     * @param TaskInterface $task Current build task identifying the selected variant.
     * @return string|null Composer lock from the newest successful build of that variant, if one exists.
     */
    protected function getPreviousSuccessfulComposerLock(TaskInterface $task): ?string
    {
        $lockData = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.build');
        $lockData->getFilters()->addConditionFromString('build_variant', $this->getBuildVariantData($task, 'uid'), ComparatorDataType::EQUALS);
        $lockData->getFilters()->addConditionFromString('status', 99, ComparatorDataType::EQUALS);
        $lockData->getSorters()->addFromString('created_on', SortingDirectionsDataType::DESC);
        $lockCol = $lockData->getColumns()->addFromExpression('composer_lock');
        $lockData->dataRead(1);
        return $lockData->isEmpty() ? null : $lockCol->getValue(0);
    }
    
    /**
     * Describes required project, version and build-variant CLI arguments.
     *
     * @return ServiceParameter[] Required positional arguments for the build action.
     */
    public function getCliArguments(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName('project')
                ->setDescription('Alias of the project to build')
                ->setRequired(true),
            (new ServiceParameter($this))
                ->setName('version')
                ->setDescription('Version number - e.g. 1.0.12 or 2.0-beta. Use sematic versioning!')
                ->setRequired(true),
            (new ServiceParameter($this))
                ->setName('variant')
                ->setDescription('Build variant name or UID')
                ->setRequired(true)
        ];
    }

    /**
     * Describes optional build comment, notes and PHP-version CLI options.
     *
     * @return ServiceParameter[] Optional named arguments for the build action.
     */
    public function getCliOptions(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName('comment')
                ->setDescription('Comment to give a short description about the build.'),
            (new ServiceParameter($this))
                ->setName('notes')
                ->setDescription('You can save a note to the build to give further information.'),
            (new ServiceParameter($this))
                ->setName('php')
                ->setDescription('Custom PHP version to be used (must be registered in axenox.Deployer.config.json!)')
        ];
    }
    
}