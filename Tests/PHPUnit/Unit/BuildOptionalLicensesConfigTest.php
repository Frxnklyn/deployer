<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\LicenseScanner;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards local license discovery independently of optional project configuration.
 */
class BuildOptionalLicensesConfigTest extends TestCase
{
    /**
     * Existing inventories must retain discovered declarations and real text evidence in saved builds.
     *
     * @param string|null $configuration
     * @param bool $availableTerms
     * @return void
     */
    #[DataProvider('configurationScenarios')]
    public function testExistingInventoryIsEnrichedBeforeCompactionAndAudit(?string $configuration, bool $availableTerms) : void
    {
        $projectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('build-optional-licenses-', true);
        $vendorPath = $projectPath . DIRECTORY_SEPARATOR . 'vendor';
        $composerPath = $vendorPath . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'library';
        $npmPath = $vendorPath . DIRECTORY_SEPARATOR . 'npm-asset' . DIRECTORY_SEPARATOR . 'library';
        $inventoryPath = $vendorPath . DIRECTORY_SEPARATOR . 'licenses.json';
        $stagedPath = $projectPath . DIRECTORY_SEPARATOR . 'package_bom.json';
        $manifestPath = $npmPath . DIRECTORY_SEPARATOR . 'package.json';
        $termsPaths = [
            $composerPath . DIRECTORY_SEPARATOR . 'LICENSE',
            $npmPath . DIRECTORY_SEPARATOR . 'LICENSE'
        ];
        mkdir($composerPath, 0777, true);
        mkdir($npmPath, 0777, true);
        try {
            $source = json_encode(['packages' => [
                ['name' => 'example/library', 'version' => '1.0.0', 'license' => ['MIT']],
                ['name' => 'npm-asset/library', 'version' => '2.0.0']
            ]], JSON_THROW_ON_ERROR);
            file_put_contents($inventoryPath, $source);
            file_put_contents($manifestPath, '{"name":"library","license":"MIT"}');
            if ($availableTerms) {
                foreach ($termsPaths as $path) {
                    file_put_contents($path, 'Installed MIT terms');
                }
            }

            $workbench = new Workbench();
            $action = ActionFactory::createFromString($workbench, Build::class);
            $bom = Build::buildRecipeBom($projectPath, $configuration);
            (new JsonBOM($bom))->saveToFile($stagedPath);
            $method = new \ReflectionMethod(Build::class, 'buildPackageBom');
            $method->setAccessible(true);
            $snapshot = $method->invoke($action, $stagedPath);
            $packages = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)['packages'];

            self::assertSame(['example/library', 'library'], array_column($packages, 'name'));
            self::assertSame(['composer', 'npm'], array_column($packages, 'ecosystem'));
            foreach ($packages as $package) {
                self::assertSame($package['ecosystem'] . ':' . $package['name'] . ':' . $package['version'], $package['urn']);
                self::assertSame('MIT', $package['license_used']);
                self::assertCount(1, $package['licenses']);
                self::assertSame('MIT', $package['licenses'][0]['name']);
                self::assertSame($availableTerms, $package['licenses'][0]['text_available']);
                self::assertArrayNotHasKey('text', $package['licenses'][0]);
                if ($availableTerms) {
                    self::assertSame('local', $package['licenses'][0]['source']);
                }
            }
            self::assertSame($source, file_get_contents($inventoryPath));

            $task = new GenericTask($workbench);
            $task->setParameter(Audit::TASK_PARAM_SBOM, file_get_contents($stagedPath));
            $findings = (new LicenseScanner($workbench))->audit($task);
            self::assertCount($availableTerms ? 0 : 2, $findings);
            foreach ($findings as $finding) {
                self::assertSame('missing-license-text', $finding->getSourceId());
            }
        } finally {
            foreach (array_merge([$inventoryPath, $stagedPath, $manifestPath], $termsPaths) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            foreach ([$composerPath, dirname($composerPath), $npmPath, dirname($npmPath), $vendorPath, $projectPath] as $path) {
                rmdir($path);
            }
        }
    }

    /**
     * Omitted, blank and empty-object configuration must not control local evidence discovery.
     *
     * @return array<string,array{string|null,bool}>
     */
    public static function configurationScenarios() : array
    {
        $scenarios = [];
        foreach (['omitted' => null, 'empty' => '', 'blank' => '  ', 'empty object' => '{}'] as $name => $configuration) {
            $scenarios[$name . ' with terms'] = [$configuration, true];
            $scenarios[$name . ' without terms'] = [$configuration, false];
        }
        return $scenarios;
    }
}
