<?php
namespace axenox\Deployer\Actions;

use axenox\Deployer\DataTypes\BuildablePhpVersionDataType;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\HexadecimalNumberDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use axenox\Deployer\DataTypes\BuildRecipeDataType;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\iCreateData;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\CommonLogic\Filemanager;
use Symfony\Component\Process\Process;
use axenox\Deployer\Actions\Traits\BuildProjectTrait;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\Actions\ActionInputError;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\TaskFactory;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use exface\Core\DataTypes\JsonDataType;

/**
 * Creates a new build for a project from one of the build variants configured for it.
 * 
 * Successful builds are automatically audited by `axenox.Deployer.AuditBuild` after
 * their Composer lock and minimal package BOM are saved. Audit errors are logged
 * without failing the build.
 * 
 * ## Parameters
 * 
 * This action takes the following parameters
 * 
 * - project alias
 * - build number for the new build (see below)
 * - name of the build variant to use
 * - build comment - optional - a short text describing the build
 * - notes - optional - a (longer) text for side-notes
 * 
 * The parameters can either be passed via command line or with input data (as values for the
 * respective meta attributes of the build object).
 * 
 * The action creates a build archive named after a comination of the verison number and current time seperated 
 * by a '+' character: `[version]+yyyymmddhhmmss`, e.g. `1.0-beta+20191108145134`. The achrive-file is saved 
 * within the deployers data folder in `[config:PROJECTS_FOLDER][hostname]/[buildfolder]/[buildname].tar.gz`. 
 * 
 * After completing the building process, you can deploy the build to a host of your choice, using the action 
 * `axenox.Deployer.Deploy` or the corresponding the CLI command `axenox.Deployer:Deploy`. 
 * 
 * ## Build numbers
 * 
 * The build number can be anything you like, but it is recommended to use [semantic versioning](https://semver.org/) 
 * to make build number well readable and ensure proper sorting by build number.
 * 
 * Examples:
 * 
 * - `1.0`
 * - `1.0.01`
 * - `1.1-beta01`
 * 
 * Remember, that a build name derived from the current time will be added automatically!
 * 
 * ## Commandline Usage:
 * 
 * ```
 * vendor\bin\action axenox.Deployer:Build [project alias] [version] [variant name] <--comment some text> <--notes some text>
 * 
 * ```
 * 
 * For example:
 * ```
 * action axenox.Deployer:Build testProject 1.0-beta "test build"
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *        
 */
class Build extends AbstractActionDeferred implements iCanBeCalledFromCLI, iCreateData
{
    use BuildProjectTrait;
    
    private $projectData = null;
    private $buildVariantData = null;

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
        $this->setInputObjectAlias('axenox.Deployer.build');
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
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
                'composer_json' => $this->getComposerJson($task),
                'composer_auth_json' => $this->getBuildVariantData($task, 'composer_auth_json')
            ]);
        }
        
        return [$task, $buildData];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
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
        
        try {
            // Prepare project folder and deployer task file
            $projectFolder = $this->prepareDeployerProjectFolder($task);
            $buildTask = $this->prepareDeployerTask($task, $projectFolder, $buildName);
            
            $composerJson = $this->createComposerJson($task, $projectFolder);
            $buildData->setCellValue('composer_json', 0, $composerJson);
            
            $composerAuthJson = $this->createComposerAuthJson($task, $projectFolder);
            $buildData->setCellValue('composer_auth_json', 0, $composerAuthJson);
            
            $buildData->setCellValue('comment', 0, $this->getComment($task));
            $buildData->setCellValue('notes', 0, $this->getNotes($task));
            
            // run the deployer task via CLI
            if (getcwd() !== $this->getBasePath()) {
                chdir($this->getBasePath());
            }
            $cmd = 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . "dep {$buildTask}";
            
            $seconds = time();
            
            $environmentVars = $this->getCmdEnvironmentVars($projectFolder);

            // Remove any stale build package BOM before starting the build
            $packageBomPath = $this->getBasePath() . $projectFolder . DIRECTORY_SEPARATOR . 'package_bom.json';
            if (is_file($packageBomPath) && ! unlink($packageBomPath)) {
                throw new ActionRuntimeError($this, 'Cannot remove a stale build package BOM: ' . $packageBomPath);
            }
            
            $process = Process::fromShellCommandline($cmd, null, $environmentVars, null, $this->getTimeout());
            $process->start();
            foreach ($process as $msg) {
                // Live output
                yield $msg;
                // Save to log
                $log .= $msg;
                // Save current log to DB
                $buildData->setCellValue('log', 0, $log);
                $buildData->dataUpdate(false);
            }
            
            if ($process->isSuccessful() === false) {
                $buildData->setCellValue('status', 0, 90); // failed
                $msg = '✘ FAILED building ' . $buildName . '.';
            } else {
                $buildData->setCellValue('status', 0, 99); // completed
                $seconds = time() - $seconds;
                $msg = '✔ SUCCEEDED building ' . $buildName . ' in ' . $seconds . ' seconds.';
            }
            $buildData->dataUpdate(false);
            
            $composerLockPath = $this->getBasePath() . $projectFolder . DIRECTORY_SEPARATOR . 'composer.lock';
            if (file_exists($composerLockPath)) {
                $buildData->setCellValue('composer_lock', 0, file_get_contents($composerLockPath));
            }

            // Read the build package BOM if the build was successful
            if ($process->isSuccessful() && is_file($packageBomPath)) {
                $buildData->setCellValue('package_bom', 0, $this->buildPackageBom($packageBomPath));
            }
            
            // Delete temporary files
            $this->cleanupFiles($projectFolder);
            
            // Send success/failure message
            yield $msg;
            $log .= $msg;
            
            $buildData->setCellValue('log', 0, $log);
            
            // Update build entry's state and save log to data source
            $buildData->dataUpdate(false);
        } catch (\Throwable $e) {
            $log .= PHP_EOL . '✘ ERRROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
            $buildData->setCellValue('log', 0, $log);
            $buildData->setCellValue('status', 0, 90); // failed
            $this->getWorkbench()->getLogger()->logException($e);
            $buildData->dataUpdate(false);
        }

        if ((int) $buildData->getCellValue('status', 0) === 99) {
            $msg = PHP_EOL . 'Auditing ' . $buildName . '...' . PHP_EOL;
            yield $msg;
            $log .= $msg;

            try {
                $auditAction = ActionFactory::createFromString($this->getWorkbench(), AuditBuild::class);
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
     * Reduces the staged license inventory to the build's database representation.
     * 
     * Recipes preserve the original JSON before cleaning up their vendor folder.
     * Invalid inventories fail rather than recording an apparently clean empty build.
     * JsonBOM owns the minimal format; the staged source is left unchanged.
     * 
     * @param string $filePath
     * @return string
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
        $bom = new LicenseBOM();
        foreach ($data['packages'] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || $package['name'] === '') {
                throw new InvalidArgumentException('Build package BOM packages must have a nonempty name.');
            }
            $bom->addPackage(new BOMPackage($package));
        }
        return (new JsonBOM($bom))->setFormat('minimal')->buildJson();
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
    protected function getProjectData(TaskInterface $task, string $projectAttributeAlias): string
    {
        if ($this->projectData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.project');
            $ds->getColumns()->addMultiple([
                'alias',
                'build_recipe',
                'build_recipe_custom_path'
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
        return $this->projectData->getCellValue($projectAttributeAlias, 0);
    }
    
    /**
     * This function takes an task, and an attribute as parameter, and returns the data of
     * the tasks build variant data, stored under the attribute passed as a parameter.
     * 
     * @param TaskInterface $task
     * @param string $buildVariantAttributeAlias
     * @throws ActionInputMissingError
     * @return mixed
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
                'composer_auth_json'
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
     * Returns the path to the deployer recipe file relative to the base installation folder.
     * 
     * E.g. for built-in recipes it's "vendor/axenox/deployer/Recipes..."
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function getBuildRecipeFile(TaskInterface $task): string
    {
        $recipe = $this->getProjectData($task, 'build_recipe');
        
        switch ($recipe) {
            case BuildRecipeDataType::CUSTOM_BUILD:
                return $this->getProjectData($task, 'build_recipe_custom_path');
            default:
                if ($recipe === BuildRecipeDataType::COMPOSER_INSTALL_WITH_ASSET_FIX) {
                    $recipe = BuildRecipeDataType::COMPOSER_INSTALL;
                }
                $recipiesBasePath = Filemanager::FOLDER_NAME_VENDOR . DIRECTORY_SEPARATOR . $this->getApp()->getDirectory() . DIRECTORY_SEPARATOR . 'Recipes' . DIRECTORY_SEPARATOR . 'Build' . DIRECTORY_SEPARATOR;
                return $recipiesBasePath . $recipe . '.php';
        }
    }
    
    /**
     * Generates a deployer task file named `build.php` and returns the CLI arguments to run the actual build command.
     * 
     * @param TaskInterface $task
     * @param string $buildFolder
     * @param string $buildName
     * @return string
     */
    protected function prepareDeployerTask(TaskInterface $task, string $buildFolder, string $buildName) : string
    {
        $slash = DIRECTORY_SEPARATOR;
        $builds_archives_path = $slash . $this->getFolderNameForBuilds();

        // Get deployer recipe file path
        $recipePath = $this->getBuildRecipeFile($task);
        $deployerTaskName = basename($recipePath, '.php');
        
        // Get the PHP path from the PHP version
        if ($phpVersion = $this->getBuildData($task, 'php_version', 'php')) {
            $phpPath = BuildablePhpVersionDataType::getExecutable($phpVersion, $this->getWorkbench());
        } else {
            $phpPath = 'php';  
        }
        
        $content = <<<PHP
<?php
namespace Deployer;

require 'vendor/autoload.php';
require 'vendor/deployer/deployer/recipe/common.php';

set('release_name', '{$buildName}');

// === Path definitions ===
set('builds_archives_path', __DIR__ . '{$builds_archives_path}');
set('php_executable', '{$phpPath}');

require '{$recipePath}';

PHP;
        // Save to file
        $content_php = fopen($this->getWorkbench()->filemanager()->getPathToBaseFolder() . $slash . $buildFolder . $slash . 'build.php', 'w');
        fwrite($content_php, $content);
        fclose($content_php);
        
        // Return the deployer CLI parameters to run the task
        return "-f=\"{$buildFolder}{$slash}build.php\" {$deployerTaskName}";
    }
    
    /**
     * Prepares the folder structure needed to run the deployer command and 
     * returns it's path relative to workbench installation root.
     * 
     * ```
     * project_folder
     * - builds
     * 
     * ```
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function prepareDeployerProjectFolder(TaskInterface $task) : string
    {
        $projectFolder = $this->getProjectFolderRelativePath($task);
        $basePath = $this->getBasePath();
        
        // Make sure, folder project/builds exists
        $buildsFolderPath = $projectFolder 
            . DIRECTORY_SEPARATOR . $this->getFolderNameForBuilds();
        Filemanager::pathConstruct($basePath . $buildsFolderPath);
        
        // copy current composer.phar to project folder, so it can be used for composer commands.
        if (file_exists($this->getBasePath() . 'composer.phar')) {
            $this->getWorkbench()->filemanager()->copyFile($this->getBasePath() . 'composer.phar', $this->getBasePath() . $projectFolder . DIRECTORY_SEPARATOR . 'composer.phar');
        }
        
        return $projectFolder;
    }

    /**
     * Generates buildname from buildversion and current time. 
     * The returned buildname is the concatinaton of the versionnumber and a timestamp of the current time,
     * formatted as `YYYYMMDDhhmmss`, seperated by a `+` character. 
     * Example: `1.0-beta+201911210859`
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function generateBuildName(TaskInterface $task) : string
    {
        $timestamp = date('YmdHis');
        
        return $this->getVersion($task) . '+' . $timestamp;

    } 
    
    /**
     * This function gets the version for the current build, passed as an parameter for this action.
     * Because of this Parameter being required for the build action, this function throws an `ActionInputMissingError` 
     * if the version is missing or invalid.
     * Any non-empty string is valid as parameter for the version.
     * The version number is returned as a string. 
     * 
     * @param TaskInterface $task
     * @throws ActionInputMissingError
     * @return string
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
     * This function gets the comment, possibly passed as an argument to the build action, and returns them as a string.
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function getComment(TaskInterface $task) : string
    {
        return $this->getBuildData($task, 'comment', 'comment', '');
    }

    /**
     * @param TaskInterface $task
     * @param string $buildAttribute
     * @param $default
     * @return mixed|null
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
     * This function gets the notes, possibly passed as an argument to the build action, and returns them as a string.
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function getNotes(TaskInterface $task) : string
    {
        return $this->getBuildData($task, 'notes', 'notes', '');
    }
    
    /**
     * This function retruns the valid `composer.json` for the current build action.
     * The function achives this by getting the default `composer.json` from the project data of the current task,
     * and an custom one, which may be passed as an argument for that specific build action.
     * If there is a custom json passed for `composer.json` the function returns it, else it will return the default one. 
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function getComposerJson(TaskInterface $task) : string
    {
        $defaultComposerJson = $this->getBuildVariantData($task, 'composer_json');
        $customComposerJson = null;
        
        if ($task->hasParameter('composer_json')) {
            $customComposerJson = $task->getParameter('composer_json');
        }

        $composerJson = $customComposerJson ? $customComposerJson : ($defaultComposerJson ?? '{}');   
        if ($this->getProjectData($task, 'build_recipe') === BuildRecipeDataType::COMPOSER_INSTALL_WITH_ASSET_FIX) {
            $composerJson = $this->getComposerJsonWithAssetFix($composerJson, $task);
        }
        return $composerJson;
    }

    /**
     * Get JS assets from asset-packagist latest versin cache to avoid CDN issues
     * 
     * JavaScript packages are fetched from https://asset-packagist.org - a bridge between Composer, NPM and Bower.
     * This service has had some glitches in 2026 and did not always provide the correct version information, resulting
     * in unresolveable version constraints. A workaround was suggested in https://github.com/hiqdev/asset-packagist/issues/181
     * which solved the issues multiple times. This method here automates that workaround.
     * 
     * Technically, the latest cached version of the package is retrieved instead of querying asset-packagist
     * regularly. This helps if the package was there, but suddenly "disappeared".
     * 
     * @link https://github.com/hiqdev/asset-packagist/issues/181
     * 
     * @param string $composerJson
     * @param TaskInterface $task
     * @return string
     */
    protected function getComposerJsonWithAssetFix(string $composerJson, TaskInterface $task) : string
    {
        $composerArray = json_decode($composerJson, true);
        
        $lockData = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.build');
        $lockData->getFilters()->addConditionFromString('build_variant', $this->getBuildVariantData($task, 'uid'), ComparatorDataType::EQUALS);
        $lockData->getFilters()->addConditionFromString('status', 99, ComparatorDataType::EQUALS);
        $lockData->getSorters()->addFromString('created_on', SortingDirectionsDataType::DESC);
        $lockCol = $lockData->getColumns()->addFromExpression('composer_lock');
        $lockData->dataRead(1);
        $lockJson = $lockCol->getValue(0);
        $lockArray = json_decode($lockJson, true);
        
        $repos = [];
        foreach ($lockArray['packages'] as $lockPackage) {
            $packageName = $lockPackage['name'];
            if (
                StringDataType::startsWith($packageName, 'npm-asset/') 
                || StringDataType::startsWith($packageName, 'bower-asset/')
            ) {
                $repos[] = [
                    "type" => "composer",
                    "url" => "https://cdn.asset-packagist.org/p/{$packageName}/latest.json",
                    "only" => [
                        $packageName
                    ]
                ];
            }
        }
        // Put the workaround-repos in the front to give them higher priority
        $composerArray['repositories'] = array_merge($repos, $composerArray['repositories']);
        return json_encode($composerArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * This function takes a stringifed json and saves it to the `composer.json` in the current projects working directory.
     * 
     * @param TaskInterface $task
     * @param string $projectFolder
     * @return string
     */
    protected function createComposerJson(TaskInterface $task, string $projectFolder) : string
    {
        $content = $this->getComposerJson($task);
        $contentArray = json_decode($content, true);
        if (! $contentArray) {
            throw new ActionRuntimeError($this, 'Invalid composer.json detected!');
        }
        $contentPretty = json_encode($contentArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($this->getBasePath() . $projectFolder . DIRECTORY_SEPARATOR . 'composer.json', $contentPretty);
        return $content;
    }
    
    /**
     * This function retruns the valid `auth.json` for the current build action.
     * The function achives this by getting the default `auth.json` from the project data of the current task,
     * and an custom one, which may be passed as an argument for that specific build action.
     * If there is a custom json passed for `auth.json` the function returns it, else it will return the default one. 
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function getComposerAuthJson(TaskInterface $task) : string
    {
        /* @var \exface\Core\CommonLogic\UxonObject $authUxon */
        $authUxon = $this->getApp()->getConfig()->getOption('COMPOSER.AUTH');        
        $buildAuthJson = $this->getBuildVariantData($task, 'composer_auth_json');
        if ($buildAuthJson) {
            $buildAuthUxon = UxonObject::fromJson($buildAuthJson);
            $authUxon = $authUxon->extend($buildAuthUxon);
        }
        return $authUxon->toJson(true);
    }
    
    /**
     * 
     * @param TaskInterface $task
     * @param string $projectFolder
     * @return string
     */
    protected function createComposerAuthJson(TaskInterface $task, string $projectFolder) : string
    {
        $content = $this->getComposerAuthJson($task);
        file_put_contents($this->getBasePath() . $projectFolder . DIRECTORY_SEPARATOR . 'auth.json', $content);
        return $content;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
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
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
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
    
    /**
     * deletes all directories and files created in the building process, except the actual build (-directory)
     * 
     * @param string $projectFolder
     */
    protected function cleanupFiles(string $projectFolder)
    {
        $baseAbsPath = $this->getBasePath();
        $stagedFiles = [
            $projectFolder . DIRECTORY_SEPARATOR . 'build.php',
            $projectFolder . DIRECTORY_SEPARATOR . 'composer.json',
            $projectFolder . DIRECTORY_SEPARATOR . 'composer.lock',
            $projectFolder . DIRECTORY_SEPARATOR . 'package_bom.json',
            $projectFolder . DIRECTORY_SEPARATOR . 'auth.json'
        ];
        
        $stagedDirectories = [
            // .composer folder contains the composer cache. Perhaps it's not bad too keep it...
            // $baseAbsPath . $projectFolder . DIRECTORY_SEPARATOR . '.composer'
        ];   
        
        //delete files first
        foreach($stagedFiles as $file){
            if (file_exists($file)){
                unlink($file);
            }
        }
        
        //delete directories last
        foreach($stagedDirectories as $dir){
            Filemanager::deleteDir($dir);
        }
   
    }
    
    /**
     * Returns the environment variables needed for the cli to work with symfony.
     * 
     * @param string $projectFolder
     * @return array
     */
    protected function getCmdEnvironmentVars(string $projectFolder) : array
    {
        $composerHomePath = $this->getComposerHomePath($projectFolder);
        return [
            'COMPOSER_HOME' => $composerHomePath,
        ];
    }
    
    /**
     * Returns absolute path to .composer file in the projects working directory.
     * 
     * Example: `C:\wamp\www\exface\exface\deployer\testHost\.composer`
     * 
     * @param string $projectFolder
     * @return string
     */
    protected function getComposerHomePath(string $projectFolder) : string
    {
        return $this->getBasePath() . $projectFolder . DIRECTORY_SEPARATOR . '.composer';
    }

}