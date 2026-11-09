<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\BuildResult;
use axenox\Deployer\Common\Pipelines\BuildTask;
use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use axenox\Deployer\Pipelines\Build\ComposerInstall;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use PHPUnit\Framework\TestCase;

/**
 * Executes the native pipeline with real local PHP/tar, never Composer downloads.
 *
 * @author Brooklyn Fränzschky
 */
class ComposerInstallTest extends TestCase
{
    private string $root;
    private ComposerInstall $pipeline;

    /**
     * Creates an owned test tree outside the worktree, including spaces in paths.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = \axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace::create('native build');
        mkdir($this->root . DIRECTORY_SEPARATOR . 'builds');
        $this->pipeline = new ComposerInstall(new Workbench());
        $this->pipeline->importUxonObject(new UxonObject([
            'composer_json' => ['require' => ['example/library' => '1.0.0']],
            'composer_auth' => ['http-basic' => ['example.invalid' => ['username' => 'fixture', 'password' => 'fixture-password-never-log']]]
        ]));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        NativeBuildFiles::remove($this->root);
    }

    /**
     * @param string|null $replacement
     * @return BuildTask
     */
    private function task(?string $replacement = null): BuildTask
    {
        return new BuildTask(
            $this->root,
            $this->root . DIRECTORY_SEPARATOR . 'builds',
            '1.0+fixture',
            PHP_BINARY,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'NativeComposer.php',
            $replacement
        );
    }

    /**
     * @param \Generator $generator
     * @return BuildResult
     */
    private function consume(\Generator $generator): BuildResult
    {
        foreach ($generator as $message) {
            self::assertIsString($message);
            self::assertStringNotContainsString('fixture-password-never-log', $message);
        }
        return $generator->getReturn();
    }

    /**
     * Verifies archive entries, real installed lock/BOM, selected PHP and installer parity.
     *
     * @return void
     */
    public function testRealBuildArchiveAndEvidenceAgreeAndOverrideDoesNotMutatePrototype(): void
    {
        $before = $this->pipeline->exportUxonObject()->toJson();
        $replacement = '{"require":{"example/library":"2.0.0"}}';
        $task = $this->task($replacement);
        $task->licensesJson = json_encode([
            'packages' => [['name' => 'example/library', 'version' => '0.0.0', 'license_used' => 'Commercial']],
            'licenses' => ['Commercial' => ['license_text' => 'Explicit fixture terms']]
        ]);
        $result = $this->consume($this->pipeline->run($task));
        self::assertSame(99, $result->getStatus());
        self::assertSame($replacement, $result->getComposerJson());
        self::assertSame($before, $this->pipeline->exportUxonObject()->toJson());
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.build-*'));
        $extracted = $this->root . DIRECTORY_SEPARATOR . 'extracted';
        mkdir($extracted);
        CliCommandRunner::runCliCommandIntoArray('tar', ['-xzf', $result->getArchivePath(), '-C', $extracted]);
        self::assertSame($result->getComposerLock(), file_get_contents($extracted . DIRECTORY_SEPARATOR . 'composer.lock'));
        self::assertSame('2.0.0', json_decode($result->getComposerLock(), true)['packages'][0]['version']);
        self::assertSame('example/library', json_decode($result->getPackageBom(), true)['packages'][0]['name']);
        self::assertSame('2.0.0', json_decode($result->getPackageBom(), true)['packages'][0]['version']);
        self::assertSame('example/dev', json_decode($result->getPackageBom(), true)['packages'][1]['name']);
        $manifest = json_decode(file_get_contents($extracted . DIRECTORY_SEPARATOR . 'composer.json'), true);
        self::assertSame(['axenox\\PackageManager\\StaticInstaller::composerFinishInstall'], $manifest['scripts']['post-install-cmd']);
        self::assertSame(['psr-0' => ['axenox\\PackageManager' => 'vendor/']], $manifest['autoload']);
        self::assertFileExists($extracted . DIRECTORY_SEPARATOR . 'composer.phar');
        self::assertFileExists($extracted . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'licenses.json');
        $inventory = json_decode(file_get_contents($extracted . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'licenses.json'), true);
        self::assertSame('2.0.0', $inventory['packages'][0]['version']);
        self::assertSame('Commercial', $inventory['packages'][0]['license_used']);
        self::assertSame('Explicit fixture terms', $inventory['packages'][0]['license_text']['Commercial']);
        self::assertSame(PHP_VERSION . PHP_EOL . PHP_BINARY, file_get_contents($extracted . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'runtime.txt'));
        $listing = CliCommandRunner::runCliCommandIntoArray('tar', ['-tzf', $result->getArchivePath()])['stdout'];
        self::assertStringNotContainsString('auth.json', $listing);
        self::assertStringNotContainsString('.composer', $listing);
        self::assertStringNotContainsString('fixture-password-never-log', file_get_contents($extracted . DIRECTORY_SEPARATOR . 'composer.json'));

        $task = $this->task();
        $task->buildName = 'stored-default';
        $default = $this->consume($this->pipeline->run($task));
        self::assertSame('1.0.0', json_decode($default->getComposerLock(), true)['packages'][0]['version']);
    }

    /**
     * Failed exit codes, missing output and credentials must never produce a successful artifact.
     *
     * @return void
     */
    public function testCommandAndOutputFailuresCleanWorkspaceAndSuppressSecrets(): void
    {
        foreach (['fail', 'missing', 'credential', 'hiddencredential', 'lockcredential', 'timeout'] as $mode) {
            $this->pipeline->setComposerTimeout($mode === 'timeout' ? 1 : 30);
            $failed = false;
            try {
                $this->consume($this->pipeline->run($this->task(json_encode(['extra' => ['test_mode' => $mode]]))));
            } catch (\RuntimeException $error) {
                $failed = true;
                self::assertStringNotContainsString('fixture-password-never-log', $error->getMessage());
                self::assertNull($error->getPrevious());
                if ($mode === 'fail') {
                    self::assertStringContainsString('exit code 7', $error->getMessage());
                }
                if ($mode === 'timeout') {
                    self::assertStringContainsString('timed out after 1 seconds', $error->getMessage());
                }
            }
            self::assertTrue($failed, 'Expected failure for ' . $mode);
            self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.build-*'));
            self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '*'));
        }
    }

    /**
     * Unsupported task types fail before workspace creation.
     *
     * @return void
     */
    public function testUnsupportedTaskIsRejectedBeforeSideEffects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pipeline->run($this->createStub(PipelineTaskInterface::class))->current();
    }

    /**
     * Native builds capture effective cache settings while leaving stored UXON unchanged.
     *
     * @return void
     */
    public function testAssetCacheFixUsesActionSuppliedHistoryAndPersistsEffectiveConfiguration(): void
    {
        $this->pipeline->setAssetPackagistCacheFix(true);
        $stored = $this->pipeline->exportUxonObject()->toJson();
        $task = $this->task('{"require":{"example/library":"2.0.0"}}');
        $task->previousComposerLock = '{"packages":[{"name":"npm-asset/jquery"}]}';
        $result = $this->consume($this->pipeline->run($task));
        self::assertSame(['npm-asset/jquery'], json_decode($result->getComposerJson(), true)['repositories'][0]['only']);
        self::assertSame('2.0.0', json_decode($result->getComposerJson(), true)['require']['example/library']);
        self::assertSame($stored, $this->pipeline->exportUxonObject()->toJson());
    }

    /**
     * A requested fix cannot be disabled or run against guessed history.
     *
     * @return void
     */
    public function testAssetCacheFixWithoutHistoryFailsBeforeWorkspaceCreation(): void
    {
        $this->pipeline->setAssetPackagistCacheFix(true);
        try {
            $this->consume($this->pipeline->run($this->task()));
            self::fail('Missing history was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('previous successful build', $error->getMessage());
        }
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.build-*'));
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '*'));
    }

    /**
     * Traversal, shell options, absolute paths and credential trees are not archive inputs.
     *
     * @return void
     */
    public function testUnsafeArchiveEntriesAndMissingRequiredFilesAreRejected(): void
    {
        foreach (['../auth.json', '--checkpoint-action=exec=whoami', 'C:\\private', '/etc', 'vendor/../auth.json', '.composer', 'missing'] as $entry) {
            try {
                NativeBuildFiles::validateEntry($this->root, $entry);
                self::fail('Expected invalid archive entry.');
            } catch (\InvalidArgumentException | \RuntimeException $expected) {
                self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '*'));
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->pipeline->setSourceFiles(new UxonObject(['vendor']));
    }

    /**
     * Invalid selected settings never become shell fragments or runtime PHP overrides.
     *
     * @return void
     */
    public function testRuntimeValuesCannotBeImportedAsPrototypeSettings(): void
    {
        $this->expectException(\exface\Core\Exceptions\UxonMapError::class);
        $this->pipeline->importUxonObject(new UxonObject(['php_executable' => 'other-php']));
    }

    /**
     * Inline credentials cannot be shipped in Composer manifests.
     *
     * @return void
     */
    public function testInlineCredentialsAreRejectedBeforeSideEffects(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->pipeline->run($this->task('{"repositories":[{"url":"https://user:secret@example.invalid"}]}'))->current();
    }

    /**
     * A native nonzero exit is an exception even though Core's streaming default is silent.
     *
     * @return void
     */
    public function testNativeRunnerRejectsNonzeroWithoutCredentialDiagnostics(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Native build command failed');
        NativeBuildFiles::command(PHP_BINARY, ['-r', 'fwrite(STDERR,"fixture-password-never-log");exit(9);'], $this->root, 10);
    }

    /**
     * Fixed diagnostic hints identify common failures without forwarding process output.
     *
     * @return void
     */
    public function testNativeRunnerReportsSafeFailureCategories(): void
    {
        foreach ([
            'curl error 60: unable to get local issuer certificate' => 'TLS certificate validation failed.',
            'Could not authenticate against github.com' => 'Repository authentication failed.',
            'Your requirements could not be resolved to an installable set of packages.' => 'Composer dependency resolution failed.',
            'Unrecognized private diagnostic' => 'Native build command failed with exit code 1.',
        ] as $diagnostic => $expected) {
            $code = 'fwrite(STDERR,' . var_export($diagnostic . ' fixture-password-never-log', true) . ');exit(1);';
            try {
                NativeBuildFiles::command(PHP_BINARY, ['-r', $code], $this->root, 10);
                self::fail('A failed command must throw.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
                self::assertStringNotContainsString('fixture-password-never-log', $error->getMessage());
                self::assertStringNotContainsString($diagnostic, $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }

    /**
     * An already published build cannot be overwritten by a duplicate run.
     *
     * @return void
     */
    public function testExistingArchiveIsPreserved(): void
    {
        $archive = $this->root . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '1.0+fixture.tar.gz';
        file_put_contents($archive, 'existing-artifact');
        try {
            $this->consume($this->pipeline->run($this->task()));
            self::fail('Duplicate build must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('The build archive already exists.', $error->getMessage());
        }
        self::assertSame('existing-artifact', file_get_contents($archive));
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.build-*'));
    }
}
