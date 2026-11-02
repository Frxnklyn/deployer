<?php
namespace axenox\Deployer\Common\Execution;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs Composer-backed builds and produces their archive, lockfile and package BOM.
 */
class ComposerBuildExecutor
{
    /**
     * Accepts the Composer-backed legacy values and rejects removed build implementations.
     *
     * @param string $recipe
     */
    public function assertSupportedRecipe(string $recipe): void
    {
        if (! in_array($recipe, ['ComposerInstall', 'ComposerInstallAssetFix'], true)) {
            throw new RuntimeException(
                'Build recipe "' . $recipe . '" is not supported by the native build executor. '
                . 'CloneLocal and CustomBuild recipes must be migrated to ComposerInstall.'
            );
        }
    }

    /**
     * Installs the build dependencies and creates the distributable archive.
     *
     * @param string $projectPath
     * @param string $archivePath
     * @param string $phpExecutable
     * @param string $composerHome
     * @param int $timeout
     * @param string[] $sourceFiles
     * @return \Generator<int, string, mixed, array{composer_lock: ?string, package_bom: ?string}>
     */
    public function execute(
        string $projectPath,
        string $archivePath,
        string $phpExecutable,
        string $composerHome,
        int $timeout,
        array $sourceFiles
    ): \Generator {
        $this->validateSourceFiles($projectPath, $sourceFiles);
        $environment = ['COMPOSER_HOME' => $composerHome];

        yield from $this->runProcess(
            [$phpExecutable, 'composer.phar', 'install', '--prefer-dist', '--no-interaction'],
            $projectPath,
            $environment,
            $timeout
        );

        foreach ($sourceFiles as $sourceFile) {
            if (! file_exists($projectPath . DIRECTORY_SEPARATOR . $sourceFile)) {
                throw new RuntimeException('Build archive source not found after Composer install: ' . $sourceFile);
            }
        }
        if (! is_dir($projectPath . DIRECTORY_SEPARATOR . 'vendor')) {
            throw new RuntimeException('Composer install completed without creating the vendor folder.');
        }
        if (! is_file($projectPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
            throw new RuntimeException('Composer install completed without creating vendor/autoload.php.');
        }

        $this->preparePackageManagerComposerMetadata($projectPath . DIRECTORY_SEPARATOR . 'composer.json');

        $licensePath = $projectPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'licenses.json';
        $packageBomPath = $projectPath . DIRECTORY_SEPARATOR . 'package_bom.json';
        if (is_file($licensePath) && ! copy($licensePath, $packageBomPath)) {
            throw new RuntimeException('Cannot stage the build package BOM.');
        }

        $archiveDirectory = dirname($archivePath);
        if (! is_dir($archiveDirectory) && ! mkdir($archiveDirectory, 0775, true) && ! is_dir($archiveDirectory)) {
            throw new RuntimeException('Cannot create build archive directory: ' . $archiveDirectory);
        }

        yield from $this->runProcess(
            array_merge(['tar', '-czf', $archivePath, '-C', $projectPath], $sourceFiles),
            $projectPath,
            [],
            $timeout
        );

        $lockPath = $projectPath . DIRECTORY_SEPARATOR . 'composer.lock';
        $composerLock = is_file($lockPath) ? file_get_contents($lockPath) : null;
        if ($composerLock === false) {
            throw new RuntimeException('Cannot read the generated composer.lock.');
        }

        $packageBom = is_file($packageBomPath) ? file_get_contents($packageBomPath) : null;
        if ($packageBom === false) {
            throw new RuntimeException('Cannot read the staged build package BOM.');
        }

        return [
            'composer_lock' => $composerLock,
            'package_bom' => $packageBom
        ];
    }

    /**
     * Adds the PackageManager install hooks to the Composer manifest included in a build.
     *
     * @param string $composerJsonPath
     */
    private function preparePackageManagerComposerMetadata(string $composerJsonPath): void
    {
        $json = file_get_contents($composerJsonPath);
        if ($json === false) {
            throw new RuntimeException('Cannot read the staged composer.json.');
        }
        $composer = json_decode($json, true);
        if (! is_array($composer)) {
            throw new RuntimeException('The staged composer.json is not valid JSON.');
        }

        $composer['autoload'] = [
            'psr-0' => [
                'axenox\\PackageManager' => 'vendor/'
            ]
        ];
        $composer['optimize-autoloader'] = true;
        $composer['scripts'] = [
            'post-update-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishUpdate'],
            'post-install-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishInstall'],
            'post-package-install' => ['axenox\\PackageManager\\StaticInstaller::composerFinishPackageInstall'],
            'post-package-update' => ['axenox\\PackageManager\\StaticInstaller::composerFinishPackageUpdate']
        ];

        $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($composerJsonPath, $encoded) === false) {
            throw new RuntimeException('Cannot write the PackageManager Composer hooks to composer.json.');
        }
    }

    /**
     * Executes a process with argument-vector handling and yields its live output.
     *
     * @param string[] $command
     * @param string $workingDirectory
     * @param array<string, string> $environment
     * @param int $timeout
     * @return \Generator<int, string>
     */
    private function runProcess(array $command, string $workingDirectory, array $environment, int $timeout): \Generator
    {
        $process = new Process($command, $workingDirectory, $environment, null, $timeout);
        $process->start();
        foreach ($process as $output) {
            yield $output;
        }
        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            $message = 'Process failed with exit code ' . $process->getExitCode();
            if ($errorOutput !== '') {
                $message .= ': ' . $errorOutput;
            }
            throw new RuntimeException($message);
        }
    }

    /**
     * Rejects source entries that could escape the build workspace.
     *
     * @param string $projectPath
     * @param string[] $sourceFiles
     */
    private function validateSourceFiles(string $projectPath, array $sourceFiles): void
    {
        if ($sourceFiles === []) {
            throw new RuntimeException('No build archive source files were configured.');
        }

        foreach ($sourceFiles as $sourceFile) {
            if (! is_string($sourceFile) || $sourceFile === '' || str_contains($sourceFile, "\0")) {
                throw new RuntimeException('A build archive source entry is invalid.');
            }
            $normalized = str_replace('\\', '/', $sourceFile);
            if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
                throw new RuntimeException('Build archive sources must be relative to the build workspace.');
            }
            foreach (explode('/', $normalized) as $segment) {
                if ($segment === '..') {
                    throw new RuntimeException('Build archive sources cannot traverse outside the build workspace.');
                }
            }
        }
    }
}
