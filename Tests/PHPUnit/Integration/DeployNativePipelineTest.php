<?php
namespace axenox\Deployer\Tests\PHPUnit\Integration;

use axenox\Deployer\Actions\Deploy;
use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Common\Pipelines\BuildTask;
use axenox\Deployer\Pipelines\Build\ComposerInstall;
use axenox\Deployer\Pipelines\Deploy\ManualTransfer;
use axenox\Deployer\Pipelines\Deploy\UpdaterPull;
use axenox\Deployer\Common\Pipelines\NativeOtaPackage;
use example\Native\Pipelines\Deploy\ConfigurationOnly;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'ConfigurationOnly.php';

/**
 * Action/factory/native package integration with in-memory records only.
 *
 * @author Brooklyn Fränzschky
 */
class DeployNativePipelineTest extends TestCase
{
    private string $root;
    private array $records = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = \axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace::create('deploy-action');
        mkdir($this->root . DIRECTORY_SEPARATOR . 'project');
        mkdir($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'builds');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'fixture.txt', 'local build fixture');
        NativeBuildFiles::command('tar', [
            '-czf', $this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '1.0+fixture.tar.gz',
            '--', 'fixture.txt'
        ], $this->root, 30);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        NativeBuildFiles::remove($this->root);
    }

    /**
     * @param array $hosts
     * @param bool $failPersistence
     * @param string $projectLicenses
     * @return array
     */
    private function fixture(array $hosts, bool $failPersistence = false, string $projectLicenses = ''): array
    {
        $this->records = [];
        $workbench = $this->createMock(WorkbenchInterface::class);
        $app = $this->createMock(AppInterface::class);
        $workbench->method('getApp')->willReturn($app);
        $app->method('get')->willReturnCallback(
            /**
             * @param mixed $selector
             * @param mixed $selectorClass
             * @param array $arguments
             * @return object
             */
            static function ($selector, $selectorClass, array $arguments): object {
                $class = ltrim($selector->toString(), '\\');
                return new $class(...$arguments);
            }
        );
        $action = $this->getMockBuilder(Deploy::class)->disableOriginalConstructor()->onlyMethods([
            'getWorkbench', 'getHostData', 'getBuildData', 'getProjectData', 'getBasePath',
            'getProjectFolderRelativePath', 'getHostCount', 'getHostAttributeAliases'
        ])->getMock();
        $action->method('getWorkbench')->willReturn($workbench);
        $action->method('getHostCount')->willReturn(count($hosts));
        $action->method('getBasePath')->willReturn($this->root);
        $action->method('getProjectFolderRelativePath')->willReturn('project');
        $action->method('getProjectData')->willReturn($projectLicenses);
        $action->method('getHostAttributeAliases')->willReturn([
            'prototypefile', 'deployconfig', 'phpcli', 'pathabstoapi', 'pathreltoreleases', 'name', 'dataconnection'
        ]);
        $action->method('getHostData')->willReturnCallback(
            /**
             * @param mixed $task
             * @param string $attribute
             * @param int $index
             * @return string|null
             */
            static function ($task, string $attribute, int $index = 0) use ($hosts): ?string {
                return $hosts[$index][$attribute] ?? [
                    'uid' => 'host-' . $index, 'name' => 'fixture-' . $index,
                    'deploy_config' => '{}', 'path_abs_to_api' => 'C:\\private\\installation',
                    'operating_system' => 'windows', 'php_cli' => 'php'
                ][$attribute] ?? null;
            }
        );
        $action->method('getBuildData')->willReturnCallback(
            /**
             * @param mixed $task
             * @param string $attribute
             * @return string|null
             */
            static function ($task, string $attribute): ?string {
                return ['uid' => 'build-1', 'name' => '1.0+fixture', 'status' => '99'][$attribute] ?? null;
            }
        );
        $sheet = $this->createMock(DataSheetInterface::class);
        $sheet->method('setCellValue')->willReturnCallback(
            /**
             * @param string $name
             * @param int $row
             * @param mixed $value
             * @return DataSheetInterface
             */
            function (string $name, int $row, $value) use ($sheet): DataSheetInterface {
                $this->records[$row][$name] = $value;
                return $sheet;
            }
        );
        $sheet->method('getCellValue')->willReturnCallback(
            /**
             * @param string $name
             * @param int $row
             * @return mixed
             */
            function (string $name, int $row) {
                return $name === 'uid' ? 'deployment-' . $row : ($this->records[$row][$name] ?? null);
            }
        );
        $sheet->method('dataUpdate')->willReturnCallback(
            /**
             * @param mixed ...$arguments
             * @return int
             */
            function (...$arguments) use ($failPersistence): int {
                if ($failPersistence && ($this->records[0]['status'] ?? null) === 60) {
                    throw new \RuntimeException('simulated persistence failure with secret-do-not-log');
                }
                return 1;
            }
        );
        return [$action, new GenericTask($workbench), $sheet];
    }

    /**
     * @param array $fixture
     * @return string
     */
    private function runAction(array $fixture): string
    {
        [$action, $task, $sheet] = $fixture;
        $generator = (new \ReflectionMethod(Deploy::class, 'performDeferred'))->invoke($action, $task, $sheet);
        return implode('', iterator_to_array($generator));
    }

    /**
     * @return void
     */
    public function testSelectedHostsPrepareDistinctPackagesAndPersistOnlyPublishedState(): void
    {
        $log = $this->runAction($this->fixture([
            ['prototype_file' => ManualTransfer::class, 'deploy_config' => '{"config":{"Fixture.config.json":{"password":"secret-do-not-log"}}}'],
            ['prototype_file' => ManualTransfer::class]
        ]));
        self::assertStringNotContainsString('secret-do-not-log', $log);
        self::assertStringNotContainsString('http', $log);
        self::assertStringContainsString('PREPARED ONLY - NOT INSTALLED', $log);
        foreach ($this->records as $row) {
            self::assertSame(60, $row['status']);
            self::assertArrayNotHasKey('completed_on', $row);
            self::assertStringContainsString('SHA-256:', $row['log']);
        }
        $packages = glob($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz');
        self::assertCount(2, $packages);
        $identities = [];
        foreach ($packages as $path) {
            $package = new \PharData($path);
            $manifest = json_decode($package['manifest.json']->getContent(), true);
            $identities[] = $manifest['deployment_id'] . ':' . $manifest['host_id'];
        }
        sort($identities);
        self::assertSame(['deployment-0:host-0', 'deployment-1:host-1'], $identities);
    }

    public function testWindowsLegacyRootPreparesNativeOtaWithDeferredBindingThroughDeploy(): void
    {
        $log = $this->runAction($this->fixture([[
            'prototype_file' => UpdaterPull::class,
            'operating_system' => 'windows',
            'path_abs_to_api' => '/local_host_pui_one'
        ]]));
        self::assertSame(60, $this->records[0]['status']);
        self::assertArrayNotHasKey('completed_on', $this->records[0]);
        self::assertStringContainsString('deferred', $log);
        $path = NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'project', 'deployment-0');
        self::assertFileExists($path);
        self::assertStringContainsString(hash_file('sha256', $path), $log);
        self::assertStringNotContainsString('/local_host_pui_one', file_get_contents($path));
    }

    /**
     * @return void
     */
    public function testInvalidSelectionConfigurationAndGeneratorContractsNeverFallBack(): void
    {
        foreach ([
            ['prototype_file' => 'invalid-prototype'],
            ['prototype_file' => ComposerInstall::class],
            ['prototype_file' => ManualTransfer::class, 'deploy_config' => '{"php_cli":"forbidden"}'],
            ['prototype_file' => ManualTransfer::class, 'deploy_config' => '{"hostPathToInstallationRoot":"secret-do-not-log"}'],
            ['prototype_file' => ManualTransfer::class, 'deploy_config' => '{"relative_deploy_path":"old"}'],
            ['prototype_file' => ManualTransfer::class, 'deploy_config' => '{"default_app_config":{}}'],
            ['prototype_file' => ManualTransfer::class, 'deploy_config' => '{"unknown":"secret-do-not-log"}'],
            ['prototype_file' => ManualTransfer::class, 'path_abs_to_api' => '..\\old'],
            ['prototype_file' => ConfigurationOnly::class, 'deploy_config' => '{"mode":"nontext"}'],
            ['prototype_file' => ConfigurationOnly::class, 'deploy_config' => '{"mode":"noresult"}']
        ] as $host) {
            $log = $this->runAction($this->fixture([$host]));
            self::assertSame(90, $this->records[0]['status']);
            self::assertArrayHasKey('completed_on', $this->records[0]);
            self::assertStringContainsString('no legacy fallback', $log);
            self::assertStringNotContainsString('secret-do-not-log', $log);
        }
    }

    /**
     * @return void
     */
    public function testGenericDeploymentDoesNotRequireAnyArchiveOrProjectDirectory(): void
    {
        NativeBuildFiles::remove($this->root . DIRECTORY_SEPARATOR . 'project');
        $log = $this->runAction($this->fixture([['prototype_file' => ConfigurationOnly::class]]));
        self::assertSame(99, $this->records[0]['status']);
        self::assertStringContainsString('Configuration-only fixture completed', $log);
    }

    /**
     * Native pull must receive release inputs and publish without invoking a recipe.
     *
     * @return void
     */
    public function testNativePullUsesActionOwnedReleaseAndRemainsAwaitingInstallation(): void
    {
        $log = $this->runAction($this->fixture([['prototype_file' => UpdaterPull::class]]));
        self::assertSame(60, $this->records[0]['status']);
        self::assertArrayNotHasKey('completed_on', $this->records[0]);
        self::assertStringContainsString('PUBLISHED ONLY - NOT INSTALLED', $log);
        self::assertStringNotContainsString('http://', $log);
        self::assertFileExists(NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'project', 'deployment-0'));
        $export = $this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'builds'
            . DIRECTORY_SEPARATOR . '1.0+fixture_fixture0.phx';
        self::assertFileExists($export);
        self::assertStringContainsString($export, $log);
        self::assertSame(hash_file('sha256', NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'project', 'deployment-0')),
            hash_file('sha256', $export));
    }

    /**
     * Failure to persist publication must remove the executable artifact.
     *
     * @return void
     */
    public function testNativePullPersistenceFailureDiscardsExecutableArtifact(): void
    {
        $this->runAction($this->fixture([['prototype_file' => UpdaterPull::class]], true));
        self::assertSame(90, $this->records[0]['status']);
        self::assertFileDoesNotExist(NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'project', 'deployment-0'));
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'builds'
            . DIRECTORY_SEPARATOR . '1.0+fixture_fixture0.phx');
        self::assertCount(0, glob($this->root . DIRECTORY_SEPARATOR . 'project'
            . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz'));
    }

    /**
     * @return void
     */
    public function testPersistenceFailureDiscardsOnlyItsPreparedPackage(): void
    {
        $log = $this->runAction($this->fixture([['prototype_file' => ManualTransfer::class]], true));
        self::assertSame(90, $this->records[0]['status']);
        self::assertStringNotContainsString('secret-do-not-log', $log);
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz'));
    }

    /**
     * The previous increment's actual Composer pipeline feeds the native deployment boundary.
     *
     * @return void
     */
    public function testNativeComposerBuildCanBePreparedByDeployWithoutLegacyOrDownloads(): void
    {
        $project = $this->root . DIRECTORY_SEPARATOR . 'project';
        unlink($project . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '1.0+fixture.tar.gz');
        $pipeline = new ComposerInstall($this->createStub(WorkbenchInterface::class));
        $pipeline->setComposerAuth(new \exface\Core\CommonLogic\UxonObject([
            'http-basic' => ['example.invalid' => ['password' => 'fixture-password-never-log']]
        ]));
        $generator = $pipeline->run(new BuildTask(
            $project,
            $project . DIRECTORY_SEPARATOR . 'builds',
            '1.0+fixture',
            PHP_BINARY,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'NativeComposer.php',
            '{"require":{"example/library":"1.0.0"}}'
        ));
        iterator_to_array($generator);
        $build = $generator->getReturn();
        self::assertSame(99, $build->getStatus());
        $this->runAction($this->fixture([['prototype_file' => ManualTransfer::class]]));
        self::assertSame(60, $this->records[0]['status']);
        $packages = glob($project . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz');
        self::assertCount(1, $packages);
        $package = new \PharData($packages[0]);
        $manifest = json_decode($package['manifest.json']->getContent(), true);
        self::assertSame(hash_file('sha256', $build->getArchivePath()), $manifest['payload']['sha256']);
    }

    /**
     * Invalid host roots report an actionable fixed hint without exposing host values or preparing artifacts.
     *
     * @return void
     */
    public function testInvalidHostRootReportsSafeHintBeforePreparation(): void
    {
        foreach ([
            ['path_abs_to_api' => '/private-do-not-log', 'operating_system' => 'windows'],
            ['path_abs_to_api' => 'C:\\private-do-not-log', 'operating_system' => 'linux'],
            ['path_abs_to_api' => 'private-do-not-log', 'operating_system' => 'windows']
        ] as $host) {
            $log = $this->runAction($this->fixture([['prototype_file' => ManualTransfer::class] + $host]));
            self::assertSame(90, $this->records[0]['status']);
            self::assertStringContainsString('Invalid host installation root (path_abs_to_api).', $log);
            self::assertStringContainsString('C:\\Apps\\MyApplication', $log);
            self::assertStringNotContainsString('private-do-not-log', $log);
            self::assertSame($log, $this->records[0]['log']);
            self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.native-deployments');
        }
    }

    /**
     * @return void
     */
    public function testUnconfiguredHostsRequireMigrationWithoutLegacyExecution(): void
    {
        foreach ([null, '', '  '] as $prototype) {
            $log = $this->runAction($this->fixture([['prototype_file' => $prototype]]));
            self::assertSame(90, $this->records[0]['status']);
            self::assertStringContainsString('No deployment pipeline selected.', $log);
            self::assertStringContainsString('migration', $log);
            self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*'));
        }

    }

    /**
     * Host overrides and project defaults survive the config rename boundary as data.
     *
     * @return void
     */
    public function testProjectLicenseDefaultsAndHostOverridesAreIncludedWithoutLoggingThem(): void
    {
        $project = '{"licenses":{"Commercial":{"license_text":"Project terms","license_link":"https://example.invalid/license"}},"packages":[{"name":"example/library","license_used":"Commercial"}]}';
        $settings = '{"config":{"Licenses.config.json":{"licenses":{"Commercial":{"license_text":"Host private terms"}}}}}';
        $log = $this->runAction($this->fixture([
            ['prototype_file' => ManualTransfer::class, 'deploy_config' => $settings]
        ], false, $project));
        self::assertSame(60, $this->records[0]['status'], $log);
        self::assertStringNotContainsString('Host private terms', $log);
        $paths = glob($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz');
        self::assertCount(1, $paths);
        $package = new \PharData($paths[0]);
        $manifest = json_decode($package['manifest.json']->getContent(), true);
        $terms = $manifest['pipeline']['config']['Licenses.config.json'];
        self::assertSame('Host private terms', $terms['licenses']['Commercial']['license_text']);
        self::assertSame('https://example.invalid/license', $terms['licenses']['Commercial']['license_link']);
        self::assertSame('example/library', $terms['packages'][0]['name']);
    }

    /**
     * A failed attempt may not clean up an earlier prepared artifact.
     *
     * @return void
     */
    public function testMissingBuildFailsWithoutDeletingAnEarlierPackage(): void
    {
        $this->runAction($this->fixture([['prototype_file' => ManualTransfer::class]]));
        $paths = glob($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz');
        self::assertCount(1, $paths);
        $hash = hash_file('sha256', $paths[0]);
        unlink($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '1.0+fixture.tar.gz');
        $this->runAction($this->fixture([['prototype_file' => ManualTransfer::class]]));
        self::assertSame(90, $this->records[0]['status']);
        self::assertFileExists($paths[0]);
        self::assertSame($hash, hash_file('sha256', $paths[0]));
    }
}
