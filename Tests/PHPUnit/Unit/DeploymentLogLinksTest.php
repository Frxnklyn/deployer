<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Deploy;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies deployment log link replacement without running deployments or changing files.
 */
class DeploymentLogLinksTest extends TestCase
{
    /**
     * Exercises the production formatter with isolated installation and cache paths.
     */
    private function format(string $message, string $basePath): string
    {
        $files = $this->createMock(Filemanager::class);
        $files->method('getPathToCacheFolder')->willReturn($basePath . 'cache');
        $workbench = $this->createMock(WorkbenchInterface::class);
        $workbench->method('getInstallationPath')->willReturn($basePath);
        $workbench->method('getUrl')->willReturn('https://deployer.example/');
        $workbench->method('filemanager')->willReturn($files);
        $action = $this->getMockBuilder(Deploy::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBasePath', 'getWorkbench'])
            ->getMock();
        $action->method('getBasePath')->willReturn($basePath);
        $action->method('getWorkbench')->willReturn($workbench);

        return (new \ReflectionMethod(Deploy::class, 'replaceFilePathsWithHyperlinks'))
            ->invoke($action, $message);
    }

    /**
     * Windows paths, repeated paths and spaces must not truncate the surrounding log.
     */
    public function testWindowsPathsBecomeDownloadLinks(): void
    {
        $base = 'C:\\exface\\';
        $path = $base . 'data\\deployer\\demo\\release host.phx';
        $message = 'Created "' . $path . '". Run "' . $path . '" on the host.';
        $url = 'https://deployer.example/data/deployer/demo/release host.phx';

        self::assertSame('Created "' . $url . '". Run "' . $url . '" on the host.', $this->format($message, $base));
    }

    /**
     * Unix paths receive the same treatment and distinct artifact links remain distinct.
     */
    public function testMultipleUnixPathsBecomeDownloadLinks(): void
    {
        $base = '/srv/exface/';
        $message = 'Copy "' . $base . 'data/one.phx" then "' . $base . 'data/two.phx".';
        self::assertSame(
            'Copy "https://deployer.example/data/one.phx" then "https://deployer.example/data/two.phx".',
            $this->format($message, $base)
        );
    }

    /**
     * Messages without quoted installation paths must be returned unchanged.
     */
    public function testUnrelatedLogTextIsPreserved(): void
    {
        $message = 'Download "https://example.org/release.phx"; keep "/other/release.phx" unchanged.';
        self::assertSame($message, $this->format($message, '/srv/exface/'));
        self::assertSame('', $this->format('', '/srv/exface/'));
    }
}
