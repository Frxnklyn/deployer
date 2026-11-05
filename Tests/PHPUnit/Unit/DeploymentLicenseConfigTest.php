<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\DeploymentLicenseConfig;
use PHPUnit\Framework\TestCase;

/**
 * Native configuration merging is independent of historical BOM runtime APIs.
 *
 * @author Brooklyn Fränzschky
 */
class DeploymentLicenseConfigTest extends TestCase
{
    /**
     * @return void
     */
    public function testDefinitionsAndPackagesMatchIdentityRatherThanArrayPositions(): void
    {
        $project = [
            'licenses' => [
                ['name' => 'MIT', 'text' => 'MIT terms'],
                ['name' => 'Commercial', 'text' => 'Project terms', 'link' => 'https://example.invalid/license']
            ],
            'packages' => [
                ['name' => 'example/one', 'license_used' => 'MIT'],
                ['name' => 'example/two', 'license_used' => 'Commercial', 'license_text' => ['MIT' => 'Keep terms']]
            ]
        ];
        $host = [
            'System.config.json' => ['keep' => true],
            'Licenses.config.json' => [
                'licenses' => [['name' => 'Commercial', 'text' => 'Host terms']],
                'packages' => [['name' => 'example/two', 'license_text' => ['Commercial' => 'Package terms']]]
            ]
        ];
        $before = $host;
        $merged = DeploymentLicenseConfig::merge($host, json_encode($project));
        self::assertSame($before, $host);
        self::assertSame(['keep' => true], $merged['System.config.json']);
        $config = $merged['Licenses.config.json'];
        self::assertSame('MIT terms', $config['licenses']['MIT']['license_text']);
        self::assertSame('Host terms', $config['licenses']['Commercial']['license_text']);
        self::assertSame('https://example.invalid/license', $config['licenses']['Commercial']['license_link']);
        self::assertCount(2, $config['packages']);
        $packages = array_column($config['packages'], null, 'name');
        self::assertSame('Commercial', $packages['example/two']['license_used']);
        self::assertSame(['MIT' => 'Keep terms', 'Commercial' => 'Package terms'], $packages['example/two']['license_text']);
    }

    /**
     * @return void
     */
    public function testAbsentProjectAndPackageOnlyListsRemainSupported(): void
    {
        $host = ['Licenses.config.json' => ['packages' => []]];
        foreach ([null, '', '  '] as $project) {
            self::assertSame($host, DeploymentLicenseConfig::merge($host, $project));
        }
        $merged = DeploymentLicenseConfig::merge([], '[{"name":"example/one","license_used":"MIT"}]');
        self::assertSame('example/one', $merged['Licenses.config.json']['packages'][0]['name']);
    }

    /**
     * @return void
     */
    public function testInvalidCollectionsFailWithoutSilentlyDroppingProjectTerms(): void
    {
        foreach (['true', '{"packages":"invalid"}', '{"packages":[{}]}', '{"licenses":[{}]}'] as $project) {
            try {
                DeploymentLicenseConfig::merge([], $project);
                self::fail('Invalid project license configuration must fail.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
