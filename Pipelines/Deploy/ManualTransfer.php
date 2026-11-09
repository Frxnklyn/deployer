<?php
namespace axenox\Deployer\Pipelines\Deploy;

use axenox\Deployer\Common\Pipelines\NativeDeploymentPackage;
use axenox\Deployer\Common\Pipelines\NativeHostInstaller;
use axenox\Deployer\Common\Pipelines\PreparedDeploymentResult;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Uxon\UxonSchema;
use axenox\Deployer\Common\Pipelines\DeploymentHttpContext;
use axenox\Deployer\Interfaces\Pipelines\DeploymentHttpHandlerInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Prepares a private manual-transfer package, without executing a host installer.
 *
 * UXON configures installer and package-preservation options only. The owning
 * action supplies the host root, PHP executable, operating system and any
 * required connection separately; these host values are not exported as
 * prototype configuration.
 *
 * @author Brooklyn Fränzschky
 */
class ManualTransfer implements DeploymentPipelineInterface, DeploymentHttpHandlerInterface
{
    use ImportUxonObjectTrait;

    private string $hostPath = '';
    private string $hostPhp = 'php';
    private string $hostOs = '';
    private array $configuration = [
        'config' => [],
        'keep_releases' => 4,
        'shared_dirs' => ['backup', 'data', 'logs', 'translations'],
        'copy_dirs' => ['config'],
        'local_vendors' => [],
        'local_packages' => [],
        'php_memory_limit' => '2G'
    ];

    /**
     * Initializes the default installer options.
     *
     * The workbench argument satisfies Core's selectable-component constructor
     * contract; host-specific values are injected by the action after creation.
     *
     * @param WorkbenchInterface $workbench Core workbench passed by the component factory.
     */
    public function __construct(WorkbenchInterface $workbench)
    {
    }

    /**
     * Reports no publication for a manual-transfer pipeline and rejects OTA callbacks.
     *
     * @param ServerRequestInterface $request Incoming request.
     * @param DeploymentHttpContext $context Action-owned publication and response context.
     * @return ResponseInterface Not-modified response when no update exists, otherwise a conflict.
     */
    public function handleDeploymentHttpRequest(ServerRequestInterface $request, DeploymentHttpContext $context): ResponseInterface
    {
        if ($request->getMethod() === 'GET' && $context->read() === null) {
            return new Response(304, $context->headers, 'No published update is available.');
        }
        return new Response(409, $context->headers, $request->getMethod() === 'GET'
            ? 'This deployment is manual transfer, not an OTA publication.'
            : 'Manual transfer does not accept OTA callbacks.');
    }

    /**
     * Host input only; this is intentionally not a UXON property.
     *
     * @param string $path Host installation root supplied by the owning action.
     * @return $this
     * @throws \InvalidArgumentException If a required root is not absolute or contains traversal.
     */
    public function setHostPathToInstallationRoot(string $path): self
    {
        if (! $this->allowsDeferredRoot() && (! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)
            || preg_match('/[\x00-\x1f]/', $path)
            || preg_match('~(?:^|[\\\\/])\.\.?(?:[\\\\/]|$)~', $path))) {
            throw new \InvalidArgumentException('Host installation root must be an absolute non-traversing path.');
        }
        $this->hostPath = $path;
        return $this;
    }

    /**
     * Host executable, not a command line.
     *
     * @param string $executable Host PHP executable path, not a command line.
     * @return $this
     * @throws \InvalidArgumentException If the value does not name a PHP executable.
     */
    public function setHostPhpExecutable(string $executable): self
    {
        if ($executable === '' || preg_match('/[\x00-\x1f"\'`|;&<>]/', $executable)
            || ! preg_match('~(?:^|[\\\\/])php(?:[0-9.]*)?(?:\.exe)?$~iD', $executable)) {
            throw new \InvalidArgumentException('Host PHP must name a PHP executable, not a shell command.');
        }
        $this->hostPhp = $executable;
        return $this;
    }

    /**
     * Normalizes supported model values to the installer operating-system names.
     *
     * @param string $operatingSystem Host operating-system value supplied by the owning action.
     * @return $this
     * @throws \InvalidArgumentException If the operating system is unsupported.
     */
    public function setHostOperatingSystem(string $operatingSystem): self
    {
        $operatingSystem = ['windows' => 'Windows', 'windowsSsh' => 'Windows', 'linux' => 'Linux', 'unix' => 'Unix'][$operatingSystem] ?? $operatingSystem;
        if (! in_array($operatingSystem, ['Windows', 'Linux', 'Unix'], true)) {
            throw new \InvalidArgumentException('Unsupported host operating system.');
        }
        $this->hostOs = $operatingSystem;
        return $this;
    }

    /**
     * Application overrides preserved as data, never printed or executed.
     *
     * @uxon-property config
     * @uxon-type object
     * @uxon-default {}
     * @param UxonObject $value Installer application configuration to preserve in the package.
     * @return $this
     * @throws \RuntimeException If the configuration contains unsupported settings.
     */
    public function setConfig(UxonObject $value): self
    {
        NativeHostInstaller::validateSettings(['config' => $value->toArray()]);
        $this->configuration['config'] = $value->toArray();
        return $this;
    }

    /**
     * Keeps active and immediate previous releases; -1 disables pruning.
     *
     * @uxon-property keep_releases
     * @uxon-type integer
     * @uxon-default 4
     * @param int $value Number of releases to retain, or -1 to disable pruning.
     * @return $this
     * @throws \RuntimeException If the release-retention value is unsupported.
     */
    public function setKeepReleases(int $value): self
    {
        NativeHostInstaller::validateSettings(['keep_releases' => $value]);
        $this->configuration['keep_releases'] = $value;
        return $this;
    }

    /**
     * Safe top-level shared directories, staged privately until application installation succeeds.
     * Installer mutations to preserved shared content fail before activation.
     *
     * @uxon-property shared_dirs
     * @uxon-type string[]
     * @uxon-default ["backup", "data", "logs", "translations"]
     * @param UxonObject $value List of safe top-level directories to share between releases.
     * @return $this
     * @throws \InvalidArgumentException If a directory entry is malformed or unsafe.
     * @throws \RuntimeException If the installer rejects the directory settings.
     */
    public function setSharedDirs(UxonObject $value): self
    {
        NativeHostInstaller::validateSettings(['shared_dirs' => $value->toArray()]);
        $this->configuration['shared_dirs'] = self::paths($value);
        return $this;
    }

    /**
     * Complete snapshots of safe top-level directories; nested paths are unsupported.
     * Configuration is always preserved even when this list is empty.
     *
     * @uxon-property copy_dirs
     * @uxon-type string[]
     * @uxon-default ["config"]
     * @param UxonObject $value List of safe top-level directories to snapshot from the current installation.
     * @return $this
     * @throws \InvalidArgumentException If a directory entry is malformed or unsafe.
     * @throws \RuntimeException If the installer rejects the directory settings.
     */
    public function setCopyDirs(UxonObject $value): self
    {
        NativeHostInstaller::validateSettings(['copy_dirs' => $value->toArray(), 'shared_dirs' => []]);
        $this->configuration['copy_dirs'] = self::paths($value);
        return $this;
    }

    /**
     * Preserve absent local apps under these vendor names without replacing build packages.
     * Protected runtime vendors exface, axenox and composer are not accepted.
     *
     * @uxon-property local_vendors
     * @uxon-type string[]
     * @uxon-default []
     * @param UxonObject $value Vendor names whose absent local applications should be preserved.
     * @return $this
     * @throws \InvalidArgumentException If names are invalid or protected runtime vendors are selected.
     * @throws \RuntimeException If the installer rejects the vendor settings.
     */
    public function setLocalVendors(UxonObject $value): self
    {
        NativeHostInstaller::validateSettings(['local_vendors' => $value->toArray()]);
        $this->configuration['local_vendors'] = self::names($value, '~^[a-z0-9][a-z0-9_.-]*$~D');
        return $this;
    }

    /**
     * Preserves named packages even when present in the new build.
     * Requires identical Composer metadata and executable content; asset changes only.
     *
     * @uxon-property local_packages
     * @uxon-type string[]
     * @uxon-default []
     * @param UxonObject $value Package names to preserve from the current installation.
     * @return $this
     * @throws \InvalidArgumentException If package names or preservation settings are invalid.
     * @throws \RuntimeException If the installer rejects the package settings.
     */
    public function setLocalPackages(UxonObject $value): self
    {
        NativeHostInstaller::validateSettings(['local_packages' => $value->toArray()]);
        $this->configuration['local_packages'] = self::names($value, '~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D');
        return $this;
    }

    /**
     * Sets the PHP memory limit for installer execution.
     *
     * @uxon-property php_memory_limit
     * @uxon-type string
     * @uxon-default 2G
     * @param string $value PHP memory-limit value such as `2G` or `-1`.
     * @return $this
     * @throws \InvalidArgumentException If the value is not a supported PHP memory limit.
     */
    public function setPhpMemoryLimit(string $value): self
    {
        if (! preg_match('/^(?:-1|[1-9][0-9]*[KMG]?)$/iD', $value)) {
            throw new \InvalidArgumentException('Invalid installer PHP memory limit.');
        }
        $this->configuration['php_memory_limit'] = $value;
        return $this;
    }

    /**
     * Validates and de-duplicates a UXON list of names.
     *
     * @param UxonObject $value UXON list to validate.
     * @param string $pattern Regular expression each name must match.
     * @return string[] Validated names in their original order.
     * @throws \InvalidArgumentException If the UXON value is not a list of matching strings.
     */
    private static function names(UxonObject $value, string $pattern): array
    {
        $names = $value->toArray();
        if (! array_is_list($names)) {
            throw new \InvalidArgumentException('Expected a list of names.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || ! preg_match($pattern, $name)) {
                throw new \InvalidArgumentException('Invalid deployment configuration name.');
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Validates installer directory names and rejects traversal or reserved paths.
     *
     * @param UxonObject $value UXON list of relative top-level paths.
     * @return string[] Validated directory paths.
     * @throws \InvalidArgumentException If any path is malformed, unsafe or reserved.
     */
    private static function paths(UxonObject $value): array
    {
        $paths = self::names($value, '~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~D');
        foreach ($paths as $path) {
            if (preg_match('~(?:^|/)\.\.?(?:/|$)|^(?:vendor|releases|shared|current|exface)(?:/|$)~i', $path)) {
                throw new \InvalidArgumentException('Unsafe shared/copied directory.');
            }
        }
        return $paths;
    }

    /**
     * Returns only prototype-owned installer and preservation options.
     *
     * Host root, PHP executable and operating system values injected by the
     * action are intentionally excluded.
     *
     * @return UxonObject Exportable pipeline configuration.
     */
    public function exportUxonObject(): UxonObject
    {
        return new UxonObject($this->configuration);
    }

    /**
     * Returns the Core UXON schema used to import pipeline settings.
     *
     * @return string|null Schema class name.
     */
    public static function getUxonSchemaClass(): ?string
    {
        return UxonSchema::class;
    }

    /**
     * Indicates whether a derived pipeline may defer the host root binding.
     *
     * @return bool True only for pipelines that require an explicit operator root later.
     */
    protected function allowsDeferredRoot(): bool
    {
        return false;
    }

    /**
     * Validates all inputs before creating a package and returns preparation only.
     *
     * The task must include the release identity and project paths. No target-host
     * command is run by this method.
     *
     * @param PipelineTaskInterface $task Release inputs supplied by the owning action.
     * @return \Generator<int, string, mixed, PreparedDeploymentResult> Progress messages and the private prepared package.
     * @throws \InvalidArgumentException If task, host inputs, or installer settings are invalid.
     * @throws \RuntimeException If installer settings are rejected or the private package cannot be prepared.
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (! $task instanceof ReleaseDeploymentTask
            || ($this->hostPath === '' && ! $this->allowsDeferredRoot()) || $this->hostOs === '') {
            throw new \InvalidArgumentException('ManualTransfer requires a release task and action-supplied host inputs.');
        }
        NativeHostInstaller::validateSettings($this->configuration);
        $paths = array_merge($this->configuration['shared_dirs'], $this->configuration['copy_dirs']);
        foreach ($paths as $index => $left) {
            foreach (array_slice($paths, $index + 1) as $right) {
                if (strtolower($left) === strtolower($right)
                    || str_starts_with(strtolower($left), strtolower($right) . '/')
                    || str_starts_with(strtolower($right), strtolower($left) . '/')) {
                    throw new \InvalidArgumentException('Shared/copied directories may not overlap.');
                }
            }
        }
        $validRoot = (bool) preg_match($this->hostOs === 'Windows' ? '~^[A-Za-z]:[\\\\/]~' : '~^/~', $this->hostPath)
            && ! preg_match('~[\x00-\x1f]|(?:^|[\\\\/])\.\.?(?:[\\\\/]|$)~', $this->hostPath)
            && ! preg_match('~^(?:[A-Za-z]:)?[\\\\/]+$~D', $this->hostPath);
        if (! $validRoot && ! $this->allowsDeferredRoot()) {
            throw new \InvalidArgumentException('Host installation root does not match the selected operating system.');
        }
        yield "Preparing native manual-transfer package; no host installation will be performed.\n";
        $host = [
            'php_executable' => $this->hostPhp,
            'operating_system' => $this->hostOs
        ];
        if ($validRoot) {
            $host['installation_root'] = $this->hostPath;
        } else {
            $host['installation_root_binding'] = 'deferred_explicit';
            yield "Target root binding deferred: installation requires an explicit validated --root; automatic push is forbidden.\n";
        }
        return NativeDeploymentPackage::prepare($task, $host, $this->configuration);
    }
}
