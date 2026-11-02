<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\LicenseScanner;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers build inventories when Composer hooks have not generated vendor/licenses.json.
 */
class BuildBOMTest extends TestCase
{
    /**
     * Explicit project terms must win over the installed package's generic license file.
     *
     * @return void
     */
    public function testProjectLicenseFileTakesPrecedenceOverGenericPackageTerms() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-project-precedence-', true);
        $packagePath = $projectPath . '/vendor/example/library';
        mkdir($packagePath, 0777, true);
        try {
            file_put_contents($projectPath . '/vendor/licenses.json', '{"packages":[{"name":"example/library","license_used":"MIT"}]}');
            file_put_contents($packagePath . '/LICENSE', 'Default package terms');
            file_put_contents($projectPath . '/vendor/project-terms.txt', 'Selected project terms');
            ActionFactory::createFromString(new Workbench(), Build::class);
            $bom = Build::buildRecipeBom($projectPath, json_encode([
                'packages' => [['name' => 'example/library', 'license_used' => 'Commercial']],
                'licenses' => [['name' => 'Commercial', 'file' => 'project-terms.txt']]
            ], JSON_THROW_ON_ERROR));
            $package = $bom->getPackages()['composer:example/library:'];
            self::assertSame('Selected project terms', $package->getLicenses()['Commercial']->getText());
            self::assertSame('local', $package->getLicenses()['Commercial']->getSource());
        } finally {
            unlink($projectPath . '/vendor/licenses.json');
            unlink($projectPath . '/vendor/project-terms.txt');
            unlink($packagePath . '/LICENSE');
            rmdir($packagePath);
            rmdir(dirname($packagePath));
            rmdir($projectPath . '/vendor');
            rmdir($projectPath);
        }
    }

    /**
     * Configured files must be read before cleanup, and missing files must not certify terms.
     *
     * @param bool $existingInventory
     * @param bool $availableFile
     * @return void
     */
    #[DataProvider('projectFileScenarios')]
    public function testProjectFileEvidenceSurvivesCompactionAndAudit(bool $existingInventory, bool $availableFile) : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-project-file-', true);
        $vendorPath = $projectPath . '/vendor';
        mkdir($vendorPath, 0777, true);
        $inventoryPath = $existingInventory ? $vendorPath . '/licenses.json' : $projectPath . '/composer.lock';
        $termsPath = $vendorPath . '/project-terms.txt';
        try {
            file_put_contents($inventoryPath, json_encode(['packages' => [[
                'name' => 'example/library', 'version' => '1.0.0', 'license' => ['MIT']
            ]]], JSON_THROW_ON_ERROR));
            if ($availableFile) {
                file_put_contents($termsPath, 'Project file terms');
            }
            $configuration = json_encode([
                'packages' => [['name' => 'example/library', 'license_used' => 'Commercial']],
                'licenses' => ['Commercial' => ['license_file' => 'project-terms.txt']]
            ], JSON_THROW_ON_ERROR);
            $workbench = new Workbench();
            ActionFactory::createFromString($workbench, Build::class);
            $bom = Build::buildRecipeBom($projectPath, $configuration);
            $package = $bom->getPackages()['composer:example/library:1.0.0'];
            self::assertSame('Commercial', $package->getLicenseUsed());
            self::assertSame($availableFile, $package->hasLicenseText());
            self::assertSame($availableFile ? 'Project file terms' : null, $package->getLicenses()['Commercial']->getText());

            $snapshot = (new JsonBOM($bom))->setFormat('minimal')->buildJson();
            $task = new GenericTask($workbench);
            $task->setParameter(Audit::TASK_PARAM_SBOM, $snapshot);
            $findings = (new LicenseScanner($workbench))->audit($task);
            self::assertCount($availableFile ? 0 : 1, $findings);
            if (! $availableFile) {
                self::assertSame('missing-license-text', $findings[0]->getSourceId());
            }
        } finally {
            if (is_file($termsPath)) {
                unlink($termsPath);
            }
            unlink($inventoryPath);
            rmdir($vendorPath);
            rmdir($projectPath);
        }
    }

    /**
     * Both inventory routes must distinguish readable project files from unresolved references.
     *
     * @return array<string,array{bool,bool}>
     */
    public static function projectFileScenarios() : array
    {
        return [
            'existing inventory with terms' => [true, true],
            'existing inventory without terms' => [true, false],
            'fallback with terms' => [false, true],
            'fallback without terms' => [false, false]
        ];
    }

    /**
     * Shared project terms must supplement declared licenses without repeating package entries.
     *
     * @return void
     */
    public function testSharedProjectTermsApplyWithoutPackageOverrides() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-project-shared-', true);
        $vendorPath = $projectPath . '/vendor';
        mkdir($vendorPath, 0777, true);
        $inventoryPath = $vendorPath . '/licenses.json';
        try {
            file_put_contents($inventoryPath, '{"packages":[{"name":"example/library","license_used":"Commercial"}]}');
            ActionFactory::createFromString(new Workbench(), Build::class);
            $bom = Build::buildRecipeBom($projectPath, '{"licenses":{"Commercial":{"license_text":"Shared project terms"}}}');
            $package = $bom->getPackages()['composer:example/library:'];
            self::assertSame('Shared project terms', $package->getLicenses()['Commercial']->getText());
            self::assertTrue($package->hasLicenseText());
        } finally {
            unlink($inventoryPath);
            rmdir($vendorPath);
            rmdir($projectPath);
        }
    }

    /**
     * Project configuration must not bypass strict UXON import to fabricate resolved evidence.
     *
     * @return void
     */
    public function testProjectConfigurationRejectsResultOnlyEvidence() : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-project-evidence-', true);
        $vendorPath = $projectPath . '/vendor';
        mkdir($vendorPath, 0777, true);
        $inventoryPath = $vendorPath . '/licenses.json';
        try {
            file_put_contents($inventoryPath, '{"packages":[{"name":"example/library"}]}');
            ActionFactory::createFromString(new Workbench(), Build::class);
            $this->expectException(UxonMapError::class);
            Build::buildRecipeBom($projectPath, json_encode(['packages' => [[
                'name' => 'example/library',
                'licenses' => [['name' => 'Commercial', 'text_available' => true]]
            ]]], JSON_THROW_ON_ERROR));
        } finally {
            unlink($inventoryPath);
            rmdir($vendorPath);
            rmdir($projectPath);
        }
    }

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
            self::assertSame([['name' => 'MIT', 'source' => 'local', 'text_available' => true]], $packages['example/library']['licenses']);
            self::assertSame(['MIT'], array_column($packages['example']['licenses'], 'name'));
            self::assertSame([['name' => 'MIT', 'source' => 'local', 'text_available' => true]], $packages['example']['licenses']);
            self::assertSame([['name' => 'MIT', 'text_available' => false]], $packages['not-installed']['licenses']);
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