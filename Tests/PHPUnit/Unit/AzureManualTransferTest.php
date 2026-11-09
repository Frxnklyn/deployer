<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Pipelines\Deploy\AzureManualTransfer;
use axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real private package preparation only; no Azure requests or host installations.
 */
class AzureManualTransferTest extends TestCase
{
    private string $root;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = NativeTestWorkspace::create('azure');
        mkdir($this->root . DIRECTORY_SEPARATOR . 'builds');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'fixture.txt', 'fixture');
        NativeBuildFiles::command('tar', ['-czf', $this->root . DIRECTORY_SEPARATOR . 'builds'
            . DIRECTORY_SEPARATOR . 'v1.tar.gz', '--', 'fixture.txt'], $this->root, 30);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        NativeBuildFiles::remove($this->root);
    }

    /**
     * @return AzureManualTransfer
     */
    private function pipeline(): AzureManualTransfer
    {
        return (new AzureManualTransfer($this->createStub(WorkbenchInterface::class)))
            ->setHostOperatingSystem('Linux')
            ->setHostPathToInstallationRoot('/home/native-app')
            ->setHostPhpExecutable('php');
    }

    /**
     * Workstation OS controls instructions independently of Linux target inputs.
     *
     * @return void
     */
    public function testOperatorInstructionsAndExecutablePackageDoNotClaimAzureAutomation(): void
    {
        foreach (['Windows', 'Unix'] as $os) {
            $pipeline = $this->pipeline();
            $pipeline->importUxonObject(new UxonObject(['app_service_name' => 'example-app', 'operator_os' => $os]));
            $before = $pipeline->exportUxonObject()->toJson();
            $run = $pipeline->run(new ReleaseDeploymentTask('azure-' . $os, 'host', 'build', 'v1', $this->root));
            iterator_to_array($run);
            $result = $run->getReturn();
            self::assertSame(60, $result->getStatus());
            self::assertFileExists($result->artifactPath);
            self::assertStringEndsWith('.phx', $result->artifactPath);
            self::assertSame(hash_file('sha256', $result->artifactPath), $result->sha256);
            self::assertStringContainsString('https://example-app.scm.azurewebsites.net/api/zipdeploy', $result->instructions);
            self::assertStringContainsString('no Azure request has been sent', $result->instructions);
            self::assertStringContainsString('No automatic status callback', $result->instructions);
            self::assertStringContainsString('database changes are not rolled back', $result->instructions);
            self::assertStringNotContainsString('--insecure', $result->instructions);
            self::assertStringContainsString($os === 'Windows' ? 'Compress-Archive -LiteralPath' : "zip '", $result->instructions);
            self::assertStringContainsString($os === 'Windows' ? 'curl.exe' : 'curl --fail-with-body', $result->instructions);
            self::assertSame($before, $pipeline->exportUxonObject()->toJson());
            $result->discard();
        }
    }

    /**
     * Names cannot inject credentials, endpoints, path segments or command fragments.
     *
     * @return void
     */
    public function testInvalidNamesAndMissingRequiredNameFailBeforePackagePreparation(): void
    {
        foreach (['a', '-app', 'app-', 'app.example', 'https://example.invalid', 'app;command', str_repeat('a', 61)] as $name) {
            try {
                $this->pipeline()->setAppServiceName($name);
                self::fail('Unsafe Azure name accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('app_service_name', $error->getMessage());
            }
        }
        try {
            $this->pipeline()->run(new ReleaseDeploymentTask('attempt', 'host', 'build', 'v1', $this->root))->current();
            self::fail('Missing Azure name accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . '.native-deployments');
        }
    }
}
