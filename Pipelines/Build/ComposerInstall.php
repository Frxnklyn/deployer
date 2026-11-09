<?php
namespace axenox\Deployer\Pipelines\Build;

use axenox\Deployer\Common\Pipelines\ComposerBuildBom;
use axenox\Deployer\Common\Pipelines\AssetPackagistCache;
use axenox\Deployer\Common\Pipelines\BuildResult;
use axenox\Deployer\Common\Pipelines\BuildTask;
use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Interfaces\Pipelines\BuildPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Uxon\UxonSchema;

/**
 * Installs Composer dependencies in a private workspace and publishes a tar.gz build.
 *
 * UXON configures Composer-specific settings only; the action supplies the
 * project paths, PHP executable, Composer executable, build identity and
 * run-specific project inputs. Commands emit phase messages only because
 * Composer diagnostics can contain credentials. Composer scripts and plugins
 * execute as trusted project code.
 *
 * @author Brooklyn Fränzschky
 */
final class ComposerInstall implements BuildPipelineInterface
{
    use ImportUxonObjectTrait;

    private UxonObject $composerJson;
    private UxonObject $composerAuth;
    private int $composerTimeout = 1200;
    private bool $assetPackagistCacheFix = false;
    private array $sourceFiles = ['vendor', 'composer.json', 'composer.lock', 'composer.phar'];

    /**
     * Initializes empty pipeline-owned Composer settings.
     *
     * The workbench parameter is part of Core's selectable-component constructor
     * contract; this pipeline does not use it to resolve run inputs.
     *
     * @param WorkbenchInterface $workbench Core workbench passed by the component factory.
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->composerJson = new UxonObject();
        $this->composerAuth = new UxonObject();
    }

    /**
     * Complete Composer project configuration.
     *
     * @uxon-property composer_json
     * @uxon-type object
     * @param UxonObject $value Complete Composer project configuration object.
     * @return $this
     */
    public function setComposerJson(UxonObject $value): self
    {
        $this->composerJson = $value->copy();
        return $this;
    }

    /**
     * Pipeline-specific Composer authentication, combined with action-provided
     * global authentication at run time.
     *
     * @uxon-property composer_auth
     * @uxon-type object
     * @param UxonObject $value Composer authentication object.
     * @return $this
     */
    public function setComposerAuth(UxonObject $value): self
    {
        $this->composerAuth = $value->copy();
        return $this;
    }

    /**
     * Per-operation deadline for Composer and tar; no legacy outer process deadline.
     *
     * @uxon-property composer_timeout
     * @uxon-type integer
     * @uxon-default 1200
     * @param int $value Positive timeout in seconds for each Composer or archive operation.
     * @return $this
     * @throws \InvalidArgumentException If the timeout is less than one second.
     */
    public function setComposerTimeout(int $value): self
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('Composer timeout must be positive.');
        }
        $this->composerTimeout = $value;
        return $this;
    }

    /**
     * Validated relative paths; required deployment files cannot be removed.
     *
     * @uxon-property source_files
     * @uxon-type string[]
     * @param UxonObject $value List of relative workspace paths to archive.
     * @return $this
     * @throws \InvalidArgumentException If the value is not a string list or omits a required entry.
     */
    public function setSourceFiles(UxonObject $value): self
    {
        $entries = $value->toArray();
        if (! array_is_list($entries) || array_filter($entries, 'is_string') !== $entries
            || array_diff(['vendor', 'composer.json', 'composer.lock', 'composer.phar'], $entries)) {
            throw new \InvalidArgumentException('source_files must include all four required deployment entries.');
        }
        $this->sourceFiles = array_values(array_unique($entries));
        return $this;
    }

    /**
     * Uses asset identities from the previous successful build of this variant.
     * Missing history fails before Composer starts; it never disables the fix silently.
     *
     * @uxon-property asset_packagist_cache_fix
     * @uxon-type boolean
     * @uxon-default false
     * @param bool $value Whether to carry forward previous successful build asset identities.
     * @return $this
     */
    public function setAssetPackagistCacheFix(bool $value): self
    {
        $this->assetPackagistCacheFix = $value;
        return $this;
    }

    /**
     * Exports copies, never the run-specific replacement.
     *
     * @return UxonObject
     */
    public function exportUxonObject(): UxonObject
    {
        return new UxonObject([
            'composer_json' => $this->composerJson->toArray(),
            'composer_auth' => $this->composerAuth->toArray(),
            'composer_timeout' => $this->composerTimeout,
            'source_files' => $this->sourceFiles,
            'asset_packagist_cache_fix' => $this->assetPackagistCacheFix
        ]);
    }

    /**
     * @return string|null Core UXON schema class for imported pipeline settings.
     */
    public static function getUxonSchemaClass(): ?string
    {
        return UxonSchema::class;
    }

    /**
     * Executes without model access; exceptions leave persistence to the action.
     *
     * @param PipelineTaskInterface $task Native build inputs supplied by the owning action.
     * @return \Generator<int, string, mixed, BuildResult> Progress messages and the completed build evidence.
     * @throws \InvalidArgumentException If the task or its required paths and inputs are invalid.
     * @throws \RuntimeException If installation, evidence capture, archiving or workspace cleanup fails.
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (! $task instanceof BuildTask) {
            throw new \InvalidArgumentException('ComposerInstall requires a native BuildTask.');
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]*$/D', $task->buildName)
            || ! is_dir($task->projectPath) || ! is_dir($task->archiveDirectory)
            || ! is_file($task->composerPhar) || $task->phpExecutable === ''
            || preg_match('/[\x00-\x1f]/', $task->phpExecutable)) {
            throw new \InvalidArgumentException('Invalid native build paths, name or PHP executable.');
        }
        $input = $task->composerJson ?? $this->composerJson->toJson();
        if ($this->assetPackagistCacheFix) {
            $input = AssetPackagistCache::apply($input, $task->previousComposerLock);
        }
        $decoded = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
        if (! $decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('Composer configuration must be a JSON object.');
        }
        // Embedded credentials could otherwise be shipped in composer.json or composer.lock.
        self::validateCredentials((array) $decoded);
        $archive = realpath($task->archiveDirectory) . DIRECTORY_SEPARATOR . $task->buildName . '.tar.gz';
        if (file_exists($archive)) {
            throw new \RuntimeException('The build archive already exists.');
        }
        $workspace = realpath($task->projectPath) . DIRECTORY_SEPARATOR . '.build-' . bin2hex(random_bytes(12));
        if (! mkdir($workspace, 0700)) {
            throw new \RuntimeException('Cannot create the build workspace.');
        }
        $partial = $workspace . DIRECTORY_SEPARATOR . 'build.tar.gz';
        $published = false;
        try {
            yield "Preparing isolated Composer workspace\n";
            self::write($workspace . DIRECTORY_SEPARATOR . 'composer.json', $input);
            if (! copy($task->composerPhar, $workspace . DIRECTORY_SEPARATOR . 'composer.phar')) {
                throw new \RuntimeException('Cannot stage Composer.');
            }
            $home = $workspace . DIRECTORY_SEPARATOR . '.composer';
            if (! mkdir($home, 0700)) {
                throw new \RuntimeException('Cannot create private Composer home.');
            }
            $auth = UxonObject::fromJson($task->composerAuth)->extend($this->composerAuth);
            self::write($home . DIRECTORY_SEPARATOR . 'auth.json', $auth->toJson());
            $secrets = NativeBuildFiles::authenticationSecrets($auth->toArray());
            yield "Installing Composer dependencies (command diagnostics suppressed)\n";
            NativeBuildFiles::command($task->phpExecutable, [
                $workspace . DIRECTORY_SEPARATOR . 'composer.phar', 'install', '--prefer-dist', '--no-interaction', '--no-progress'
            ], $workspace, $this->composerTimeout, [
                'COMPOSER_HOME' => $home,
                'COMPOSER' => 'composer.json',
                'COMPOSER_AUTH' => '{}',
                'COMPOSER_VENDOR_DIR' => 'vendor',
                'COMPOSER_NO_DEV' => '0',
                'COMPOSER_CACHE_DIR' => $home . DIRECTORY_SEPARATOR . 'cache'
            ]);
            if (! is_file($workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')
                || ! is_file($workspace . DIRECTORY_SEPARATOR . 'composer.lock')) {
                throw new \RuntimeException('Composer did not produce vendor/autoload.php and composer.lock.');
            }
            $lock = file_get_contents($workspace . DIRECTORY_SEPARATOR . 'composer.lock');
            self::validateCredentials(json_decode($lock, true, 512, JSON_THROW_ON_ERROR));
            yield "Capturing dependency BOM and preparing installer scripts\n";
            $bom = ComposerBuildBom::capture($workspace, $task->licensesJson);
            (new JsonBOM($bom))->saveToFile($workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'licenses.json');
            $minimalBom = (new JsonBOM($bom))->setFormat('minimal')->buildJson();
            $manifest = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            $manifest['autoload'] = ['psr-0' => ['axenox\\PackageManager' => 'vendor/']];
            $manifest['optimize-autoloader'] = true;
            $manifest['scripts'] = [
                'post-update-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishUpdate'],
                'post-install-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishInstall'],
                'post-package-install' => ['axenox\\PackageManager\\StaticInstaller::composerFinishPackageInstall'],
                'post-package-update' => ['axenox\\PackageManager\\StaticInstaller::composerFinishPackageUpdate']
            ];
            self::write($workspace . DIRECTORY_SEPARATOR . 'composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            foreach ($this->sourceFiles as $entry) {
                NativeBuildFiles::validateEntry($workspace, $entry, $secrets);
            }
            yield "Creating build archive\n";
            NativeBuildFiles::command('tar', array_merge(['-czf', $partial, '--'], $this->sourceFiles), $workspace, $this->composerTimeout);
            if (! is_file($partial) || filesize($partial) === 0) {
                throw new \RuntimeException('Archive command did not produce a build.');
            }
            // Exclusive publication prevents a concurrent run overwriting an existing artifact.
            $destination = @fopen($archive, 'x+b');
            if ($destination === false) {
                throw new \RuntimeException('Cannot publish the build archive exclusively.');
            }
            $published = true;
            try {
                $source = fopen($partial, 'rb');
                try {
                    if (stream_copy_to_stream($source, $destination) !== filesize($partial)) {
                        throw new \RuntimeException('Cannot publish the complete build archive.');
                    }
                } finally {
                    fclose($source);
                }
            } catch (\Throwable $error) {
                fclose($destination);
                unlink($archive);
                $published = false;
                throw $error;
            }
            fclose($destination);
        } finally {
            try {
                NativeBuildFiles::remove($workspace);
            } catch (\Throwable $cleanupError) {
                if ($published) {
                    unlink($archive);
                }
                throw new \RuntimeException('Cannot clean the private build workspace; check filesystem permissions.');
            }
        }
        yield "Composer build complete\n";
        return new BuildResult(99, $archive, $lock, $minimalBom, $input);
    }

    /**
     * Writes an exact string to a private workspace file.
     *
     * @param string $path Destination file path.
     * @param string $contents File contents.
     * @return void
     * @throws \RuntimeException If the complete contents cannot be written.
     */
    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new \RuntimeException('Cannot write a native build workspace file.');
        }
    }

    /**
     * Inspects decoded JSON so escaped slashes cannot hide credential-bearing URLs.
     *
     * Rejects embedded Composer credentials in decoded manifest or lock data.
     *
     * @param array<string|int, mixed> $data Decoded Composer data to inspect recursively.
     * @return void
     * @throws \RuntimeException If credential-bearing fields or URLs are found.
     */
    private static function validateCredentials(array $data): void
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), ['github-oauth', 'gitlab-token', 'http-basic', 'bearer'], true)) {
                throw new \RuntimeException('Use composer_auth rather than inline Composer credentials.');
            }
            if (is_array($value) || is_object($value)) {
                self::validateCredentials((array) $value);
            } elseif (is_string($value) && (preg_match('~[a-z][a-z0-9+.-]*://[^/\s"<>]*@~i', $value)
                || preg_match('~[?&](?:access_token|token|password|api_key)=~i', $value))) {
                throw new \RuntimeException('Composer data contains inline credentials.');
            }
        }
    }
}
