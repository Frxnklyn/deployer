<?php
namespace axenox\Deployer\Tests\PHPUnit\Integration;

use axenox\Deployer\Common\Pipelines\BuildTask;
use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Pipelines\Build\ComposerInstall;
use axenox\Deployer\Pipelines\Deploy\ManualTransfer;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Offline Composer build and real Core/PackageManager installation in an owned database.
 *
 * @author Brooklyn Fränzschky
 */
#[Group('real-install')]
class RealNativeInstallationTest extends TestCase
{
    /**
     * Platform requirements remain in manifests for Composer to validate, not mirror.
     *
     * @param string $name
     * @return bool
     */
    private static function isPlatformRequirement(string $name): bool
    {
        return $name === 'php' || str_starts_with($name, 'php-') || $name === 'hhvm'
            || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')
            || in_array($name, ['composer', 'composer-runtime-api', 'composer-plugin-api'], true);
    }

    /**
     * Copies code, never the host installation, authentication or VCS directories.
     * Package Config defaults are required by Core; installation config is not copied.
     *
     * @param string $source
     * @param string $destination
     * @return void
     */
    private function copyPackage(string $source, string $destination): void
    {
        mkdir($destination, 0700, true);
        foreach (new \FilesystemIterator($source) as $entry) {
            $name = $entry->getFilename();
            if (in_array(strtolower($name), ['.git', '.svn', '.hg', 'tests', 'test', 'vendor', 'auth.json',
                '.composer', '.env', '.ssh', '.netrc', '.npmrc', '.git-credentials'], true)
                || $name === 'config' || str_starts_with($name, '.env.')
                || preg_match('/\.(pem|key|p12|pfx)$/i', $name)) {
                continue;
            }
            if (NativeBuildFiles::isLink($entry->getPathname())) {
                throw new \RuntimeException('Local package contains a link: ' . $name);
            }
            $target = $destination . DIRECTORY_SEPARATOR . $name;
            if ($entry->isDir()) {
                $this->copyPackage($entry->getPathname(), $target);
            } elseif (! $entry->isFile() || ! copy($entry->getPathname(), $target)) {
                throw new \RuntimeException('Cannot mirror local package code.');
            }
        }
    }

    /**
     * Uses actual local manifests and installed versions; Composer still resolves all constraints.
     * No remote repositories, scripts, plugins, symlinks or version constraint overrides.
     *
     * @param string $vendor
     * @param string $root
     * @return array
     */
    private function composerManifest(string $vendor, string $root): array
    {
        $installed = json_decode(file_get_contents($vendor . DIRECTORY_SEPARATOR . 'composer'
            . DIRECTORY_SEPARATOR . 'installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_column($installed['packages'] ?? $installed, null, 'name');
        $pending = ['exface/core', 'axenox/packagemanager'];
        $mirrored = [];
        $repositories = [];
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($mirrored[$name]) || self::isPlatformRequirement($name)) {
                continue;
            }
            if (! isset($packages[$name])) {
                throw new \RuntimeException('Real runtime prerequisite is not installed locally: ' . $name);
            }
            $package = $packages[$name];
            $source = realpath($vendor . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR
                . ($package['install-path'] ?? ('..' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name))));
            if ($source === false || ! str_starts_with($source, realpath($vendor) . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Local dependency is not ordinary installed vendor code: ' . $name);
            }
            $manifestFile = $source . DIRECTORY_SEPARATOR . 'composer.json';
            $manifest = is_file($manifestFile)
                ? json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR)
                : array_intersect_key($package, array_flip(['name', 'type', 'require', 'autoload', 'extra',
                    'license', 'bin', 'replace', 'provide', 'conflict']));
            $destination = $root . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $name);
            $this->copyPackage($source, $destination);
            // Repository/authentication/scripts are not dependency code and may refer to live services.
            unset($manifest['repositories'], $manifest['scripts'], $manifest['config'], $manifest['require-dev']);
            file_put_contents($destination . DIRECTORY_SEPARATOR . 'composer.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $repositories[] = ['type' => 'path', 'url' => $destination,
                'options' => ['symlink' => false, 'reference' => 'none', 'versions' => [$name => $package['version']]]];
            $mirrored[$name] = true;
            $pending = array_merge($pending, array_keys($manifest['require'] ?? []));
        }
        $repositories[] = ['packagist.org' => false];
        return [
            'name' => 'deployer/isolated-runtime-test',
            'require' => [
                'exface/core' => $packages['exface/core']['version'] . ' as 1.0.x-dev',
                'axenox/packagemanager' => $packages['axenox/packagemanager']['version'] . ' as 1.0.x-dev'
            ],
            'minimum-stability' => 'dev',
            'repositories' => $repositories,
            'config' => ['allow-plugins' => false, 'audit' => ['block-insecure' => false]]
        ];
    }

    /**
     * Runs an isolated PHP process without loading the repository's autoloader.
     * Output is retained only for test failures, with generated credentials redacted.
     *
     * @param array $arguments
     * @param string $cwd
     * @param string $secret
     * @return array
     */
    private function execute(array $arguments, string $cwd, string $secret = ''): array
    {
        $process = new \Symfony\Component\Process\Process(array_merge([PHP_BINARY], $arguments), $cwd);
        $process->setTimeout(7500);
        $exit = $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();
        return [$exit, $secret === '' ? $output : str_replace($secret, '[redacted]', $output)];
    }

    /**
     * Builds a real package twice and checks first-install and update model/schema/startup.
     * The admin connection never selects or modifies an existing database.
     *
     * @return void
     */
    public function testOfflineBuildRealStandaloneInstallAndUpdate(): void
    {
        if (getenv('EXFACE_DEPLOYER_REAL_INSTALL_TEST') !== '1') {
            self::markTestSkipped('Set EXFACE_DEPLOYER_REAL_INSTALL_TEST=1 for owned-database real installation.');
        }
        $base = NativeTestWorkspace::realInstallBase();
        self::assertTrue(extension_loaded('mysqli'), 'Real integration requires mysqli.');
        $vendor = dirname(__DIR__, 5);
        $composer = dirname($vendor) . DIRECTORY_SEPARATOR . 'composer.phar';
        self::assertFileExists($composer, 'A locally installed Composer PHAR is required; no downloads.');
        $root = NativeTestWorkspace::create('real-install', $base);
        mkdir($root . DIRECTORY_SEPARATOR . 'builds', 0700);
        mkdir($root . DIRECTORY_SEPARATOR . 'host', 0700);
        $database = 'deployer_real_' . bin2hex(random_bytes(8));
        $user = 'dep_' . bin2hex(random_bytes(8));
        $password = bin2hex(random_bytes(24));
        $admin = null;
        $createdDatabase = false;
        $createdUser = false;
        $oldNetwork = getenv('COMPOSER_DISABLE_NETWORK');
        putenv('COMPOSER_DISABLE_NETWORK=1');
        try {
            $manifest = $this->composerManifest($vendor, $root);
            $pipeline = new ComposerInstall(new Workbench());
            $pipeline->setComposerJson(new UxonObject($manifest));
            $build = $pipeline->run(new BuildTask($root, $root . DIRECTORY_SEPARATOR . 'builds',
                'real-v1', PHP_BINARY, $composer));
            iterator_to_array($build);
            self::assertFileExists($build->getReturn()->getArchivePath());
            $admin = new \mysqli('127.0.0.1',
                getenv('EXFACE_DEPLOYER_TEST_MYSQL_USER'),
                getenv('EXFACE_DEPLOYER_TEST_MYSQL_PASSWORD') ?: '', '',
                (int) getenv('EXFACE_DEPLOYER_TEST_MYSQL_PORT'));
            $admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
            $createdDatabase = true;
            $admin->query("CREATE USER '{$user}'@'127.0.0.1' IDENTIFIED BY '{$password}'");
            $createdUser = true;
            $admin->query("GRANT ALL ON `{$database}`.* TO '{$user}'@'127.0.0.1'");
            $connector = ['host' => '127.0.0.1',
                'port' => (int) getenv('EXFACE_DEPLOYER_TEST_MYSQL_PORT'),
                'dbase' => $database, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4'];
            $firstRelease = null;
            $counts = null;
            foreach (['real-v1', 'real-v2'] as $version) {
                if ($version === 'real-v2') {
                    $build = $pipeline->run(new BuildTask($root, $root . DIRECTORY_SEPARATOR . 'builds',
                        $version, PHP_BINARY, $composer));
                    iterator_to_array($build);
                }
                $manual = (new ManualTransfer(new Workbench()))
                    ->setHostOperatingSystem(PHP_OS_FAMILY === 'Windows' ? 'Windows' : 'Linux')
                    ->setHostPathToInstallationRoot($root . DIRECTORY_SEPARATOR . 'host')
                    ->setHostPhpExecutable(PHP_BINARY);
                $manual->importUxonObject(new UxonObject(['config' => ['System.config.json' => [
                    'METAMODEL.CONNECTOR_CONFIG' => $connector,
                    'SERVER.INSTALLATION_NAME' => $database,
                    'INSTALLER.SERVER_INSTALLER.CLASS' => '\\exface\\Core\\CommonLogic\\AppInstallers\\ApacheServerInstaller',
                    'INSTALLER.SCHEDULERINSTALLER.DISABLED' => true
                ]]]));
                $run = $manual->run(new ReleaseDeploymentTask('isolated', 'isolated', 'isolated', $version, $root));
                iterator_to_array($run);
                $transport = $root . DIRECTORY_SEPARATOR . 'transport-' . $version;
                mkdir($transport, 0700);
                $copy = $transport . DIRECTORY_SEPARATOR . 'package.tar.gz';
                copy($run->getReturn()->artifactPath, $copy);
                self::assertSame($run->getReturn()->sha256, hash_file('sha256', $copy));
                (new \PharData($copy))->extractTo($transport);
                $run->getReturn()->discard();
                [$exit, $output] = $this->execute([$transport . DIRECTORY_SEPARATOR . 'installer.php',
                    '--root', $root . DIRECTORY_SEPARATOR . 'host'], $transport, $password);
                self::assertSame(0, $exit, $output);
                clearstatcache(true);
                $current = realpath($root . DIRECTORY_SEPARATOR . 'host' . DIRECTORY_SEPARATOR . 'current');
                self::assertNotFalse($current);
                self::assertFileExists($current . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR
                    . 'exface' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'index.php');
                self::assertFileExists($current . DIRECTORY_SEPARATOR . '.htaccess');
                self::assertFileExists($current . DIRECTORY_SEPARATOR . '.native-install-result');
                if ($firstRelease !== null) {
                    self::assertNotSame($firstRelease, $current);
                    self::assertDirectoryExists($firstRelease);
                }
                $firstRelease = $current;
                $admin->select_db($database);
                $actual = [];
                foreach (['exf_app', 'exf_object', 'exf_attribute', 'exf_page'] as $table) {
                    $actual[$table] = (int) $admin->query("SELECT COUNT(*) FROM `{$table}`")->fetch_row()[0];
                    self::assertGreaterThan(0, $actual[$table], $table);
                }
                self::assertSame(2, (int) $admin->query("SELECT COUNT(*) FROM exf_app WHERE app_alias IN ('exface.Core','axenox.PackageManager')")->fetch_row()[0]);
                if ($counts !== null) {
                    self::assertSame($counts, $actual, 'Same-code update must not duplicate models.');
                }
                $counts = $actual;
                $probe = $root . DIRECTORY_SEPARATOR . 'startup.php';
                file_put_contents($probe, '<?php require $argv[1] . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";'
                    . '$w = new \exface\Core\CommonLogic\Workbench(); try { $w->start();'
                    . 'if (!$w->isInstalled() || !$w->isStarted()) { throw new \RuntimeException("Not installed/started"); }'
                    . '$o = $w->model()->getObject("exface.Core.APP");'
                    . 'echo json_encode(["path"=>$w->getInstallationPath(),"alias"=>$o->getAliasWithNamespace()]);'
                    . '} finally { $w->stop(); }');
                [$exit, $output] = $this->execute([$probe, $current], $current, $password);
                self::assertSame(0, $exit, $output);
                $startup = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('exface.Core.APP', $startup['alias']);
                self::assertSame($current, realpath($startup['path']));
            }
        } finally {
            try {
                if ($admin !== null) {
                    try {
                        if ($createdDatabase) {
                            $admin->query("DROP DATABASE `{$database}`");
                            self::assertSame(0, $admin->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '{$database}'")->fetch_row()[0] + 0);
                        }
                    } finally {
                        try {
                            if ($createdUser) {
                                $admin->query("DROP USER '{$user}'@'127.0.0.1'");
                            }
                        } finally {
                            $admin->close();
                        }
                    }
                }
            } finally {
                putenv($oldNetwork === false ? 'COMPOSER_DISABLE_NETWORK' : 'COMPOSER_DISABLE_NETWORK=' . $oldNetwork);
                NativeBuildFiles::remove($root);
                self::assertDirectoryDoesNotExist($root);
            }
        }
    }
}
