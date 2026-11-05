<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use axenox\Deployer\Common\Pipelines\ComposerBuildBom;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Guards reuse of an already generated inventory instead of rebuilding it from manifests.
 */
class BuildExistingBOMTest extends TestCase
{
    /**
     * Project selections and shared terms must participate even when a generated inventory exists.
     *
     * @return void
     */
    public function testProjectConfigurationOverridesExistingInventoryBeforeCompaction() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-project-licenses-', true);
        $vendorPath = $projectPath . DIRECTORY_SEPARATOR . 'vendor';
        mkdir($vendorPath, 0777, true);
        $licensePath = $vendorPath . DIRECTORY_SEPARATOR . 'licenses.json';
        try {
            $source = json_encode(['packages' => [[
                'name' => 'example/library', 'version' => '1.2.3', 'license_used' => 'MIT',
                'licenses' => [['name' => 'MIT']]
            ]]], JSON_THROW_ON_ERROR);
            file_put_contents($licensePath, $source);
            $configuration = json_encode([
                'packages' => [['name' => 'example/library', 'license_used' => 'Commercial', 'ecosystem' => 'private']],
                'licenses' => [['name' => 'Commercial', 'text' => 'Project terms']]
            ], JSON_THROW_ON_ERROR);
            ActionFactory::createFromString(new Workbench(), Build::class);
            $bom = Build::buildRecipeBom($projectPath, $configuration);
            $package = $bom->getPackages()['example/library'];
            self::assertSame('1.2.3', $package->getVersion());
            self::assertSame('private', $package->toComposerArray()['ecosystem']);
            self::assertSame('Commercial', $package->getLicenseUsed());
            self::assertSame('Project terms', $package->getLicenseText('Commercial'));
            self::assertTrue($package->hasLicenseText());
            self::assertCount(1, $bom->getPackages());
            $snapshot = json_decode(ComposerBuildBom::buildSnapshot($bom), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('private:example/library:1.2.3', $snapshot['packages'][0]['urn']);
            self::assertSame('Commercial', $snapshot['packages'][0]['license_used']);
            self::assertSame('private', $snapshot['packages'][0]['ecosystem']);
            self::assertTrue($snapshot['packages'][0]['licenses'][1]['text_available']);
            self::assertSame($source, file_get_contents($licensePath));
        } finally {
            unlink($licensePath);
            rmdir($vendorPath);
            rmdir($projectPath);
        }
    }

    /**
     * Reusing generated output must restore evidence rather than import it as configuration.
     *
     * @return void
     */
    public function testRecordedEvidenceSurvivesExistingInventoryReuse() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-evidence-', true);
        $vendorPath = $projectPath . DIRECTORY_SEPARATOR . 'vendor';
        mkdir($vendorPath, 0777, true);
        $licensePath = $vendorPath . DIRECTORY_SEPARATOR . 'licenses.json';
        try {
            $source = json_encode(['packages' => [[
                'name' => 'example/library', 'license_used' => 'MIT',
                'licenses' => [['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]]
            ]]], JSON_THROW_ON_ERROR);
            file_put_contents($licensePath, $source);
            ActionFactory::createFromString(new Workbench(), Build::class);
            $bom = Build::buildRecipeBom($projectPath);
            $package = $bom->getPackages()['example/library'];
            self::assertSame('MIT', $package->getLicenseUsed());
            self::assertTrue($package->toComposerArray()['licenses'][0]['text_available']);
            self::assertNull($package->getLicenseText('MIT'));
            $snapshot = json_decode(ComposerBuildBom::buildSnapshot($bom), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('composer:example/library:', $snapshot['packages'][0]['urn']);
            self::assertSame([['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]], $snapshot['packages'][0]['licenses']);
            self::assertSame($source, file_get_contents($licensePath));
        } finally {
            unlink($licensePath);
            rmdir($vendorPath);
            rmdir($projectPath);
        }
    }

    /**
     * Existing inventories must work without reading a Composer lock or bundled manifests.
     * 
     * @return void
     */
    public function testExistingInventoryBypassesManifestGeneration() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-existing-bom-', true);
        $vendorPath = $projectPath . DIRECTORY_SEPARATOR . 'vendor';
        mkdir($vendorPath, 0777, true);
        $licensePath = $vendorPath . DIRECTORY_SEPARATOR . 'licenses.json';
        try {
            $source = json_encode(['packages' => [[
                'name' => 'example/library', 'version' => '1.2.3',
                'license_text' => ['MIT' => 'License'],
                'bundled_in' => 'composer:example/owner:2.0.0'
            ]]], JSON_THROW_ON_ERROR);
            file_put_contents($licensePath, $source);
            ActionFactory::createFromString(new Workbench(), Build::class);

            $bom = Build::buildRecipeBom($projectPath);
            $snapshot = json_decode(ComposerBuildBom::buildSnapshot($bom), true, 512, JSON_THROW_ON_ERROR);

            self::assertCount(1, $snapshot['packages']);
            self::assertSame('example/library', $snapshot['packages'][0]['name']);
            self::assertSame('1.2.3', $snapshot['packages'][0]['version']);
            self::assertSame('composer:example/owner:2.0.0', $snapshot['packages'][0]['bundled_in']);
            self::assertArrayNotHasKey('license_text', $snapshot['packages'][0]);
            self::assertSame($source, file_get_contents($licensePath));
        } finally {
            unlink($licensePath);
            rmdir($vendorPath);
            rmdir($projectPath);
        }
    }
}