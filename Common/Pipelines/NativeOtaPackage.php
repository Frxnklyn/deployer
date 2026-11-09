<?php
namespace axenox\Deployer\Common\Pipelines;

/**
 * Publishes immutable, deployment-attempt-owned executable packages for authenticated
 * native OTA. The generated bootstrap bundles only the standalone installer path and never
 * loads the old application's autoloader; the compatibility build-directory copy is
 * separately mutable and is not used as the authenticated download source.
 */
final class NativeOtaPackage
{
    /**
     * Recognizes only this bundled protocol's class/path selectors.
     *
     * @param string $prototype Saved pipeline selector or recipe path.
     * @return bool Whether it identifies a bundled native pull/polling pipeline.
     */
    public static function isPrototype(string $prototype): bool
    {
        return in_array(strtolower(ltrim(str_replace('\\', '/', $prototype), '/')), [
            'axenox/deployer/pipelines/deploy/updaterpull',
            'axenox/deployer/pipelines/deploy/updaterpull.php',
            'axenox/deployer/pipelines/deploy/updaterpolling',
            'axenox/deployer/pipelines/deploy/updaterpolling.php'
        ], true);
    }

    /**
     * Detects bundled preparation-only prototypes that must never use legacy OTA lookup.
     *
     * @param string $prototype Saved pipeline selector or recipe path.
     * @return bool Whether it identifies a bundled preparation-only manual-transfer pipeline.
     */
    public static function isManualPrototype(string $prototype): bool
    {
        return in_array(strtolower(ltrim(str_replace('\\', '/', $prototype), '/')), [
            'axenox/deployer/pipelines/deploy/manualtransfer',
            'axenox/deployer/pipelines/deploy/manualtransfer.php',
            'axenox/deployer/pipelines/deploy/azuremanualtransfer',
            'axenox/deployer/pipelines/deploy/azuremanualtransfer.php'
        ], true);
    }

    /**
     * Resolves only an attempt-owned filename, never a caller-supplied path.
     *
     * @param string $project Canonical project directory for this deployment.
     * @param string $deploymentId Deployment-attempt identity used to derive the filename.
     * @return string Private artifact path derived from the attempt identity.
     * @throws \InvalidArgumentException If the project or attempt identity is invalid.
     * @throws \RuntimeException If the artifact path traverses a link.
     */
    public static function path(string $project, string $deploymentId): string
    {
        if ($deploymentId === '' || strlen($deploymentId) > 200
            || NativeBuildFiles::isLink($project) || ! is_dir($project)) {
            throw new \InvalidArgumentException('Invalid OTA deployment identity or project.');
        }
        $directory = $project . DIRECTORY_SEPARATOR . '.native-deployments';
        $path = $directory . DIRECTORY_SEPARATOR . 'ota-' . hash('sha256', $deploymentId) . '.phx';
        if (NativeBuildFiles::isLink($directory) || NativeBuildFiles::isLink($path)) {
            throw new \RuntimeException('OTA artifact paths may not be links.');
        }
        return $path;
    }

    /**
     * Wraps an inspected native package without shell concatenation or recipe execution.
     * Existing attempts cannot be overwritten. Configuration remains in private storage.
     *
     * @param ReleaseDeploymentTask $task Action-resolved attempt identity and project path.
     * @param PreparedDeploymentResult $package Inspected package to wrap; it is discarded after publication.
     * @return PreparedDeploymentResult Immutable attempt-owned executable OTA artifact.
     * @throws \RuntimeException If source verification, wrapping, writing or publication fails.
     */
    public static function publish(ReleaseDeploymentTask $task, PreparedDeploymentResult $package): PreparedDeploymentResult
    {
        $path = self::path($task->projectPath, $task->deploymentId);
        $input = null;
        $output = null;
        $owned = false;
        try {
            if (NativeBuildFiles::isLink($package->artifactPath)
                || ! hash_equals($package->sha256, hash_file('sha256', $package->artifactPath))) {
                throw new \RuntimeException('Prepared OTA payload changed before publication.');
            }
            $bootstrap = "<?php\n"
                . "namespace axenox\\Deployer\\Common\\Pipelines;\n";
            foreach (['NativeBuildFiles.php', 'NativeDeploymentPackage.php', 'NativeHostInstaller.php'] as $file) {
                $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $file);
                if ($source === false) {
                    throw new \RuntimeException('Cannot read the standalone OTA bootstrap.');
                }
                $source = preg_replace('/^<\?php\s*/', '', $source, 1, $opening);
                $source = str_replace('namespace axenox\\Deployer\\Common\\Pipelines;', '', $source, $namespace);
                if ($opening !== 1 || $namespace !== 1) {
                    throw new \RuntimeException('Unexpected standalone OTA bootstrap source.');
                }
                $bootstrap .= $source . "\n";
            }
            $entry = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'Standalone' . DIRECTORY_SEPARATOR . 'ota.php');
            if ($entry === false) {
                throw new \RuntimeException('Cannot read the standalone OTA entry point.');
            }
            $entry = preg_replace('/^<\?php\s*/', '', $entry, 1);
            $entry = str_replace('namespace axenox\\Deployer\\Common\\Pipelines;', '', $entry);
            $bootstrap .= rtrim(str_replace("'[#payload_sha256#]'", var_export($package->sha256, true), $entry), "\r\n");
            $input = fopen($package->artifactPath, 'rb');
            $output = @fopen($path, 'x+b');
            if ($output === false) {
                throw new \RuntimeException('OTA attempt already has an artifact or cannot be published.');
            }
            $owned = true;
            if ($input === false || ! chmod($path, 0600)
                || fwrite($output, $bootstrap) !== strlen($bootstrap)
                || stream_copy_to_stream($input, $output) !== filesize($package->artifactPath)
                || ! fflush($output)) {
                throw new \RuntimeException('Cannot write the complete private OTA artifact.');
            }
            fclose($input);
            $input = null;
            fclose($output);
            $output = null;
            $result = new PreparedDeploymentResult($path, hash_file('sha256', $path),
                "PUBLISHED ONLY - NOT INSTALLED\n"
                . "Native OTA package prepared without deployer/deployer.\n"
                . "Use the authenticated Deployer OTA endpoint with a native-OTA-capable SelfUpdate client.\n"
                . "Older clients are rejected before download; this package requires the managed native host layout.\n"
                . "Existing unmanaged/legacy installations are not adopted automatically.\n"
                . "If root binding is deferred, execute php <package.phx> --root <existing absolute directory>.\n"
                . "Manual CLI execution works without PackageManager integration changes. Valid bound roots cannot be overridden.\n"
                . "Automatic SelfUpdate/native-root and native push require the optional PackageManager integration branch; the normal branch is not compatible with this OTA protocol.\n"
                . "Deferred packages cannot be installed by automatic push. Private staging beside the .phx must be writable.\n"
                . "Take verified database/files backups and stop traffic/jobs before installation.\n"
                . "Manual recovery: execute this same package with [--root <directory>] --recover after stopping installer workers.\n"
                . "Filesystem recovery does not roll back database changes.\n");
            $package->discard();
            if ($task->hostAlias !== '') {
                $result = self::exportBuildCopy($task, $result);
            }
            return $result;
        } catch (\Throwable $error) {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_resource($input)) {
                fclose($input);
            }
            if ($owned && is_file($path) && ! unlink($path)) {
                throw new \RuntimeException('Cannot remove the incomplete OTA artifact.');
            }
            throw $error;
        } finally {
            $package->discard();
        }
    }

    /**
     * Publishes the historical build/host filename while retaining immutable OTA attempt storage.
     *
     * The copy is staged privately and atomically replaced; web access protection is operator-owned.
     * Authenticated OTA continues to address the immutable attempt, never the mutable copy.
     *
     * @param ReleaseDeploymentTask $task Action-resolved build name, host alias and project path.
     * @param PreparedDeploymentResult $package Immutable OTA package to copy to the historical export name.
     * @return PreparedDeploymentResult Published build-directory copy and instructions.
     * @throws \InvalidArgumentException If the historical filename identity is invalid.
     * @throws \RuntimeException If the private copy cannot be published or cleaned up.
     */
    public static function exportBuildCopy(ReleaseDeploymentTask $task, PreparedDeploymentResult $package): PreparedDeploymentResult
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $task->hostAlias)
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]*$/D', $task->buildName)) {
            throw new \InvalidArgumentException('Invalid build export filename.');
        }
        $directory = $task->projectPath . DIRECTORY_SEPARATOR . 'builds';
        NativeDeploymentPackage::protect($directory, true);
        $path = $directory . DIRECTORY_SEPARATOR . $task->buildName . '_' . $task->hostAlias . '.phx';
        $staged = dirname($package->artifactPath) . DIRECTORY_SEPARATOR . '.export-' . bin2hex(random_bytes(16));
        try {
            if (NativeBuildFiles::isLink($path) || (file_exists($path) && ! is_file($path))
                || ! copy($package->artifactPath, $staged) || ! chmod($staged, 0600)
                || ! hash_equals($package->sha256, hash_file('sha256', $staged))
                || ! rename($staged, $path)) {
                throw new \RuntimeException('Cannot publish the native package in the build directory.');
            }
            return new PreparedDeploymentResult($path, $package->sha256,
                $package->instructions . "Build-directory package uses the historical build/host filename.\n"
                . "Authenticated OTA retains a separate immutable copy for this deployment attempt.\n",
                [$package->artifactPath]);
        } finally {
            if (is_file($staged) && ! unlink($staged)) {
                throw new \RuntimeException('Cannot remove the staged build-directory package.');
            }
        }
    }
}
