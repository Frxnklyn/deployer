<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Common\Pipelines\NativeHostInstaller;
use axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace;
use axenox\PackageManager\Common\Updater\SelfUpdateInstaller;
use PHPUnit\Framework\TestCase;

class NativeRootTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RequiresMethod(\axenox\PackageManager\Common\Updater\UpdateDownloader::class, 'isNativeOta')]
    public function testPackageManagerRejectsExplicitNativeRootForLegacyDownloadBeforeExecution(): void
    {
        $action = $this->getMockBuilder(\axenox\PackageManager\Actions\SelfUpdate::class)
            ->disableOriginalConstructor()->onlyMethods(['getWorkbench'])->getMock();
        $action->expects(self::never())->method('getWorkbench');
        $downloader = $this->getMockBuilder(\axenox\PackageManager\Common\Updater\UpdateDownloader::class)
            ->disableOriginalConstructor()->onlyMethods(['isNativeOta', 'getPathAbsolute', 'uploadLog'])->getMock();
        $downloader->method('isNativeOta')->willReturn(false);
        $downloader->expects(self::never())->method('getPathAbsolute');
        $downloader->method('uploadLog')->willReturn('failure reported');
        $config = $this->createStub(\exface\Core\Interfaces\ConfigurationInterface::class);
        $config->method('getOption')->willReturn(PHP_BINARY);
        $generator = (new \ReflectionMethod($action, 'performInstallation'))->invoke(
            $action, $downloader, $config, 'C:\\explicit root');
        $this->expectException(\exface\Core\Exceptions\Actions\ActionRuntimeError::class);
        $this->expectExceptionMessage('native-root is supported only');
        iterator_to_array($generator);
    }

    public function testMalformedBindingsAndInvalidRootsFailBeforeTargetWrites(): void
    {
        $root = NativeTestWorkspace::create('root-validation');
        $manifest = ['format' => 'axenox.deployer.manual-install.v1', 'host' => [
            'operating_system' => PHP_OS_FAMILY === 'Windows' ? 'Windows' : 'Linux',
            'installation_root_binding' => 'deferred_explicit'
        ]];
        try {
            self::assertSame(realpath($root), NativeHostInstaller::resolveRoot($manifest, $root));
            foreach ([
                ['installation_root_binding' => null],
                ['installation_root_binding' => []],
                ['installation_root_binding' => 'unknown'],
                ['installation_root' => $root],
                ['installation_root' => 42, 'installation_root_binding' => 'deferred_explicit'],
                ['operating_system' => []]
            ] as $changes) {
                $invalid = $manifest;
                $invalid['host'] = array_replace($manifest['host'], $changes);
                try {
                    NativeHostInstaller::resolveRoot($invalid, $root);
                    self::fail('Malformed binding accepted.');
                } catch (\RuntimeException $error) {
                    self::assertNotSame('', $error->getMessage());
                }
            }
            foreach (['', 'relative', $root . DIRECTORY_SEPARATOR . '..', dirname($root) . DIRECTORY_SEPARATOR . 'missing-native-root'] as $path) {
                try {
                    NativeHostInstaller::resolveRoot($manifest, $path);
                    self::fail('Invalid root accepted.');
                } catch (\RuntimeException $error) {
                    self::assertNotSame('', $error->getMessage());
                }
            }
            foreach ([null, 42, [], ''] as $binding) {
                $invalid = $manifest;
                unset($invalid['host']['installation_root_binding']);
                $invalid['host']['installation_root'] = $binding;
                try {
                    NativeHostInstaller::resolveRoot($invalid, $root);
                    self::fail('Invalid bound root type accepted.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('authoritative host root', $error->getMessage());
                }
            }
            self::assertSame(['.', '..'], scandir($root));
        } finally {
            NativeBuildFiles::remove($root);
        }
    }

    #[\PHPUnit\Framework\Attributes\RequiresMethod(\axenox\PackageManager\Common\Updater\UpdateDownloader::class, 'isNativeOta')]
    public function testPackageManagerPassesExplicitNativeRootAsOneLiteralArgvValue(): void
    {
        $root = NativeTestWorkspace::create('pm-native-root');
        try {
            $program = $root . DIRECTORY_SEPARATOR . 'argv fixture.php';
            $output = $root . DIRECTORY_SEPARATOR . 'argv.json';
            file_put_contents($program, '<?php file_put_contents(' . var_export($output, true) . ',json_encode(array_slice($argv,1)));');
            $explicit = $root . DIRECTORY_SEPARATOR . 'root with spaces & literal';
            $installer = new SelfUpdateInstaller($program, $root, PHP_BINARY, $explicit);
            iterator_to_array($installer->install());
            self::assertTrue($installer->getInstallationSuccess());
            self::assertSame(['--root', $explicit], json_decode(file_get_contents($output), true));
            $legacy = new SelfUpdateInstaller($program, $root, PHP_BINARY);
            iterator_to_array($legacy->install());
            self::assertTrue($legacy->getInstallationSuccess());
            self::assertSame([], json_decode(file_get_contents($output), true));
        } finally {
            NativeBuildFiles::remove($root);
        }
    }
}
