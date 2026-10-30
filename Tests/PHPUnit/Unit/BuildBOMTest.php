<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers build inventories when Composer hooks have not generated vendor/licenses.json.
 */
class BuildBOMTest extends TestCase
{
    /**
     * Build snapshots must include bundled dependencies without running installation hooks.
     * 
     * @return void
     */
    public function testBuildInventoryWithoutGeneratedLicenseFile() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-bom-', true);
        $bundlePath = $projectPath . '/vendor/example/library';
        mkdir($bundlePath, 0777, true);
        mkdir($projectPath . '/config');
        try {
            file_put_contents($projectPath . '/composer.lock', json_encode(['packages' => [[
                'name' => 'example/library', 'version' => 'v1.2.3', 'license' => ['MIT']
            ]]], JSON_THROW_ON_ERROR));
            file_put_contents($bundlePath . '/includes.json', json_encode([[
                'name' => 'npm-asset/example', 'version' => '2.0.0', 'license_text' => ['MIT' => 'License']
            ]], JSON_THROW_ON_ERROR));
            file_put_contents($projectPath . '/config/Licenses.config.json', json_encode([[
                'name' => 'example/private', 'version' => '3.0.0'
            ]], JSON_THROW_ON_ERROR));

            ActionFactory::createFromString(new Workbench(), Build::class);
            $bom = Build::buildRecipeBom($projectPath);
            $snapshot = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true, 512, JSON_THROW_ON_ERROR);
            $packages = array_column($snapshot['packages'], null, 'name');

            self::assertSame('v1.2.3', $packages['example/library']['version']);
            self::assertSame('2.0.0', $packages['example']['version']);
            self::assertSame('npm', $packages['example']['ecosystem']);
            self::assertSame('composer:example/library:v1.2.3', $packages['example']['bundled_in']);
            self::assertSame('3.0.0', $packages['example/private']['version']);
            self::assertArrayNotHasKey('license_text', $packages['example']);
            self::assertFileDoesNotExist($projectPath . '/vendor/licenses.json');
        } finally {
            unlink($projectPath . '/composer.lock');
            unlink($bundlePath . '/includes.json');
            unlink($projectPath . '/config/Licenses.config.json');
            rmdir($projectPath . '/config');
            rmdir($bundlePath);
            rmdir(dirname($bundlePath));
            rmdir($projectPath . '/vendor');
            rmdir($projectPath);
        }
    }
}