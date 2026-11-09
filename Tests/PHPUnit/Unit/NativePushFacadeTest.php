<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\PackageManager\Facades\UpdaterFacade;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\ConfigurationInterface;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Security\SecurityManagerInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Guard-only checks never construct a receiver or execute an uploaded package.
 */
#[\PHPUnit\Framework\Attributes\RequiresMethod(UpdaterFacade::class, 'createNativePushResponse')]
class NativePushFacadeTest extends TestCase
{
    /**
     * @return iterable
     */
    public static function deniedRequests(): iterable
    {
        yield 'disabled' => [false, false, 'deployer', ['deployer'], 'https', 403];
        yield 'anonymous' => [true, true, 'deployer', ['deployer'], 'https', 403];
        yield 'unlisted' => [true, false, 'other', ['deployer'], 'https', 403];
        yield 'TLS required after allowlist' => [true, false, 'deployer', ['deployer'], 'http', 400];
    }

    /**
     * @dataProvider deniedRequests
     * @param bool $enabled
     * @param bool $anonymous
     * @param string $username
     * @param array $users
     * @param string $scheme
     * @param int $expected
     * @return void
     */
    public function testExplicitOptInPrincipalAndTlsGuard(bool $enabled, bool $anonymous, string $username,
        array $users, string $scheme, int $expected): void
    {
        $config = $this->createStub(ConfigurationInterface::class);
        $config->method('getOption')->willReturnCallback(fn ($key) => [
            'UPDATER.NATIVE_PUSH.ENABLED' => $enabled,
            'UPDATER.NATIVE_PUSH.ALLOWED_USERS' => new UxonObject($users)
        ][$key] ?? null);
        $token = $this->createStub(AuthenticationTokenInterface::class);
        $token->method('isAnonymous')->willReturn($anonymous);
        $token->method('getUsername')->willReturn($username);
        $security = $this->createStub(SecurityManagerInterface::class);
        $security->method('getAuthenticatedToken')->willReturn($token);
        $workbench = $this->createStub(WorkbenchInterface::class);
        $workbench->method('getSecurity')->willReturn($security);
        $workbench->method('getInstallationPath')->willReturn('must-not-be-accessed');
        $app = $this->createStub(AppInterface::class);
        $app->method('getConfig')->willReturn($config);
        $facade = $this->getMockBuilder(UpdaterFacade::class)->disableOriginalConstructor()
            ->onlyMethods(['getWorkbench', 'getApp'])->getMock();
        $facade->method('getWorkbench')->willReturn($workbench);
        $facade->method('getApp')->willReturn($app);
        $response = (new \ReflectionMethod(UpdaterFacade::class, 'createNativePushResponse'))
            ->invoke($facade, new ServerRequest('POST', $scheme . '://fixture.invalid/api/updater/native-push'));
        self::assertSame($expected, $response->getStatusCode());
        self::assertSame(90, json_decode((string) $response->getBody(), true)['status']);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }
}
