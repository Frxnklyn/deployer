<?php
namespace axenox\Deployer\Tests\PHPUnit\Integration;

use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Common\Pipelines\NativeOtaPackage;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Pipelines\Deploy\UpdaterPull;
use axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Executes copied self-extractors using fixture runtimes, never a real target.
 */
class NativeOtaTest extends TestCase
{
    private string $root;
    private string $host;
    private string $source;

    /**
     * Creates only disposable fixture paths outside the repository.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = NativeTestWorkspace::create('ota');
        $this->host = $this->root . DIRECTORY_SEPARATOR . 'target with spaces';
        $this->source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($this->host, 0700);
        mkdir($this->source . DIRECTORY_SEPARATOR . 'builds', 0700, true);
        mkdir($this->source . DIRECTORY_SEPARATOR . 'vendor');
        copy(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'NativeHostRuntime.php',
            $this->source . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
        file_put_contents($this->source . DIRECTORY_SEPARATOR . 'composer.json', '{"require":{}}');
    }

    /**
     * Removes only this test's resolved workspace.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        NativeBuildFiles::remove($this->root);
    }

    /**
     * Prepares a real executable package without the external recipe runner.
     *
     * @param string $version
     * @param string $attempt
     * @param string|null $previous
     * @param bool $polling
     * @param bool $failInstaller
     * @return string
     */
    private function prepare(string $version, string $attempt, ?string $previous = null, bool $polling = false, bool $failInstaller = false, ?string $root = null): string
    {
        file_put_contents($this->source . DIRECTORY_SEPARATOR . 'fixture.json',
            json_encode(['version' => $version, 'previous' => $previous, 'fail' => $failInstaller]));
        NativeBuildFiles::command('tar', ['-czf', $this->source . DIRECTORY_SEPARATOR . 'builds'
            . DIRECTORY_SEPARATOR . $version . '.tar.gz', '--', 'vendor', 'composer.json', 'fixture.json'], $this->source, 30);
        $class = $polling ? \axenox\Deployer\Pipelines\Deploy\UpdaterPolling::class : UpdaterPull::class;
        $pipeline = (new $class($this->createStub(WorkbenchInterface::class)))
            ->setHostOperatingSystem(PHP_OS_FAMILY === 'Windows' ? 'Windows' : 'Linux')
            ->setHostPathToInstallationRoot($root ?? $this->host)
            ->setHostPhpExecutable(PHP_BINARY);
        $generator = $pipeline->run(new ReleaseDeploymentTask($attempt, 'host', 'build', $version, $this->source));
        $log = implode('', iterator_to_array($generator));
        $result = $generator->getReturn();
        self::assertSame(60, $result->getStatus());
        self::assertSame(NativeOtaPackage::path($this->source, $attempt), $result->artifactPath);
        self::assertSame(hash_file('sha256', $result->artifactPath), $result->sha256);
        self::assertStringNotContainsString('Executing task', $log);
        self::assertStringContainsString('NOT INSTALLED', $result->instructions);
        if ($polling) {
            self::assertStringContainsString('Polling is not installed or configured', $result->instructions);
            self::assertStringContainsString('axenox.PackageManager:SelfUpdate', $result->instructions);
        }
        self::assertCount(0, glob(dirname($result->artifactPath) . DIRECTORY_SEPARATOR . '*.tar.gz'));
        return $result->artifactPath;
    }

    /**
     * Executes only a copied package, with no php.ini or old application autoload.
     *
     * @param string $artifact
     * @param array $arguments
     * @param string|null $receipt
     * @return array
     */
    private function execute(string $artifact, array $arguments = [], ?string $receipt = null): array
    {
        $environment = getenv();
        unset($environment['EXFACE_NATIVE_OTA_RECEIPT']);
        if ($receipt !== null) {
            $environment['EXFACE_NATIVE_OTA_RECEIPT'] = $receipt;
        }
        $process = proc_open(array_merge([PHP_BINARY, '-n', $artifact], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $environment);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    public function testDeferredRootRequiresExplicitValidatedRootAndNeverSupportsAutomaticPush(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Regression for the original Windows host/root mismatch.');
        }
        $artifact = $this->prepare('v1', 'deferred', null, false, false, '/local_host_pui_one');
        $bytes = file_get_contents($artifact);
        $offset = strrpos($bytes, '__halt_compiler();') + strlen('__halt_compiler();');
        $archive = $this->root . DIRECTORY_SEPARATOR . 'inspect.tar.gz';
        file_put_contents($archive, substr($bytes, $offset));
        $extract = $this->root . DIRECTORY_SEPARATOR . 'inspect';
        mkdir($extract);
        \axenox\Deployer\Common\Pipelines\NativeDeploymentPackage::validateArchive($archive, $extract);
        $manifest = json_decode(file_get_contents($extract . DIRECTORY_SEPARATOR . 'manifest.json'), true);
        self::assertSame('deferred_explicit', $manifest['host']['installation_root_binding']);
        self::assertArrayNotHasKey('installation_root', $manifest['host']);
        self::assertStringNotContainsString('/local_host_pui_one', json_encode($manifest));
        [$exit, $output] = $this->execute($artifact);
        self::assertSame(1, $exit);
        self::assertStringContainsString('requires explicit --root', $output);
        self::assertSame([], array_values(array_diff(scandir($this->host), ['.', '..'])));
        foreach (['relative', '/local_host_pui_one', $this->root . DIRECTORY_SEPARATOR . 'missing'] as $root) {
            [$exit] = $this->execute($artifact, ['--root', $root]);
            self::assertSame(1, $exit);
            self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        }
        $receipt = $this->root . DIRECTORY_SEPARATOR . 'push-receipt.json';
        [$exit] = $this->execute($artifact, ['--root', $this->host], $receipt);
        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($receipt);
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        [$exit, $output] = $this->execute($artifact, ['--root', $this->host]);
        self::assertSame(0, $exit, $output);
        self::assertSame('v1', file_get_contents($this->host . DIRECTORY_SEPARATOR . 'current'
            . DIRECTORY_SEPARATOR . 'installed-version'));
        $control = $this->host . DIRECTORY_SEPARATOR . '.native-installer';
        $id = str_repeat('a', 32);
        $abandoned = $control . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $id;
        mkdir($abandoned);
        file_put_contents($control . DIRECTORY_SEPARATOR . 'journal.json', json_encode([
            'id' => $id, 'previous' => realpath($this->host . DIRECTORY_SEPARATOR . 'current'),
            'committed' => false, 'keep_releases' => 4, 'created_shared' => []
        ]));
        [$exit, $output] = $this->execute($artifact, ['--root', $this->host, '--recover']);
        self::assertSame(0, $exit, $output);
        self::assertDirectoryDoesNotExist($abandoned);
        self::assertFileDoesNotExist($control . DIRECTORY_SEPARATOR . 'journal.json');
        self::assertSame('v1', file_get_contents($this->host . DIRECTORY_SEPARATOR . 'current'
            . DIRECTORY_SEPARATOR . 'installed-version'));
    }

    /**
     * Optional native push integration must reject a deferred root before target writes.
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\RequiresMethod(\axenox\PackageManager\Common\Updater\NativePushReceiver::class, 'receive')]
    public function testOptionalPushReceiverRejectsDeferredRootBeforeInstallation(): void
    {
        $artifact = $this->prepare('v1', 'deferred', null, false, false, 'unusable-relative-root');
        $bytes = file_get_contents($artifact);
        $pushStaging = $this->root . DIRECTORY_SEPARATOR . 'push-private';
        \axenox\Deployer\Common\Pipelines\NativeHostInstaller::protect($pushStaging);
        $receiver = new \axenox\PackageManager\Common\Updater\NativePushReceiver($pushStaging, PHP_BINARY);
        $request = new \GuzzleHttp\Psr7\ServerRequest('POST', 'https://fixture.invalid/api/updater/native-push', [
            'Content-Type' => 'application/vnd.axenox.native-ota',
            'Content-Length' => strlen($bytes), 'X-Deployer-Deployment' => 'deferred',
            'X-Deployer-SHA256' => hash('sha256', $bytes),
            'X-Deployer-Filename' => 'ota-' . hash('sha256', 'deferred') . '.phx'
        ], $bytes);
        $response = $receiver->receive($request);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(90, json_decode((string) $response->getBody(), true)['status']);
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
    }

    public function testBoundPackageCannotOverrideRootAndRejectsDuplicateOptions(): void
    {
        $artifact = $this->prepare('v1', 'bound');
        foreach ([['--root', $this->source], ['--root', 'relative'], ['--root', $this->host, '--root', $this->host],
            ['--recover', '--recover'], ['--root']] as $arguments) {
            [$exit] = $this->execute($artifact, $arguments);
            self::assertSame(1, $exit);
        }
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        self::assertDirectoryDoesNotExist($this->source . DIRECTORY_SEPARATOR . '.native-installer');
        [$exit, $output] = $this->execute($artifact, ['--root', $this->host]);
        self::assertSame(0, $exit, $output);
    }

    /**
     * Push acknowledgement is bound to an installed attempt and the actual executable bytes.
     *
     * @return void
     */
    public function testCompletionReceiptRequiresSuccessfulInstallationAndCannotOverwriteAFile(): void
    {
        $artifact = $this->prepare('v1', 'attempt-1');
        $receipt = $this->root . DIRECTORY_SEPARATOR . 'receipt.json';
        file_put_contents($receipt, 'do-not-overwrite');
        [$exit] = $this->execute($artifact, [], $receipt);
        self::assertSame(1, $exit);
        self::assertSame('do-not-overwrite', file_get_contents($receipt));
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        unlink($receipt);
        [$exit, $output] = $this->execute($artifact, [], $receipt);
        self::assertSame(0, $exit, $output);
        self::assertSame([
            'protocol' => 'axenox.Deployer.native-ota/1',
            'deployment_id' => 'attempt-1',
            'sha256' => hash_file('sha256', $artifact),
            'status' => 99
        ], json_decode(file_get_contents($receipt), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('v1', file_get_contents($this->host . DIRECTORY_SEPARATOR . 'current'
            . DIRECTORY_SEPARATOR . 'installed-version'));
    }

    /**
     * Corruption and filesystem recovery cannot emit a completion acknowledgement.
     *
     * @return void
     */
    public function testCorruptedPackagesAndRecoveryNeverEmitSuccessfulReceipts(): void
    {
        $artifact = $this->prepare('v1', 'attempt-1');
        $receipt = $this->root . DIRECTORY_SEPARATOR . 'receipt.json';
        [$exit] = $this->execute($artifact, ['--recover'], $receipt);
        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($receipt);
        file_put_contents($artifact, 'corruption', FILE_APPEND);
        [$exit] = $this->execute($artifact, [], $receipt);
        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($receipt);
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        $failed = $this->prepare('failed', 'attempt-2', null, false, true);
        [$exit] = $this->execute($failed, [], $receipt);
        self::assertSame(1, $exit);
        self::assertFileExists($receipt);
        self::assertSame('', file_get_contents($receipt));
    }

    /**
     * Verifies real self-execution, configuration preservation and update activation.
     *
     * @return void
     */
    public function testExecutableFirstInstallAndUpdateUseOnlyNewFixtureRuntime(): void
    {
        $first = $this->prepare('v1', 'attempt-1');
        $copy = $this->root . DIRECTORY_SEPARATOR . 'copied package.phx';
        copy($first, $copy);
        unlink($first);
        [$exit, $output] = $this->execute($copy);
        self::assertSame(0, $exit, $output);
        $current = $this->host . DIRECTORY_SEPARATOR . 'current';
        self::assertSame('v1', file_get_contents($current . DIRECTORY_SEPARATOR . 'installed-version'));
        $old = realpath($current);
        $second = $this->prepare('v2', 'attempt-2', 'v1');
        [$exit, $output] = $this->execute($second);
        self::assertSame(0, $exit, $output);
        clearstatcache(true);
        self::assertNotSame($old, realpath($current));
        self::assertDirectoryExists($old);
        self::assertSame('v2', file_get_contents($current . DIRECTORY_SEPARATOR . 'installed-version'));
        self::assertFileExists($current . DIRECTORY_SEPARATOR . 'core-runner-used');
    }

    /**
     * Corruption must fail before any host mutation; a new attempt never overwrites another.
     *
     * @return void
     */
    public function testCorruptedPayloadFailsBeforeHostMutationAndArtifactsRemainAttemptOwned(): void
    {
        $first = $this->prepare('v1', 'attempt-1');
        $second = $this->prepare('v2', 'attempt-2');
        self::assertNotSame($first, $second);
        file_put_contents($first, 'corruption', FILE_APPEND);
        [$exit, $output] = $this->execute($first);
        self::assertSame(1, $exit, $output);
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        self::assertFileExists($second);
    }

    /**
     * Invalid command-line inputs cannot accidentally install a package.
     *
     * @return void
     */
    public function testInvalidArgumentsAndUnmanagedActivationAreRejected(): void
    {
        $artifact = $this->prepare('v1', 'attempt-1');
        [$exit] = $this->execute($artifact, ['--unexpected']);
        self::assertSame(1, $exit);
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        mkdir($this->host . DIRECTORY_SEPARATOR . 'current');
        file_put_contents($this->host . DIRECTORY_SEPARATOR . 'current' . DIRECTORY_SEPARATOR . 'keep.txt', 'untouched');
        [$exit] = $this->execute($artifact);
        self::assertSame(1, $exit);
        self::assertSame('untouched', file_get_contents($this->host . DIRECTORY_SEPARATOR . 'current' . DIRECTORY_SEPARATOR . 'keep.txt'));
    }

    /**
     * The actual PackageManager process adapter must handle spaces without shell parsing.
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\RequiresMethod(\axenox\PackageManager\Common\Updater\UpdateDownloader::class, 'isNativeOta')]
    public function testPackageManagerRunnerExecutesCopiedArtifactWithSpaces(): void
    {
        $artifact = $this->prepare('v1', 'attempt-1', null, false, false, 'unusable-relative-root');
        $copy = $this->root . DIRECTORY_SEPARATOR . 'copied package.phx';
        copy($artifact, $copy);
        $installer = new \axenox\PackageManager\Common\Updater\SelfUpdateInstaller($copy, $this->root, PHP_BINARY, $this->host);
        $log = implode('', iterator_to_array($installer->install()));
        self::assertTrue($installer->getInstallationSuccess(), $log);
        self::assertSame('v1', file_get_contents($this->host . DIRECTORY_SEPARATOR . 'current'
            . DIRECTORY_SEPARATOR . 'installed-version'));
    }

    /**
     * Attempt IDs are exclusive: retries cannot silently replace a published package.
     *
     * @return void
     */
    public function testDuplicateAttemptCannotOverwritePublishedPackage(): void
    {
        $artifact = $this->prepare('v1', 'attempt-1');
        $hash = hash_file('sha256', $artifact);
        try {
            $this->prepare('v2', 'attempt-1');
            self::fail('Duplicate attempt overwrote the published artifact.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('already has an artifact', $error->getMessage());
        }
        self::assertSame($hash, hash_file('sha256', $artifact));
        self::assertCount(0, glob(dirname($artifact) . DIRECTORY_SEPARATOR . '*.tar.gz'));
    }

    /**
     * Polling is the same executable pull artifact, not scheduler execution.
     *
     * @return void
     */
    public function testPollingSpecializationPreparesWithoutChangingHostOrSchedulingJobs(): void
    {
        $artifact = $this->prepare('v1', 'polling-1', null, true, false, 'unusable-relative-root');
        self::assertFileExists($artifact);
        self::assertDirectoryDoesNotExist($this->host . DIRECTORY_SEPARATOR . '.native-installer');
        self::assertSame([], iterator_to_array(new \FilesystemIterator($this->host)));
    }
}
