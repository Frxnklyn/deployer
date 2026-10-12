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
    * Build snapshots retain scanner identities but exclude license and unrelated source metadata.
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
            self::assertSame(['packages' => [
                ['name' => 'example/library', 'version' => 'v1.2.3', 'ecosystem' => 'composer'],
                ['name' => '@scope/package', 'version' => '2.0.0', 'ecosystem' => 'npm'],
                ['name' => 'example/unversioned', 'ecosystem' => 'composer']
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
            self::assertSame(['packages' => [[
                'name' => 'library', 'version' => '1.0.0', 'ecosystem' => 'bower',
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