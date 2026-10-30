<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Guards reuse of an already generated inventory instead of rebuilding it from manifests.
 */
class BuildExistingBOMTest extends TestCase
{
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
            $snapshot = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true, 512, JSON_THROW_ON_ERROR);

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