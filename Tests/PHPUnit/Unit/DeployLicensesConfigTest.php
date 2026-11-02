<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Deploy;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\UxonSyntaxError;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Protects project license defaults and host overrides in the PHX configuration payload.
 */
class DeployLicensesConfigTest extends TestCase
{
    /**
     * Exercises the local merge without persisted projects or deployment side effects.
     * 
     * @param array $hostConfig
     * @param string|null $projectJson
     * @return array
     */
    private function merge(array $hostConfig, ?string $projectJson) : array
    {
        $action = ActionFactory::createFromString(new Workbench(), Deploy::class);
        $method = new \ReflectionMethod(Deploy::class, 'mergeProjectLicensesConfig');
        $method->setAccessible(true);
        return $method->invoke($action, $hostConfig, $projectJson);
    }

    /**
     * Host overrides must preserve nonconflicting project terms and unrelated host configuration.
     * 
     * @return void
     */
    public function testHostOverridesExtendProjectLicensesRecursively() : void
    {
        $projectJson = '{"licenses":{"Commercial":{"license_text":"Project terms","license_link":"https://example.com/license"}},"packages":[{"name":"example/library","license_used":"Commercial"}]}';
        $host = ['default_app_config' => [
            'Licenses.config.json' => ['licenses' => ['Commercial' => ['license_text' => 'Host terms']]],
            'System.config.json' => ['SERVER.INSTALLATION_NAME' => 'example']
        ]];
        $merged = $this->merge($host, $projectJson);
        $licenses = $merged['default_app_config']['Licenses.config.json'];
        self::assertSame('Host terms', $licenses['licenses']['Commercial']['license_text']);
        self::assertSame('https://example.com/license', $licenses['licenses']['Commercial']['license_link']);
        self::assertSame([['name' => 'example/library', 'license_used' => 'Commercial']], $licenses['packages']);
        self::assertSame($host['default_app_config']['System.config.json'], $merged['default_app_config']['System.config.json']);
        self::assertSame($merged, $this->merge($host, $projectJson));
        self::assertArrayNotHasKey('packages', $host['default_app_config']['Licenses.config.json']);
    }

    /**
     * Projects must supply license configuration even when the host has none.
     * 
     * @return void
     */
    public function testProjectOnlyConfiguration() : void
    {
        $project = ['licenses' => ['Commercial' => ['license_text' => 'Project terms']]];
        self::assertSame(
            ['default_app_config' => ['Licenses.config.json' => $project]],
            $this->merge([], json_encode($project))
        );
    }

    /**
     * Existing host-only deployments must remain unchanged when project settings are absent.
     * 
     * @return void
     */
    public function testMissingProjectConfigurationPreservesHostConfiguration() : void
    {
        $host = ['default_app_config' => ['Licenses.config.json' => ['packages' => []]]];
        foreach ([null, '', '  '] as $projectJson) {
            self::assertSame($host, $this->merge($host, $projectJson));
            self::assertSame([], $this->merge([], $projectJson));
        }
    }

    /**
     * Malformed project terms must stop packaging instead of deploying host-only terms silently.
     * 
     * @return void
     */
    public function testInvalidProjectConfigurationIsRejected() : void
    {
        $this->expectException(UxonSyntaxError::class);
        $this->merge([], '{invalid');
    }
}