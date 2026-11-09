<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Facades\DeployerFacade;
use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Common\Pipelines\NativeOtaPackage;
use axenox\Deployer\Pipelines\Deploy\UpdaterPull;
use axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\ConfigurationInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use exface\Core\CommonLogic\Filemanager;
use example\Native\Pipelines\Deploy\HttpCapability;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'HttpCapability.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'ConfigurationOnly.php';

/**
 * Exercises OTA response/callback boundaries against in-memory records only.
 */
class NativeOtaFacadeTest extends TestCase
{
    private const ID = '0x0123456789abcdef0123456789abcdef';
    private string $root;
    private array $row;
    private int $updates;
    private array $conditions = [];
    private bool $conflict = false;
    private array $host = [];
    private bool $empty = false;
    private int $redeployments = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        require_once dirname((new \ReflectionClass(WorkbenchInterface::class))->getFileName(), 2)
            . DIRECTORY_SEPARATOR . 'CommonLogic' . DIRECTORY_SEPARATOR . 'Constants.php';
        $this->root = NativeTestWorkspace::create('ota-facade');
        mkdir($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project', 0700, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . '.native-deployments');
        $this->row = ['uid' => self::ID, 'deploy_recipe_file' => UpdaterPull::class,
            'status' => 60, 'log' => 'prepared', 'build__name' => 'v1', 'host__name' => 'fixture'];
        $this->updates = 0;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        NativeBuildFiles::remove($this->root);
    }

    /**
     * @return DeployerFacade
     */
    private function facade(): DeployerFacade
    {
        $sheet = $this->createMock(DataSheetInterface::class);
        $filters = $this->createMock(\exface\Core\Interfaces\Model\ConditionGroupInterface::class);
        $filters->method('addConditionFromString')->willReturnCallback(function ($alias, $value, $comparator) use ($filters) {
            self::assertContains($alias, ['uid', 'status', 'log']);
            if ($alias === 'uid') {
                self::assertSame(self::ID, $value);
            }
            self::assertSame(\exface\Core\DataTypes\ComparatorDataType::EQUALS, $comparator);
            $this->conditions[$alias] = $value;
            return $filters;
        });
        $sheet->method('getFilters')->willReturn($filters);
        $filters->method('addConditionForAttributeIsNull')->willReturnCallback(function ($attribute) use ($filters) {
            $this->conditions[$attribute] = null;
            return $filters;
        });
        $sheet->method('isEmpty')->willReturnCallback(fn () => $this->empty);
        $sheet->method('getCellValue')->willReturnCallback(fn ($name, $row) => $this->row[$name] ?? null);
        $sheet->method('setCellValue')->willReturnCallback(function ($name, $row, $value) use ($sheet) {
            $this->row[$name] = $value;
            return $sheet;
        });
        $sheet->method('extractSystemColumns')->willReturn($sheet);
        $sheet->method('dataUpdate')->willReturnCallback(function (...$arguments): int {
            $this->updates++;
            return $this->conflict ? 0 : 1;
        });
        $workbench = $this->createStub(WorkbenchInterface::class);
        $workbench->method('getInstallationPath')->willReturn($this->root);
        $filemanager = $this->createStub(Filemanager::class);
        $filemanager->method('getPathToVendorFolder')->willReturn(dirname(__DIR__, 5));
        $workbench->method('filemanager')->willReturn($filemanager);
        $app = $this->createStub(AppInterface::class);
        $workbench->method('getApp')->willReturn($app);
        $app->method('get')->willReturnCallback(static function ($selector, $selectorClass, array $arguments) {
            $class = ltrim(str_replace('/', '\\', preg_replace('/\.php$/', '', $selector->toString())), '\\');
            return new $class(...$arguments);
        });
        $host = $this->createMock(DataSheetInterface::class);
        $host->method('getCellValue')->willReturnCallback(fn ($attribute, $row) => $this->host[$attribute] ?? [
            'prototype_file' => $this->row['deploy_recipe_file'] === 'Recipes/Deploy/LocalBldUpdaterPull.php'
                ? '' : $this->row['deploy_recipe_file'],
            'deploy_config' => '{}', 'path_abs_to_api' => 'C:\\private\\installation',
            'php_cli' => 'php', 'operating_system' => 'windows'
        ][$attribute] ?? null);
        $config = $this->createStub(ConfigurationInterface::class);
        $config->method('getOption')->willReturnCallback(fn ($option) => $option === 'PROJECTS_FOLDER_RELATIVE_TO_BASE' ? 'projects' : 900);
        $app->method('getConfig')->willReturn($config);
        $facade = $this->getMockBuilder(DeployerFacade::class)->disableOriginalConstructor()->onlyMethods([
            'createDeploymentPublishedSheet', 'createDeploymentSheet', 'createHostSheet', 'getHostAttributeAliases',
            'getWorkbench', 'getApp', 'buildHeadersCommon', 'redeploy'
        ])->getMock();
        $facade->method('getWorkbench')->willReturn($workbench);
        $facade->method('getApp')->willReturn($app);
        $facade->method('buildHeadersCommon')->willReturn([]);
        $facade->method('createDeploymentPublishedSheet')->willReturn($sheet);
        $facade->method('createDeploymentSheet')->willReturn($sheet);
        $facade->method('createHostSheet')->willReturn($host);
        $facade->method('getHostAttributeAliases')->willReturn([
            'prototypefile', 'deployconfig', 'pathabstoapi', 'phpcli', 'operatingsystem', 'dataconnection', 'name'
        ]);
        $facade->method('redeploy')->willReturnCallback(function () {
            $this->redeployments++;
            $this->empty = false;
        });
        return $facade;
    }

    private function download(string $protocol = '1', bool $secure = true, array $query = []): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest('GET', ($secure ? 'https' : 'http') . '://fixture.invalid/api/deployer/ota/project/host'))
            ->withHeader('X-Deployer-Native-OTA', $protocol)->withQueryParams($query);
        return (new \ReflectionMethod(DeployerFacade::class, 'createResponseForOTA'))
            ->invoke($this->facade(), 'project', 'host', $request);
    }

    /**
     * @param array $query
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function postCallback(array $query): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest('POST', 'https://fixture.invalid/ota'))
            ->withQueryParams($query)->withBody(\GuzzleHttp\Psr7\Utils::streamFor('fixture callback'));
        return (new \ReflectionMethod(DeployerFacade::class, 'createResponseForLog'))
            ->invoke($this->facade(), 'project', 'host', $request);
    }

    /**
     * @return void
     */
    public function testOlderClientsAreRejectedBeforeNativeDownloadOrStatusMutation(): void
    {
        $response = $this->download('');
        self::assertSame(426, $response->getStatusCode());
        self::assertSame(60, $this->row['status']);
        self::assertSame(0, $this->updates);
    }

    /**
     * @return void
     */
    public function testNativeDownloadStreamsOnlyAttemptArtifactWithIntegrityAndNoCacheHeaders(): void
    {
        $path = NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project', self::ID);
        file_put_contents($path, 'fixture-executable');
        $response = $this->download();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::ID, $response->getHeaderLine('X-Deployer-Deployment'));
        self::assertSame(hash_file('sha256', $path), $response->getHeaderLine('X-Deployer-SHA256'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('fixture-executable', (string) $response->getBody());
        $response->getBody()->close();
        self::assertSame(62, $this->row['status']);
        self::assertArrayNotHasKey('completed_on', $this->row);
    }

    /**
     * @return void
     */
    public function testNativeCallbacksRequireAttemptAndExplicitFinalStatus(): void
    {
        self::assertSame(400, $this->postCallback(['status' => '99', 'final' => 'true'])->getStatusCode());
        self::assertSame(400, $this->postCallback(['deployment' => '../escape'])->getStatusCode());
        self::assertSame(400, $this->postCallback(['deployment' => self::ID, 'final' => 'true'])->getStatusCode());
        self::assertSame(400, $this->postCallback(['deployment' => self::ID, 'status' => '99'])->getStatusCode());
        self::assertSame(0, $this->updates);
        self::assertSame(60, $this->row['status']);
    }

    /**
     * Cleartext requests must be rejected before secret-bearing bytes are served.
     *
     * @return void
     */
    public function testNativeDownloadRejectsCleartextBeforeOpeningArtifact(): void
    {
        $response = $this->download('1', false);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->updates);
        self::assertSame(60, $this->row['status']);
        $request = (new ServerRequest('POST', 'http://fixture.invalid/ota'))
            ->withQueryParams(['deployment' => self::ID, 'status' => '99', 'final' => 'true']);
        $callbackResponse = (new \ReflectionMethod(DeployerFacade::class, 'createResponseForLog'))
            ->invoke($this->facade(), 'project', 'host', $request);
        self::assertSame(400, $callbackResponse->getStatusCode());
        self::assertSame(0, $this->updates);
    }

    /**
     * @return void
     */
    public function testDownloadOnlyDoesNotBecomeInstalledAndTerminalAttemptsCannotBeOverwritten(): void
    {
        self::assertSame(200, $this->postCallback(['deployment' => self::ID, 'status' => '67', 'final' => 'true'])->getStatusCode());
        self::assertSame(67, $this->row['status']);
        self::assertArrayNotHasKey('completed_on', $this->row);
        self::assertSame(409, $this->postCallback(['deployment' => self::ID, 'status' => '62'])->getStatusCode());
        self::assertSame(200, $this->postCallback(['deployment' => self::ID, 'status' => '99', 'final' => 'true'])->getStatusCode());
        self::assertSame(99, $this->row['status']);
        self::assertArrayHasKey('completed_on', $this->row);
        self::assertSame(409, $this->postCallback(['deployment' => self::ID, 'status' => '90', 'final' => 'true'])->getStatusCode());
    }

    /**
     * @return void
     */
    public function testLegacyCallbackStillWorksWithoutAttemptHeaders(): void
    {
        $this->row['deploy_recipe_file'] = 'Recipes/Deploy/LocalBldUpdaterPull.php';
        self::assertSame(200, $this->postCallback(['status' => '99', 'final' => 'true'])->getStatusCode());
        self::assertSame('99', $this->row['status']);
        self::assertArrayHasKey('completed_on', $this->row);
    }

    /**
     * A stale callback must not silently overwrite a concurrently updated log or state.
     *
     * @return void
     */
    public function testNativeCallbacksCompareReadStateAndLogAndReportConcurrentWriteConflict(): void
    {
        $this->conflict = true;
        $response = $this->postCallback(['deployment' => self::ID, 'status' => '67']);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['uid' => self::ID, 'status' => 60, 'log' => 'prepared'], $this->conditions);
    }

    /**
     * The polling specialization must remain visible to the same attempt-bound protocol.
     *
     * @return void
     */
    public function testPollingPrototypeUsesNativeClientGate(): void
    {
        foreach ([
            \axenox\Deployer\Pipelines\Deploy\UpdaterPolling::class,
            'axenox/deployer/Pipelines/Deploy/UpdaterPolling.php'
        ] as $prototype) {
            $this->row['deploy_recipe_file'] = $prototype;
            $response = $this->download('');
            self::assertSame(426, $response->getStatusCode());
        }
    }

    /**
     * A stale legacy .phx or callback must never be reused for a manual native attempt.
     *
     * @return void
     */
    public function testManualPrototypesNeverFallBackToLegacyOtaDownloadsOrCallbacks(): void
    {
        foreach ([
            \axenox\Deployer\Pipelines\Deploy\ManualTransfer::class,
            \axenox\Deployer\Pipelines\Deploy\AzureManualTransfer::class
        ] as $prototype) {
            $this->row['deploy_recipe_file'] = $prototype;
            $response = $this->download();
            self::assertSame(409, $response->getStatusCode());
            self::assertSame(409, $this->postCallback(['final' => 'true', 'status' => '99'])->getStatusCode());
            self::assertSame(0, $this->updates);
        }
    }

    public function testHostSelectionDelegatesCustomCapabilityWithConfigurationAndSettersEvenWithoutPublication(): void
    {
        $this->empty = true;
        $this->host = ['prototype_file' => HttpCapability::class, 'deploy_config' => '{"message":"host-selected"}'];
        self::assertSame(202, $this->download('', false, ['redeploy' => 'true'])->getStatusCode());
        self::assertSame(['GET', 'host-selected', 'C:\\private\\installation', 'php', 'windows'], HttpCapability::$received);
        self::assertSame(204, $this->postCallback([])->getStatusCode());
        self::assertSame('POST', HttpCapability::$received[0]);
        self::assertSame(0, $this->updates);
        self::assertSame(0, $this->redeployments);
    }

    public function testHostSelectionIsAuthoritativeRatherThanRecordedPrototype(): void
    {
        $this->host = ['prototype_file' => \axenox\Deployer\Pipelines\Deploy\ManualTransfer::class];
        self::assertSame(409, $this->download()->getStatusCode());
        self::assertSame(409, $this->postCallback(['deployment' => self::ID])->getStatusCode());
        $this->host = ['prototype_file' => HttpCapability::class];
        $this->row['deploy_recipe_file'] = \axenox\Deployer\Pipelines\Deploy\ManualTransfer::class;
        self::assertSame(202, $this->download()->getStatusCode());
        self::assertSame(0, $this->updates);
    }

    public function testPipelineWithoutHttpCapabilityCannotFallBackToLegacy(): void
    {
        $this->host = ['prototype_file' => \example\Native\Pipelines\Deploy\ConfigurationOnly::class];
        self::assertSame(409, $this->download()->getStatusCode());
        self::assertSame(409, $this->postCallback([])->getStatusCode());
        self::assertSame(0, $this->updates);
    }

    public function testFacadeDoesNotClassifyNativeSelectorsOrProtocols(): void
    {
        $source = file_get_contents((new \ReflectionClass(DeployerFacade::class))->getFileName());
        foreach (['NativeOtaPackage', 'isManualPrototype', 'isPrototype', 'X-Deployer-Native-OTA', 'X-Deployer-SHA256', '.phx'] as $coupling) {
            self::assertStringNotContainsString($coupling, $source);
        }
        self::assertStringContainsString('DeploymentHttpHandlerInterface', $source);
        self::assertStringContainsString('AuthenticationMiddleware', $source);
    }

    public function testHostAttributesCannotBeInjectedThroughPipelineUxon(): void
    {
        $this->host = ['prototype_file' => HttpCapability::class, 'deploy_config' => '{"php_cli":"forbidden"}'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Host attributes cannot be configured through pipeline UXON.');
        $this->download();
    }

    public function testNativePullPreservesExplicitRedeployCompatibility(): void
    {
        $this->empty = true;
        self::assertSame(304, $this->download()->getStatusCode());
        self::assertSame(0, $this->redeployments);
        $path = NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project', self::ID);
        file_put_contents($path, 'prepared-after-explicit-redeploy');
        $response = $this->download('1', true, ['redeploy' => 'true']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('prepared-after-explicit-redeploy', (string) $response->getBody());
        $response->getBody()->close();
        self::assertSame(1, $this->redeployments);
        self::assertSame(62, $this->row['status']);
        self::assertSame(1, $this->updates);
    }

    public function testNativeRedeployRejectsInsecureAndLegacyClientsBeforePreparing(): void
    {
        $this->empty = true;
        self::assertSame(400, $this->download('1', false, ['redeploy' => 'true'])->getStatusCode());
        self::assertSame(426, $this->download('', true, ['redeploy' => 'true'])->getStatusCode());
        self::assertSame(0, $this->redeployments);
        self::assertSame(0, $this->updates);
    }

    public function testNativeDownloadConcurrentStateChangeIsReportedWithoutServingBytes(): void
    {
        $path = NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project', self::ID);
        file_put_contents($path, 'fixture-executable');
        $this->conflict = true;
        $response = $this->download();
        self::assertSame(409, $response->getStatusCode());
        self::assertNotSame('fixture-executable', (string) $response->getBody());
        self::assertSame(['status' => 60], $this->conditions);
    }

    public function testUnconfiguredHostCannotBypassNativeGuards(): void
    {
        $this->host = ['prototype_file' => ''];
        self::assertSame(409, $this->download('', false)->getStatusCode());
        self::assertSame(409, $this->postCallback([])->getStatusCode());
        self::assertSame(0, $this->updates);
    }

    public function testLegacyDownloadsAndExplicitRedeployCompatibilityRemainAvailable(): void
    {
        $this->row['deploy_recipe_file'] = 'Recipes/Deploy/LocalBldUpdaterPull.php';
        mkdir($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'builds');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project'
            . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . 'v1_fixture.phx', 'legacy-executable');
        $this->empty = true;
        self::assertSame(304, $this->download('', false)->getStatusCode());
        self::assertSame(0, $this->redeployments);
        $response = $this->download('', false, ['redeploy' => 'true']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('legacy-executable', (string) $response->getBody());
        $response->getBody()->close();
        self::assertSame(1, $this->redeployments);
        self::assertSame(62, $this->row['status']);
    }

    public function testMigratedPullHostStillAcceptsHistoricalLegacyCallbacks(): void
    {
        $this->host = ['prototype_file' => UpdaterPull::class];
        $this->row['deploy_recipe_file'] = 'Recipes/Deploy/LocalBldUpdaterPull.php';
        self::assertSame(200, $this->postCallback(['final' => 'true', 'status' => '99'])->getStatusCode());
        self::assertSame('99', $this->row['status']);
    }

    public function testNativeCallbackComparesNullLogsAndRejectsUnknownStatuses(): void
    {
        foreach (['61', '100', ['99']] as $status) {
            self::assertSame(400, $this->postCallback(['deployment' => self::ID, 'status' => $status])->getStatusCode());
        }
        self::assertSame(0, $this->updates);
        $this->row['log'] = null;
        self::assertSame(200, $this->postCallback(['deployment' => self::ID, 'status' => '67'])->getStatusCode());
        self::assertSame(['uid' => self::ID, 'status' => 60, 'log' => null], $this->conditions);
    }

    public function testRepeatedNativeDownloadDoesNotRegressInstallationState(): void
    {
        $path = NativeOtaPackage::path($this->root . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . 'project', self::ID);
        file_put_contents($path, 'fixture-executable');
        $this->row['status'] = 73;
        $response = $this->download();
        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->close();
        self::assertSame(73, $this->row['status']);
        self::assertSame(0, $this->updates);
    }

    public function testNativeDownloadRetainsProjectPathTraversalGuard(): void
    {
        $request = (new ServerRequest('GET', 'https://fixture.invalid/ota'))->withHeader('X-Deployer-Native-OTA', '1');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project path must remain below the installation base.');
        (new \ReflectionMethod(DeployerFacade::class, 'createResponseForOTA'))
            ->invoke($this->facade(), '..\\project', 'host', $request);
    }

    public function testRouteDelegatesTheOriginalRequestAndDecodedHostSelection(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $request = new ServerRequest($method, 'https://fixture.invalid/api/deployer/ota/project%2Dalias/host%2Duid');
            $facade = $this->getMockBuilder(DeployerFacade::class)->disableOriginalConstructor()
                ->onlyMethods(['createResponseForDeployment'])->getMock();
            $facade->expects(self::once())->method('createResponseForDeployment')
                ->with('project-alias', 'host-uid', self::identicalTo($request))
                ->willReturn(new \GuzzleHttp\Psr7\Response(202));
            self::assertSame(202, (new \ReflectionMethod(DeployerFacade::class, 'createResponse'))->invoke($facade, $request)->getStatusCode());
        }
    }

    public function testHttpLifecycleReporterCannotChangeActionOwnedIdentity(): void
    {
        $context = (new \ReflectionMethod(DeployerFacade::class, 'createDeploymentHttpContext'))
            ->invoke($this->facade(), 'project', 'host');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP lifecycle reports cannot change deployment identity or configuration.');
        $context->report(['uid' => 'replacement']);
    }

    public function testManualHostWithNoPublicationStillReportsNoUpdateWithoutFreshRun(): void
    {
        $this->empty = true;
        $this->host = ['prototype_file' => \axenox\Deployer\Pipelines\Deploy\ManualTransfer::class];
        self::assertSame(304, $this->download('1', true, ['redeploy' => 'true'])->getStatusCode());
        self::assertSame(0, $this->updates);
        self::assertSame(0, $this->redeployments);
    }
}
