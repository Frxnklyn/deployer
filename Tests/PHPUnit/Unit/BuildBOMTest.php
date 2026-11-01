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
     * Fallback snapshots need installed terms and recovered declarations before vendor cleanup.
     * 
     * @return void
     */
    public function testFallbackDiscoversLocalTermsAndAssetManifestLicenses() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-license-', true);
        $composerPath = $projectPath . '/vendor/example/library';
        $assetPath = $projectPath . '/vendor/bower-asset/example';
        mkdir($composerPath, 0777, true);
        mkdir($assetPath, 0777, true);
        try {
            file_put_contents($projectPath . '/composer.lock', json_encode(['packages' => [
                ['name' => 'example/library', 'version' => '1.0.0', 'license' => ['MIT'], 'license_text' => ['MIT' => '']],
                ['name' => 'bower-asset/example', 'version' => '2.0.0']
            ]], JSON_THROW_ON_ERROR));
            file_put_contents($composerPath . '/LICENSE', 'Installed composer terms');
            file_put_contents($assetPath . '/package.json', '{"license":"MIT"}');
            file_put_contents($assetPath . '/LICENSE.md', 'Installed asset terms');
            file_put_contents($composerPath . '/includes.json', json_encode([
                ['name' => 'not-installed', 'ecosystem' => 'npm', 'version' => '3.0.0', 'license' => ['MIT']],
                ['name' => 'example', 'ecosystem' => 'bower']
            ], JSON_THROW_ON_ERROR));

            ActionFactory::createFromString(new Workbench(), Build::class);
            $bom = Build::buildRecipeBom($projectPath);
            $snapshot = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true, 512, JSON_THROW_ON_ERROR);
            $packages = array_column($snapshot['packages'], null, 'name');
            self::assertSame(['MIT' => 'embedded'], $packages['example/library']['license_text_source']);
            self::assertSame(['MIT'], $packages['example']['license']);
            self::assertSame(['MIT' => 'embedded'], $packages['example']['license_text_source']);
            self::assertSame(['MIT' => 'missing'], $packages['not-installed']['license_text_source']);
            self::assertArrayNotHasKey('license_text', $packages['example/library']);
            self::assertFileDoesNotExist($projectPath . '/vendor/licenses.json');
        } finally {
            unlink($projectPath . '/composer.lock');
            unlink($composerPath . '/LICENSE');
            unlink($composerPath . '/includes.json');
            unlink($assetPath . '/package.json');
            unlink($assetPath . '/LICENSE.md');
            rmdir($composerPath);
            rmdir($assetPath);
            rmdir(dirname($composerPath));
            rmdir(dirname($assetPath));
            rmdir($projectPath . '/vendor');
            rmdir($projectPath);
        }
    }

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