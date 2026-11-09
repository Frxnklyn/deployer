<?php
namespace axenox\Deployer\Tests\PHPUnit\Integration;

use axenox\Deployer\Actions\Build;
use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Pipelines\Build\ComposerInstall;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\ConfigurationInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises action/factory/native pipeline integration with in-memory persistence only.
 *
 * @author Brooklyn Fränzschky
 */
class BuildNativePipelineTest extends TestCase
{
    private string $root;
    private array $record = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = \axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace::create('build-action');
        copy(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'NativeComposer.php', $this->root . DIRECTORY_SEPARATOR . 'composer.phar');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        NativeBuildFiles::remove($this->root);
    }

    /**
     * Builds real action orchestration with model/connection boundaries mocked.
     *
     * @param string|null $prototype
     * @param string $configuration
     * @return array
     */
    private function fixture(?string $prototype, string $configuration = '{}'): array
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $app = $this->createMock(AppInterface::class);
        $config = $this->createStub(ConfigurationInterface::class);
        $config->method('getOption')->willReturnMap([
            ['COMPOSER.AUTH', new UxonObject()],
            ['PHP_VERSION_PATHS', new UxonObject(['fixture-php' => PHP_BINARY])]
        ]);
        $app->method('getConfig')->willReturn($config);
        $workbench->method('getApp')->willReturn($app);
        $app->method('get')->willReturnCallback(
            /**
             * Constructs the actual selected class with Core's supplied runtime arguments.
             *
             * @param mixed $selector
             * @param mixed $selectorClass
             * @param array $arguments
             * @return ComposerInstall
             */
            static function ($selector, $selectorClass, array $arguments): ComposerInstall {
                return new ComposerInstall(...$arguments);
            }
        );
        $action = $this->getMockBuilder(Build::class)->disableOriginalConstructor()->onlyMethods([
            'getWorkbench', 'getApp', 'getBuildVariantData', 'getProjectData', 'getBasePath',
            'getProjectFolderRelativePath', 'generateBuildName', 'getPreviousSuccessfulComposerLock'
        ])->getMock();
        $action->method('getWorkbench')->willReturn($workbench);
        $action->method('getApp')->willReturn($app);
        $action->method('getBuildVariantData')->willReturnCallback(
            /**
             * @param mixed $task
             * @param string $attribute
             * @return string|null
             */
            static function ($task, string $attribute) use ($prototype, $configuration): ?string {
                return ['prototype_file' => $prototype, 'config_uxon' => $configuration][$attribute] ?? null;
            }
        );
        $action->method('getProjectData')->willReturn('');
        $action->method('getPreviousSuccessfulComposerLock')->willReturn('{"packages":[{"name":"npm-asset/jquery"}]}');
        $action->method('getBasePath')->willReturn($this->root . DIRECTORY_SEPARATOR);
        $action->method('getProjectFolderRelativePath')->willReturn('project');
        $action->method('generateBuildName')->willReturn('1.0+action');
        $sheet = $this->createMock(DataSheetInterface::class);
        $sheet->method('setCellValue')->willReturnCallback(
            /**
             * @param string $name
             * @param int $row
             * @param mixed $value
             * @return DataSheetInterface
             */
            function (string $name, int $row, $value) use ($sheet): DataSheetInterface {
                $this->record[$name] = $value;
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
                return $this->record[$name] ?? null;
            }
        );
        $task = new GenericTask($workbench);
        $task->setParameter('php', 'fixture-php');
        return [$action, $task, $sheet];
    }

    /**
     * The action resolves prototype settings, honors one-run replacement and captures evidence.
     *
     * @return void
     */
    public function testSelectedPipelineRunsThroughActualFactoryAndActionBoundary(): void
    {
        $configuration = json_encode([
            'composer_json' => ['require' => ['example/library' => '1.0.0']],
            'composer_auth' => ['http-basic' => ['example.invalid' => ['username' => 'fixture', 'password' => 'fixture-password-never-log']]]
        ]);
        [$action, $task, $sheet] = $this->fixture(ComposerInstall::class, $configuration);
        $task->setParameter('composer_json', '{"require":{"example/library":"3.0.0"}}');
        $task->setParameter('comment', 'native fixture');
        $generator = (new \ReflectionMethod(Build::class, 'runSelectedPipeline'))->invoke($action, $task, $sheet, '1.0+action', ComposerInstall::class);
        foreach ($generator as $message) {
            self::assertIsString($message);
            self::assertStringNotContainsString('fixture-password-never-log', $message);
        }
        self::assertSame(99, $this->record['status']);
        self::assertSame('3.0.0', json_decode($this->record['composer_lock'], true)['packages'][0]['version']);
        self::assertSame('3.0.0', json_decode($this->record['package_bom'], true)['packages'][0]['version']);
        self::assertSame('native fixture', $this->record['comment']);
        self::assertFileExists($generator->getReturn()->getArchivePath());
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.build-*'));
    }

    /**
     * The current runtime must not resolve a different or missing PHP from PATH.
     *
     * @return void
     */
    public function testCurrentRuntimeUsesTheRunningInterpreter(): void
    {
        [$action, $task, $sheet] = $this->fixture(ComposerInstall::class, json_encode([
            'composer_json' => ['require' => ['example/library' => '1.0.0']],
            'composer_auth' => ['http-basic' => ['example.invalid' => ['password' => 'fixture-password-never-log']]]
        ]));
        $task->setParameter('php', PHP_VERSION);
        $oldPath = getenv('PATH');
        if (PHP_OS_FAMILY === 'Windows') {
            putenv('PATH=' . getenv('SystemRoot') . '\\System32');
        }

        try {
            $generator = (new \ReflectionMethod(Build::class, 'runSelectedPipeline'))
                ->invoke($action, $task, $sheet, '1.0+action', ComposerInstall::class);
            iterator_to_array($generator);
            $archive = new \PharData($generator->getReturn()->getArchivePath());
            self::assertSame(PHP_VERSION . PHP_EOL . PHP_BINARY, $archive['vendor/runtime.txt']->getContent());
        } finally {
            putenv($oldPath === false ? 'PATH' : 'PATH=' . $oldPath);
        }
    }

    /**
     * A web SAPI binary must never be passed to Composer in place of CLI PHP.
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testCurrentRuntimeResolvesCliInsteadOfUsingWebServerBinary(): void
    {
        define('axenox\\Deployer\\Actions\\PHP_BINARY', $this->root . DIRECTORY_SEPARATOR . 'httpd.exe');
        $this->testCurrentRuntimeUsesTheRunningInterpreter();
    }

    /**
     * The action supplies successful variant history only when requested by the prototype.
     *
     * @return void
     */
    public function testNativeAssetFixReceivesHistoryThroughTheBuildTask(): void
    {
        [$action, $task, $sheet] = $this->fixture(ComposerInstall::class,
            '{"composer_json":{"require":{"example/library":"1.0.0"}},"composer_auth":{"http-basic":{"example.invalid":{"password":"fixture-password-never-log"}}},"asset_packagist_cache_fix":true}');
        $action->expects(self::once())->method('getPreviousSuccessfulComposerLock')->with($task);
        $run = (new \ReflectionMethod(Build::class, 'runSelectedPipeline'))
            ->invoke($action, $task, $sheet, '1.0+action', ComposerInstall::class);
        iterator_to_array($run);
        self::assertSame(99, $this->record['status']);
        self::assertSame(['npm-asset/jquery'], json_decode($this->record['composer_json'], true)['repositories'][0]['only']);
    }

    /**
     * Explicit invalid selection/configuration fails the action, never using a legacy recipe.
     *
     * @return void
     */
    public function testSelectedPipelineFailuresPersistFailedStateWithoutLegacyFallback(): void
    {
        foreach ([
            ['not-a-prototype', '{}'],
            [ComposerInstall::class, '{"php_executable":"forbidden"}'],
            [ComposerInstall::class, '{"composer_auth":{"http-basic":{"example.invalid":{"password":"fixture-password-never-log"}}},"composer_json":{"extra":{"test_mode":"fail"}}}']
        ] as [$prototype, $configuration]) {
            $this->record = [];
            [$action, $task, $sheet] = $this->fixture($prototype, $configuration);
            $generator = (new \ReflectionMethod(Build::class, 'performDeferred'))->invoke($action, $task, $sheet);
            iterator_to_array($generator);
            self::assertSame(90, $this->record['status']);
            self::assertStringNotContainsString('fixture-password-never-log', $this->record['log']);
            self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.build-*'));
        }
    }

    /**
     * Absent/empty selections fail explicitly rather than executing removed recipes.
     *
     * @return void
     */
    public function testUnconfiguredVariantsRequireMigrationWithoutLegacyFallback(): void
    {
        foreach ([null, '', '  '] as $prototype) {
            [$action, $task, $sheet] = $this->fixture($prototype);
            $generator = (new \ReflectionMethod(Build::class, 'performDeferred'))->invoke($action, $task, $sheet);
            iterator_to_array($generator);
            self::assertSame(90, $this->record['status']);
            self::assertStringContainsString('No build pipeline selected.', $this->record['log']);
            self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'project');
        }
    }
}
