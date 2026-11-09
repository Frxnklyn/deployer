<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\DeploymentTask;
use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Common\Pipelines\NativeDeploymentPackage;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Pipelines\Deploy\ManualTransfer;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real local tar/package operations; no host writes, network or retained fixtures.
 *
 * @author Brooklyn Fränzschky
 */
class ManualTransferTest extends TestCase
{
    private string $root;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = \axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace::create('manual');
        mkdir($this->root . DIRECTORY_SEPARATOR . 'builds');
        mkdir($this->root . DIRECTORY_SEPARATOR . 'vendor');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'fixture.php', '<?php return "fixture";');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'composer.json', '{"require":{}}');
        NativeBuildFiles::command('tar', [
            '-czf', $this->archivePath(), '--', 'vendor', 'composer.json'
        ], $this->root, 30);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        NativeBuildFiles::remove($this->root);
    }

    /**
     * @return string
     */
    private function archivePath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . '1.0+fixture.tar.gz';
    }

    /**
     * Real dependencies contain safe documentation names with spaces and parentheses.
     *
     * @return void
     */
    public function testDependencyDocumentationNamesCanBeValidatedAndExtracted(): void
    {
        $name = 'PHP Charting Libraries (IFrame).txt';
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . $name, 'fixture documentation');
        NativeBuildFiles::command('tar', ['-czf', $this->archivePath(), '--', 'vendor', 'composer.json'], $this->root, 30);
        $destination = $this->root . DIRECTORY_SEPARATOR . 'extracted';
        mkdir($destination);
        NativeDeploymentPackage::validateArchive($this->archivePath(), $destination);
        self::assertSame('fixture documentation', file_get_contents($destination . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . $name));
    }

    /**
     * @return ReleaseDeploymentTask
     */
    private function task(): ReleaseDeploymentTask
    {
        return new ReleaseDeploymentTask('deployment-1', 'host-1', 'build-1', '1.0+fixture', $this->root);
    }

    /**
     * @param string $os
     * @return ManualTransfer
     */
    private function pipeline(string $os = 'windows'): ManualTransfer
    {
        return (new ManualTransfer($this->createStub(WorkbenchInterface::class)))
            ->setHostOperatingSystem($os)
            ->setHostPathToInstallationRoot($os === 'windows' ? 'C:\\private\\installation' : '/srv/installation')
            ->setHostPhpExecutable('php');
    }

    /**
     * Packages preserve payload identity/configuration without implying installation.
     *
     * @return void
     */
    public function testRealPackagesPreservePayloadIdentityAndRemainDistinctPerAttempt(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->importUxonObject(new UxonObject([
            'config' => ['Fixture.config.json' => ['password' => 'secret-do-not-log', 'nested' => ['flag' => true]]],
            'local_vendors' => ['client'],
            'shared_dirs' => [],
            'copy_dirs' => ['data'],
            'keep_releases' => -1
        ]));
        $settings = $pipeline->exportUxonObject()->toJson();
        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $generator = $pipeline->run($this->task());
            $log = implode('', iterator_to_array($generator));
            self::assertStringNotContainsString('secret-do-not-log', $log);
            $result = $generator->getReturn();
            $results[] = $result;
            self::assertSame(60, $result->getStatus());
            self::assertSame(hash_file('sha256', $result->artifactPath), $result->sha256);
            $package = new \PharData($result->artifactPath);
            self::assertCount(8, $package);
            $manifest = json_decode($package['manifest.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(hash_file('sha256', $this->archivePath()), $manifest['payload']['sha256']);
            self::assertSame(file_get_contents($this->archivePath()), $package['build.tar.gz']->getContent());
            self::assertSame('deployment-1', $manifest['deployment_id']);
            self::assertSame('host-1', $manifest['host_id']);
            self::assertSame('build-1', $manifest['build_id']);
            self::assertTrue($manifest['installer_included']);
            self::assertSame('prepared_not_installed', $manifest['outcome']);
            self::assertSame('secret-do-not-log', $manifest['pipeline']['config']['Fixture.config.json']['password']);
            self::assertSame([], $manifest['pipeline']['local_packages']);
            self::assertSame(['data'], $manifest['pipeline']['copy_dirs']);
            self::assertSame('C:\\private\\installation', $manifest['host']['installation_root']);
            self::assertArrayNotHasKey('relative_deploy_path', $manifest['host']);
            self::assertStringContainsString('Get-FileHash', $result->instructions);
            self::assertStringContainsString('php installer.php --root', $result->instructions);
            self::assertSame([], glob(dirname($result->artifactPath) . DIRECTORY_SEPARATOR . '.prepare-*'));
        }
        self::assertNotSame($results[0]->artifactPath, $results[1]->artifactPath);
        self::assertSame($settings, $pipeline->exportUxonObject()->toJson());
        $results[0]->discard();
        self::assertFileDoesNotExist($results[0]->artifactPath);
        self::assertFileExists($results[1]->artifactPath);
    }

    /**
     * @return void
     */
    public function testUnixInstructionsAndMissingArtifactCleanup(): void
    {
        $pipeline = $this->pipeline('linux');
        $generator = $pipeline->run($this->task());
        iterator_to_array($generator);
        self::assertStringContainsString('sha256sum', $generator->getReturn()->instructions);
        unlink($this->archivePath());
        try {
            iterator_to_array($pipeline->run($this->task()));
            self::fail('Missing build must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '.prepare-*'));
        }
    }

    /**
     * @return void
     */
    public function testGenericTaskIsRejectedBeforeFilesystemSideEffects(): void
    {
        try {
            iterator_to_array($this->pipeline()->run(new DeploymentTask('deployment-1', 'host-1')));
            self::fail('Manual release deployment must reject a generic task.');
        } catch (\InvalidArgumentException $error) {
            self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . '.native-deployments');
        }
    }

    /**
     * @return array
     */
    public static function unsafeEntries(): array
    {
        return [
            'traversal' => ['../outside', '0'],
            'absolute' => ['/outside', '0'],
            'windows' => ['C:/outside', '0'],
            'backslash' => ['vendor\\outside', '0'],
            'symlink' => ['vendor/link', '2'],
            'hardlink' => ['vendor/link', '1'],
            'device' => ['vendor/device', '3'],
            'auth' => ['vendor/auth.json', '0'],
            'private-key' => ['vendor/private.pem', '0'],
            'environment' => ['vendor/.env.local', '0'],
            'windows-device' => ['vendor/CON.php', '0'],
            'windows-alias' => ['vendor/file.', '0'],
            'unknown-extension' => ['metadata', 'g']
        ];
    }

    /**
     * Constructs raw tar headers so path normalization cannot hide unsafe names.
     *
     * @param string $name
     * @param string $type
     * @param string $contents
     * @return string
     */
    private static function header(string $name, string $type, string $contents = ''): string
    {
        $header = str_pad($name, 100, "\0") . sprintf("%07o\0%07o\0%07o\0%011o\0%011o\0", 0644, 0, 0, strlen($contents), 0)
            . '        ' . $type . str_repeat("\0", 100) . "ustar\0" . '00' . str_repeat("\0", 247);
        $header = substr_replace($header, sprintf("%06o\0 ", array_sum(unpack('C*', $header))), 148, 8);
        return $header . str_pad($contents, (int) (ceil(strlen($contents) / 512) * 512), "\0");
    }

    /**
     * @param string $name
     * @param string $type
     * @return void
     */
    #[DataProvider('unsafeEntries')]
    public function testUnsafeArchivesFailWithoutRetainingPackages(string $name, string $type): void
    {
        file_put_contents($this->archivePath(), gzencode(self::header($name, $type) . str_repeat("\0", 1024)));
        try {
            iterator_to_array($this->pipeline()->run($this->task()));
            self::fail('Unsafe archive must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Unsafe or unsupported build archive entry.', $error->getMessage());
            self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz'));
            self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '.prepare-*'));
        }
    }

    /**
     * @return void
     */
    public function testDuplicateTruncatedAndTamperedArchivesFail(): void
    {
        foreach ([
            self::header('vendor/a.php', '0') . self::header('vendor/A.php', '0') . str_repeat("\0", 1024),
            substr(self::header('vendor/a.php', '0', 'payload'), 0, 600),
            substr_replace(self::header('vendor/a.php', '0'), 'x', 0, 1) . str_repeat("\0", 1024),
            self::header('vendor/a.php', '0') . str_repeat("\0", 1024) . 'trailing-data'
        ] as $tar) {
            file_put_contents($this->archivePath(), gzencode($tar));
            try {
                NativeDeploymentPackage::validateArchive($this->archivePath());
                self::fail('Invalid archive must fail.');
            } catch (\RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }

    }

    /**
     * POSIX long paths are accepted, but their raw metadata cannot hide traversal.
     *
     * @return void
     */
    public function testPosixPathMetadataIsInspectedBeforePublication(): void
    {
        foreach (['vendor/' . str_repeat('nested/', 20) . 'file.php', '../outside'] as $name) {
            $record = 'path=' . $name . "\n";
            $length = strlen($record) + 2;
            while ($length !== strlen((string) $length) + 1 + strlen($record)) {
                $length = strlen((string) $length) + 1 + strlen($record);
            }
            $tar = self::header('metadata', 'x', $length . ' ' . $record)
                . self::header('placeholder', '0', 'fixture') . str_repeat("\0", 1024);
            file_put_contents($this->archivePath(), gzencode($tar));
            if ($name === '../outside') {
                try {
                    NativeDeploymentPackage::validateArchive($this->archivePath());
                    self::fail('PAX traversal must fail.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Unsafe or unsupported build archive entry.', $error->getMessage());
                }
            } else {
                NativeDeploymentPackage::validateArchive($this->archivePath());
                self::assertFileExists($this->archivePath());
            }
        }
    }

    /**
     * Protection is real Windows DACL/POSIX mode protection, not just an intended chmod.
     *
     * @return void
     */
    public function testPublishedPackagesArePrivateAndChangedArtifactsAreNotDiscarded(): void
    {
        $generator = $this->pipeline()->run($this->task());
        iterator_to_array($generator);
        $result = $generator->getReturn();
        $directory = dirname($result->artifactPath);
        self::assertSame("Require all denied\n", file_get_contents($directory . DIRECTORY_SEPARATOR . '.htaccess'));
        self::assertFileExists($directory . DIRECTORY_SEPARATOR . 'web.config');
        if (PHP_OS_FAMILY === 'Windows') {
            $script = '$a=Get-Acl -LiteralPath $env:EXFACE_NATIVE_PRIVATE_DIRECTORY;'
                . '$expected=@($a.GetOwner([System.Security.Principal.SecurityIdentifier]).Value,"S-1-5-18") | Select-Object -Unique;'
                . '$actual=@($a.Access | ForEach-Object {'
                . 'if($_.IsInherited -or $_.AccessControlType -ne "Allow" -or $_.FileSystemRights -ne "FullControl"){exit 1};'
                . '$_.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value});'
                . 'if(-not $a.AreAccessRulesProtected -or @(Compare-Object @($expected) @($actual)).Count -ne 0){exit 1}';
            $output = CliCommandRunner::runCliCommandIntoArray('powershell.exe', [
                '-NoProfile', '-NonInteractive', '-EncodedCommand',
                base64_encode(implode("\0", str_split($script)) . "\0")
            ], $directory, [0], 30, ['EXFACE_NATIVE_PRIVATE_DIRECTORY' => $directory]);
            self::assertSame(0, $output['exit_code']);
        } else {
            self::assertSame(0700, fileperms($directory) & 0777);
            self::assertSame(0600, fileperms($result->artifactPath) & 0777);
        }
        file_put_contents($result->artifactPath, 'changed-after-publication');
        $result->discard();
        self::assertSame('changed-after-publication', file_get_contents($result->artifactPath));
    }

    /**
     * Local directory links must not redirect either inputs or protected output.
     *
     * @return void
     */
    public function testFilesystemLinksCannotEscapeTheProject(): void
    {
        $target = $this->root . DIRECTORY_SEPARATOR . 'outside';
        mkdir($target);
        file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'do not remove');
        $link = $this->root . DIRECTORY_SEPARATOR . '.native-deployments';
        if (PHP_OS_FAMILY === 'Windows') {
            NativeBuildFiles::command('cmd.exe', ['/d', '/c', 'mklink', '/J', $link, $target], $this->root, 30);
        } elseif (! symlink($target, $link)) {
            self::fail('Cannot create the link fixture.');
        }
        self::assertTrue(NativeBuildFiles::isLink($link));
        try {
            iterator_to_array($this->pipeline()->run($this->task()));
            self::fail('Linked package directory must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Unsafe native deployment package directory.', $error->getMessage());
            self::assertSame('do not remove', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
        }
        NativeBuildFiles::remove($link);
        $link = $this->root . DIRECTORY_SEPARATOR . 'project-link';
        if (PHP_OS_FAMILY === 'Windows') {
            NativeBuildFiles::command('cmd.exe', ['/d', '/c', 'mklink', '/J', $link, $target], $this->root, 30);
        } elseif (! symlink($target, $link)) {
            self::fail('Cannot create the link fixture.');
        }
        try {
            NativeDeploymentPackage::projectPath($this->root, 'project-link');
            self::fail('Linked project path must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Project paths may not contain links.', $error->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testUnsafeConfigurationAndProjectPathsFailBeforePreparation(): void
    {
        foreach ([
            ['shared_dirs' => ['../outside']],
            ['copy_dirs' => ['vendor']],
            ['shared_dirs' => ['data'], 'copy_dirs' => ['data/nested']],
            ['config' => ['../Secret.config.json' => []]],
            ['local_packages' => ['../package']],
            ['php_memory_limit' => ['invalid']],
            ['keep_releases' => 0],
            ['keep_releases' => 1],
            ['shared_dirs' => ['data', 'DATA']],
            ['local_packages' => ['exface/core']],
            ['copy_dirs' => ['nested/path']],
            ['local_vendors' => ['axenox']],
            ['copy_dirs' => ['CON']],
            ['config' => ['CON.config.json' => []]],
            ['config' => ['Fixture.config.json' => ['secret-value']]]
        ] as $settings) {
            try {
                $pipeline = $this->pipeline();
                $pipeline->importUxonObject(new UxonObject($settings));
                iterator_to_array($pipeline->run($this->task()));
                self::fail('Invalid configuration must fail.');
            } catch (\RuntimeException | \InvalidArgumentException | \TypeError $error) {
                self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . '.native-deployments');
                self::assertStringNotContainsString('secret-value', $error->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        NativeDeploymentPackage::projectPath($this->root, '../outside');
    }

    /**
     * Direct package callers cannot bypass the same bounded installer settings.
     *
     * @return void
     */
    public function testDirectPreparationRejectsUnsupportedSettingsBeforePublishing(): void
    {
        foreach ([
            ['shared_dirs' => ['config']], ['local_packages' => ['client/CON']], ['keep_releases' => 1],
            ['copy_dirs' => 'secret-value'], ['copy_dirs' => [['secret-value']]],
            ['local_vendors' => ['named' => 'client']],
            ['config' => 'secret-value'], ['php_memory_limit' => ['secret-value']],
            ['unknown' => 'secret-value']
        ] as $settings) {
            try {
                NativeDeploymentPackage::prepare($this->task(), [], $settings);
                self::fail('Unsupported direct preparation must fail.');
            } catch (\RuntimeException $error) {
                self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . '.native-deployments');
                self::assertStringNotContainsString('secret-value', $error->getMessage());
            }
        }
    }

    /**
     * @return void
     */
    public function testNativeDefaultsAreRunnableAndExplicit(): void
    {
        self::assertSame([
            'config' => [], 'keep_releases' => 4, 'shared_dirs' => ['backup', 'data', 'logs', 'translations'], 'copy_dirs' => ['config'],
            'local_vendors' => [], 'local_packages' => [], 'php_memory_limit' => '2G'
        ], $this->pipeline()->exportUxonObject()->toArray());
        iterator_to_array($this->pipeline()->run($this->task()));
        self::assertCount(1, glob($this->root . DIRECTORY_SEPARATOR . '.native-deployments' . DIRECTORY_SEPARATOR . '*.tar.gz'));
    }
}
