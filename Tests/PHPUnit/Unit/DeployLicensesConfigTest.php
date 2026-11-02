<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Deploy;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\UxonSyntaxError;
use exface\Core\Exceptions\UxonMapError;
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
     * Project configuration must not gain result-import privileges during deployment merging.
     *
     * @return void
     */
    public function testProjectPackageConfigurationRejectsRecordedEvidence() : void
    {
        $this->expectException(UxonMapError::class);
        $this->merge([], json_encode(['packages' => [[
            'name' => 'example/library', 'licenses' => [['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]]
        ]]], JSON_THROW_ON_ERROR));
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
        self::assertSame('Host terms', $licenses['licenses'][0]['text']);
        self::assertSame('https://example.com/license', $licenses['licenses'][0]['link']);
        self::assertCount(1, $licenses['packages']);
        self::assertSame('example/library', $licenses['packages'][0]['name']);
        self::assertSame('Commercial', $licenses['packages'][0]['license_used']);
        self::assertSame('Host terms', $licenses['packages'][0]['licenses'][0]['text']);
        self::assertArrayNotHasKey('source', $licenses['licenses'][0]);
        self::assertArrayNotHasKey('text_available', $licenses['licenses'][0]);
        self::assertArrayNotHasKey('source', $licenses['packages'][0]['licenses'][0]);
        self::assertArrayNotHasKey('text_available', $licenses['packages'][0]['licenses'][0]);
        self::assertArrayNotHasKey('urn', $licenses['packages'][0]);
        $inventory = new LicenseBOM();
        $inventory->importUxonObject(new UxonObject($licenses));
        self::assertTrue($inventory->getPackages()['composer:example/library:']->hasLicenseText());
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
            ['default_app_config' => ['Licenses.config.json' => ['packages' => [], 'licenses' => [[
                'name' => 'Commercial', 'text' => 'Project terms'
            ]]]]],
            $this->merge([], json_encode($project))
        );
    }

    /**
     * Array positions must not associate one license's terms with another package or license.
     *
     * @return void
     */
    public function testCanonicalDefinitionsMergeByNameRatherThanPosition() : void
    {
        $project = [
            'licenses' => [
                ['name' => 'MIT', 'text' => 'MIT terms'],
                ['name' => 'Commercial', 'text' => 'Project terms', 'link' => 'https://example.org/license']
            ],
            'packages' => [
                ['name' => 'example/first', 'license_used' => 'MIT'],
                ['name' => 'example/second', 'license_used' => 'Commercial']
            ]
        ];
        $host = ['default_app_config' => ['Licenses.config.json' => [
            'licenses' => [['name' => 'Commercial', 'text' => 'Host terms']],
            'packages' => [['name' => 'example/second', 'ecosystem' => 'private']]
        ]]];
        $config = $this->merge($host, json_encode($project))['default_app_config']['Licenses.config.json'];
        $licenses = array_column($config['licenses'], null, 'name');
        $packages = array_column($config['packages'], null, 'name');
        self::assertSame('MIT terms', $licenses['MIT']['text']);
        self::assertSame('Host terms', $licenses['Commercial']['text']);
        self::assertSame('https://example.org/license', $licenses['Commercial']['link']);
        self::assertSame('MIT', $packages['example/first']['license_used']);
        self::assertSame('Commercial', $packages['example/second']['license_used']);
        self::assertSame('private', $packages['example/second']['ecosystem']);
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