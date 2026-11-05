<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\LicenseScanner;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Reproduces legacy recipe BOM capture against the installed PackageManager BOM API.
 *
 * Recipes\Build.php calls Build::buildRecipeBom() after Composer install; the Build action
 * then compacts the staged package_bom.json. Neither step may rely on unavailable URN or
 * data-constructor APIs.
 *
 * @author Brooklyn Fränzschky
 */
class LegacyRecipeBomTest extends TestCase
{
    private string $projectPath;

    /**
     * Creates an isolated installed Composer project.
     *
     * @return void
     */
    protected function setUp() : void
    {
        $this->projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('legacy-recipe-bom-', true);
        $vendor = $this->projectPath . DIRECTORY_SEPARATOR . 'vendor';
        mkdir($vendor . '/example/library', 0777, true);
        mkdir($vendor . '/example/bundle', 0777, true);
        mkdir($vendor . '/example/tooling', 0777, true);
        file_put_contents($this->projectPath . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'example/library', 'version' => '1.2.3', 'license' => ['MIT'],
                    'source' => ['type' => 'git', 'url' => 'https://example.invalid/library.git', 'reference' => 'abc123']],
                ['name' => 'example/bundle', 'version' => '2.0.0', 'license' => ['MIT']]
            ],
            'packages-dev' => [
                ['name' => 'example/tooling', 'version' => '0.9.0', 'license' => ['BSD-3-Clause']]
            ]
        ], JSON_THROW_ON_ERROR));
        file_put_contents($vendor . '/example/library/LICENSE', 'Library MIT terms');
        file_put_contents($vendor . '/example/tooling/LICENSE', 'Tooling BSD terms');
        file_put_contents($vendor . '/example/bundle/LICENSE', 'Bundle MIT terms');
        file_put_contents($vendor . '/example/bundle/includes.json', json_encode([
            ['name' => 'bundled-widget', 'version' => '4.5.6', 'license' => ['Apache-2.0'],
                'license_text' => ['Apache-2.0' => 'Bundled Apache terms']]
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Removes the isolated project.
     *
     * @return void
     */
    protected function tearDown() : void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectPath);
    }

    /**
     * The failing recipe call must capture locked identities, bundles and local evidence.
     *
     * @return void
     */
    public function testRecipeCaptureUsesInstalledPackageManagerApi() : void
    {
        $bom = Build::buildRecipeBom($this->projectPath, '');

        self::assertInstanceOf(LicenseBOM::class, $bom);
        $packages = $bom->getPackages();
        self::assertSame(['bundled-widget', 'example/bundle', 'example/library', 'example/tooling'], $this->sortedNames($bom));
        self::assertSame('1.2.3', $packages['example/library']->getVersion());
        self::assertSame('Library MIT terms', $packages['example/library']->getLicenseText('MIT'));
        self::assertSame('Tooling BSD terms', $packages['example/tooling']->getLicenseText('BSD-3-Clause'));
        self::assertSame('Bundled Apache terms', $packages['bundled-widget']->getLicenseText('Apache-2.0'));
    }

    /**
     * Project configuration selects terms but must not rewrite locked versions.
     *
     * @return void
     */
    public function testProjectConfigurationSelectsLicenseWithoutChangingLockedVersion() : void
    {
        file_put_contents($this->projectPath . '/vendor/licenses.json', json_encode(['packages' => [
            ['name' => 'example/library', 'version' => '0.0.1', 'license' => ['MIT']]
        ]], JSON_THROW_ON_ERROR));
        $bom = Build::buildRecipeBom($this->projectPath, json_encode([
            'packages' => [['name' => 'example/library', 'version' => '9.9.9', 'license_used' => 'Commercial']],
            'licenses' => ['Commercial' => ['license_text' => 'Project commercial terms']]
        ], JSON_THROW_ON_ERROR));

        $library = $bom->getPackages()['example/library'];
        self::assertSame('1.2.3', $library->getVersion());
        self::assertSame('Commercial', $library->getLicenseUsed());
        self::assertSame('Project commercial terms', $library->getLicenseText('Commercial'));
        self::assertArrayHasKey('Commercial', $bom->getLicenses());
    }

    /**
     * Invalid configuration and missing installed inputs must fail rather than producing an empty BOM.
     *
     * @return void
     */
    public function testInvalidInputsFailExplicitly() : void
    {
        try {
            Build::buildRecipeBom($this->projectPath, '{"packages":[{"license_used":"MIT"}]}');
            self::fail('Unnamed configured packages must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame('Build BOM packages must have a name.', $e->getMessage());
        }
        unlink($this->projectPath . '/composer.lock');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Build BOM requires a vendor folder with composer.lock or vendor/licenses.json.');
        Build::buildRecipeBom($this->projectPath);
    }

    /**
     * Like the previous recipe helper, an existing inventory remains usable without a lock.
     *
     * @return void
     */
    public function testExistingInventoryWithoutLockIsEnriched() : void
    {
        unlink($this->projectPath . '/composer.lock');
        file_put_contents($this->projectPath . '/vendor/licenses.json', json_encode(['packages' => [
            ['name' => 'example/library', 'version' => '1.2.3', 'license' => ['MIT']]
        ]], JSON_THROW_ON_ERROR));
        $bom = Build::buildRecipeBom($this->projectPath, '  ');

        self::assertSame(['bundled-widget', 'example/library'], $this->sortedNames($bom));
        self::assertSame('Library MIT terms', $bom->getPackages()['example/library']->getLicenseText('MIT'));
    }

    /**
     * The staged full inventory must compact to a nonempty minimal snapshot that audits cleanly.
     *
     * @return void
     */
    public function testStagedRecipeInventoryCompactsAndAudits() : void
    {
        $staged = $this->projectPath . DIRECTORY_SEPARATOR . 'package_bom.json';
        (new JsonBOM(Build::buildRecipeBom($this->projectPath, null)))->saveToFile($staged);
        $workbench = new Workbench();
        $action = ActionFactory::createFromString($workbench, Build::class);
        $method = new \ReflectionMethod($action, 'buildPackageBom');

        $snapshot = json_decode($method->invoke($action, $staged), true, 512, JSON_THROW_ON_ERROR);
        $identities = [];
        foreach ($snapshot['packages'] as $package) {
            $identities[$package['name']] = $package;
        }
        ksort($identities);
        self::assertSame(['bundled-widget', 'example/bundle', 'example/library', 'example/tooling'], array_keys($identities));
        self::assertEquals(['name' => 'example/library', 'version' => '1.2.3', 'ecosystem' => 'composer',
            'urn' => 'composer:example/library:1.2.3',
            'source' => ['type' => 'git', 'reference' => 'abc123'], 'license_used' => 'MIT',
            'licenses' => [['name' => 'MIT', 'source' => 'local', 'text_available' => true]]], $identities['example/library']);
        self::assertSame('composer:example/bundle:2.0.0', $identities['bundled-widget']['bundled_in']);

        $task = new GenericTask($workbench);
        $task->setParameter(Audit::TASK_PARAM_SBOM, file_get_contents($staged));
        self::assertSame([], (new LicenseScanner($workbench))->audit($task));
    }

    public function testLicenseOverridePreservesAlternativeTermsAndEvidence() : void
    {
        file_put_contents($this->projectPath . '/vendor/licenses.json', json_encode(['packages' => [[
            'name' => 'example/library', 'license' => ['MIT'],
            'licenses' => [['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]],
            'license_text' => ['MIT' => 'Recorded MIT terms']
        ]]], JSON_THROW_ON_ERROR));
        $bom = Build::buildRecipeBom($this->projectPath, json_encode(['packages' => [[
            'name' => 'example/library', 'license_used' => 'Commercial',
            'licenses' => [['name' => 'Commercial', 'text' => 'Selected commercial terms']]
        ]]], JSON_THROW_ON_ERROR));
        $package = $bom->getPackages()['example/library'];
        self::assertSame(['MIT', 'Commercial'], $package->getLicenseNames());
        self::assertSame('Recorded MIT terms', $package->getLicenseText('MIT'));
        self::assertSame('Selected commercial terms', $package->getLicenseText('Commercial'));
        $snapshot = json_decode(\axenox\Deployer\Common\Pipelines\ComposerBuildBom::buildSnapshot($bom), true, 512, JSON_THROW_ON_ERROR);
        $library = array_column($snapshot['packages'], null, 'name')['example/library'];
        self::assertSame([
            ['name' => 'MIT', 'source' => 'spdx', 'text_available' => true],
            ['name' => 'Commercial', 'source' => 'config', 'text_available' => true]
        ], $library['licenses']);
        self::assertArrayNotHasKey('license_text', $library);
    }

    public function testUnavailableSelectedFileDoesNotBorrowOtherLicenseTerms() : void
    {
        $bom = Build::buildRecipeBom($this->projectPath, json_encode([
            'packages' => [['name' => 'example/library', 'license_used' => 'Commercial']],
            'licenses' => ['Commercial' => ['license_file' => 'missing-commercial-terms.txt']]
        ], JSON_THROW_ON_ERROR));
        $package = $bom->getPackages()['example/library'];
        self::assertSame('Commercial', $package->getLicenseUsed());
        self::assertNull($package->getLicenseText('Commercial'));
        self::assertFalse($package->hasLicenseText());
    }

    /**
     * @param LicenseBOM $bom
     * @return string[]
     */
    private function sortedNames(LicenseBOM $bom) : array
    {
        $names = array_map(fn($package) => $package->getName(), array_values($bom->getPackages()));
        sort($names);
        return $names;
    }
}