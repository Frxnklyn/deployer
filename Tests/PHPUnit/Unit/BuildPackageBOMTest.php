<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Checks archived inventories without running recipes or touching project files.
 */
class BuildPackageBOMTest extends TestCase
{
    /**
     * Compaction must resolve shared terms rather than discard evidence outside package rows.
     * 
     * @return void
     */
    public function testSharedDefinitionsSurviveAsSourceEvidence() : void
    {
        $source = json_encode([
            'packages' => [['name' => 'example/library', 'version' => '1.0.0', 'license_used' => 'Client-License']],
            'licenses' => ['Client-License' => ['license_text' => 'Shared installation terms']]
        ], JSON_THROW_ON_ERROR);
        $path = tempnam(sys_get_temp_dir(), 'build-shared-license-');
        try {
            file_put_contents($path, $source);
            $snapshot = json_decode($this->buildPackageBom($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['Client-License'], array_column($snapshot['packages'][0]['licenses'], 'name'));
            self::assertSame([['name' => 'Client-License', 'source' => 'config', 'text_available' => true]], $snapshot['packages'][0]['licenses']);
            self::assertArrayNotHasKey('licenses', $snapshot);
            self::assertArrayNotHasKey('license_text', $snapshot['packages'][0]);
            self::assertSame($source, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    /**
        * Build snapshots retain scanner identities and license evidence without terms or unrelated metadata.
     * 
     * @return void
     */
    public function testMinimalSnapshotPreservesNamesVersionsAndUnversionedPackages() : void
    {
        $data = ['packages' => [
            ['name' => 'example/library', 'version' => 'v1.2.3', 'license_text' => ['MIT' => 'License']],
            ['name' => 'npm-asset/scope--package', 'version' => '2.0.0', 'description' => 'Package'],
            ['name' => 'example/unversioned', 'license' => ['MIT']]
        ]];
        $path = tempnam(sys_get_temp_dir(), 'build-bom-');
        try {
            $source = json_encode($data, JSON_THROW_ON_ERROR);
            file_put_contents($path, $source);
            self::assertEquals(['packages' => [
                ['name' => 'example/library', 'version' => 'v1.2.3', 'urn' => 'composer:example/library:v1.2.3',
                    'ecosystem' => 'composer', 'license_used' => 'MIT',
                    'licenses' => [['name' => 'MIT', 'source' => 'config', 'text_available' => true]]],
                ['name' => '@scope/package', 'version' => '2.0.0', 'urn' => 'npm:@scope/package:2.0.0',
                    'ecosystem' => 'npm', 'licenses' => []],
                ['name' => 'example/unversioned', 'licenses' => [['name' => 'MIT', 'text_available' => false]], 'urn' => 'composer:example/unversioned:',
                    'ecosystem' => 'composer', 'license_used' => 'MIT']
            ]], json_decode($this->buildPackageBom($path), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame($source, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    /**
     * An empty inventory remains a valid archived input rather than a missing BOM.
     * 
     * @return void
     */
    public function testEmptyInventory() : void
    {
        $path = tempnam(sys_get_temp_dir(), 'build-bom-');
        try {
            file_put_contents($path, '{"packages":[]}');
            self::assertSame(['packages' => []], json_decode($this->buildPackageBom($path), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($path);
        }
    }

    /**
     * Saved builds need Git references for Bower auditing even when the Composer lock is unavailable.
     * 
     * @return void
     */
    public function testMinimalSnapshotRetainsGitReferences() : void
    {
        $commit = str_repeat('a', 40);
        $data = ['packages' => [[
            'name' => 'bower-asset/library', 'version' => '1.0.0',
            'source' => ['type' => 'git', 'reference' => $commit, 'url' => 'https://example.org/library.git'],
            'license_text' => ['MIT' => 'License']
        ]]];
        $path = tempnam(sys_get_temp_dir(), 'build-bom-git-');
        try {
            $source = json_encode($data, JSON_THROW_ON_ERROR);
            file_put_contents($path, $source);
            self::assertEquals(['packages' => [[
                'name' => 'library', 'version' => '1.0.0', 'urn' => 'bower:library:1.0.0', 'ecosystem' => 'bower',
                'license_used' => 'MIT', 'licenses' => [['name' => 'MIT', 'source' => 'config', 'text_available' => true]],
                'source' => ['type' => 'git', 'reference' => $commit]
            ]]], json_decode($this->buildPackageBom($path), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame($source, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    /**
     * Wrong document shapes must not be recorded as apparently clean inventories.
     * 
     * @return void
     */
    public function testInvalidInventoryIsRejected() : void
    {
        $path = tempnam(sys_get_temp_dir(), 'build-bom-');
        try {
            file_put_contents($path, '{"packages":[{"version":"1.0"}]}');
            $this->expectException(InvalidArgumentException::class);
            $this->buildPackageBom($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Exercises the action's conversion without invoking its build or persistence lifecycle.
     * 
     * @param string $filePath
     * @return string
     */
    private function buildPackageBom(string $filePath) : string
    {
        $action = ActionFactory::createFromString(new Workbench(), Build::class);
        $method = new \ReflectionMethod(Build::class, 'buildPackageBom');
        $method->setAccessible(true);
        return $method->invoke($action, $filePath);
    }
}