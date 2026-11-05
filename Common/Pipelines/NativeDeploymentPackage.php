<?php
namespace axenox\Deployer\Common\Pipelines;

/**
 * Shared native preparation boundary with a bundled standalone host installer.
 *
 * @author Brooklyn Fränzschky
 */
final class NativeDeploymentPackage
{
    /**
     * Resolves an existing project below the action's installation base without links.
     *
     * @param string $base
     * @param string $relative
     * @return string
     */
    public static function projectPath(string $base, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if (! preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~D', $relative)
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $relative)) {
            throw new \InvalidArgumentException('Project path must remain below the installation base.');
        }
        $current = $base;
        foreach (explode('/', $relative) as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (NativeBuildFiles::isLink($current)) {
                throw new \RuntimeException('Project paths may not contain links.');
            }
        }
        $path = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($path === false || ! is_dir($path)) {
            throw new \RuntimeException('Native deployment project directory is missing.');
        }
        return $path;
    }

    /**
     * Creates a unique protected package from an inspected immutable snapshot.
     *
     * Config may contain secrets: neither its contents nor command diagnostics are emitted.
     *
     * @param ReleaseDeploymentTask $task
     * @param array $host
     * @param array $configuration
     * @return PreparedDeploymentResult
     */
    public static function prepare(ReleaseDeploymentTask $task, array $host, array $configuration): PreparedDeploymentResult
    {
        NativeHostInstaller::validateSettings($configuration);
        if ($task->deploymentId === '' || $task->hostId === '' || $task->buildId === ''
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]*$/D', $task->buildName)
            || NativeBuildFiles::isLink($task->projectPath) || ! is_dir($task->projectPath)) {
            throw new \InvalidArgumentException('Invalid native deployment identity or project path.');
        }
        $buildRelative = 'builds/' . $task->buildName . '.tar.gz';
        $source = $task->projectPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $buildRelative);
        if (NativeBuildFiles::isLink($task->projectPath . DIRECTORY_SEPARATOR . 'builds') || NativeBuildFiles::isLink($source) || ! is_file($source)) {
            throw new \RuntimeException('The selected release archive is missing.');
        }
        $directory = $task->projectPath . DIRECTORY_SEPARATOR . '.native-deployments';
        if (NativeBuildFiles::isLink($directory) || (file_exists($directory) && ! is_dir($directory))) {
            throw new \RuntimeException('Unsafe native deployment package directory.');
        }
        self::protect($directory);
        $identity = bin2hex(random_bytes(16));
        $workspace = $directory . DIRECTORY_SEPARATOR . '.prepare-' . $identity;
        if (! mkdir($workspace, 0700)) {
            throw new \RuntimeException('Cannot create deployment preparation workspace.');
        }
        $artifact = $directory . DIRECTORY_SEPARATOR . $identity . '.tar.gz';
        $published = false;
        try {
            $payload = $workspace . DIRECTORY_SEPARATOR . 'build.tar.gz';
            if (! copy($source, $payload)) {
                throw new \RuntimeException('Cannot snapshot the selected build.');
            }
            self::validateArchive($payload);
            $manifest = [
                'format' => 'axenox.deployer.manual-install.v1',
                'outcome' => 'prepared_not_installed',
                'installer_included' => true,
                'artifact_id' => $identity,
                'deployment_id' => $task->deploymentId,
                'host_id' => $task->hostId,
                'build_id' => $task->buildId,
                'build_name' => $task->buildName,
                'payload' => ['file' => 'build.tar.gz', 'sha256' => hash_file('sha256', $payload)],
                'host' => $host,
                'pipeline' => $configuration
            ];
            self::write($workspace . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $instructions = "PREPARED ONLY - NOT INSTALLED\n"
                . "A standalone CLI installer is included; this is not an executable .phx self-extractor.\n"
                . "Transfer using an authenticated channel to a private staging directory on the selected host.\n"
                . "The package and manifest may contain application secrets; restrict filesystem access and never publish a URL.\n"
                . "Verify the outer SHA-256 supplied by the deployment log, then the payload SHA-256 in manifest.json.\n"
                . ($host['operating_system'] === 'Windows'
                    ? "Windows verification: Get-FileHash -Algorithm SHA256 <package-path>\n"
                    : "Unix verification: sha256sum <package-path>\n")
                . "After verifying the outer hash, extract this outer package into private staging, not over a live installation.\n"
                . "Stop all application traffic/jobs and take a verified database/files backup before installing.\n"
                . "Run the host PHP CLI: php installer.php --root <absolute-host-root>\n"
                . "The explicit root must match the host root in manifest.json; UXON cannot change it.\n"
                . "Only pull/polling may omit an unusable root and mark installation_root_binding=deferred_explicit.\n"
                . "That marker requires an explicit existing absolute root at execution; automatic push is forbidden.\n"
                . "Requires PHP >=8.1 CLI, zlib, proc_open; Windows additionally needs PowerShell and junction support.\n"
                . "Match PHP/extensions to built dependencies; the currently reviewed Core requires PHP ^8.3.\n"
                . "Provision the target database/user and verify the full System.config.json connector before installing.\n"
                . "Private CLI roots need an explicit INSTALLER.SERVER_INSTALLER.CLASS for the intended web server.\n"
                . "For isolated tests/operator-managed scheduling set INSTALLER.SCHEDULERINSTALLER.DISABLED=true.\n"
                . "Bounded layout: root/current -> root/.native-installer/releases/<attempt>. Web routing is operator-owned.\n"
                . "Set shared_dirs=[] and keep_releases=-1. local_packages is unsupported and must be empty.\n"
                . "Existing unmanaged current directories/links are rejected. No automatic legacy layout adoption.\n"
                . "Installation runs only the extracted runtime. Activation follows successful application installers.\n"
                . "On interruption run: php installer.php --root <absolute-host-root> --recover\n"
                . "Recovery restores filesystem activation only, never databases or external installer side effects.\n"
                . "No host files were changed by package preparation. Status 60 remains awaiting operator work, not success.\n"
                . "Delete the staging copy after authorized use. Retained source packages require operator cleanup.\n";
            self::write($workspace . DIRECTORY_SEPARATOR . 'README.txt', $instructions);
            foreach (['NativeDeploymentPackage.php', 'NativeBuildFiles.php', 'NativeHostInstaller.php'] as $file) {
                if (! copy(__DIR__ . DIRECTORY_SEPARATOR . $file, $workspace . DIRECTORY_SEPARATOR . $file)) {
                    throw new \RuntimeException('Cannot bundle standalone installer dependencies.');
                }
            }
            foreach (['installer.php', 'runtime-install.php'] as $file) {
                if (! copy(__DIR__ . DIRECTORY_SEPARATOR . 'Standalone' . DIRECTORY_SEPARATOR . $file, $workspace . DIRECTORY_SEPARATOR . $file)) {
                    throw new \RuntimeException('Cannot bundle standalone installer entry points.');
                }
            }
            $partial = $workspace . DIRECTORY_SEPARATOR . 'package.tar.gz';
            NativeBuildFiles::command('tar', [
                '-czf', $partial, '--', 'manifest.json', 'README.txt', 'build.tar.gz',
                'NativeDeploymentPackage.php', 'NativeBuildFiles.php', 'NativeHostInstaller.php',
                'installer.php', 'runtime-install.php'
            ], $workspace, 120);
            if (! chmod($partial, 0600) || ! rename($partial, $artifact)) {
                throw new \RuntimeException('Cannot publish deployment package.');
            }
            $published = true;
            return new PreparedDeploymentResult($artifact, hash_file('sha256', $artifact), $instructions);
        } catch (\Throwable $error) {
            if ($published && is_file($artifact)) {
                unlink($artifact);
            }
            throw $error;
        } finally {
            try {
                NativeBuildFiles::remove($workspace);
            } catch (\Throwable $error) {
                if ($published && is_file($artifact)) {
                    unlink($artifact);
                }
                throw $error;
            }
        }
    }

    /**
     * Restricts local access and denies static Apache/IIS delivery, including on Windows.
     *
     * This is not a replacement for operator-controlled data-directory/server permissions.
     * Native logs deliberately do not route these artifacts through the file facade.
     *
     * @param string $directory
     * @param bool $buildExports Validate the build directory; optional web-server rules are currently disabled.
     * @return void
     */
    // Generated by GitHub Copilot - Nov-05-2026
    public static function protect(string $directory, bool $buildExports = false): void
    {
        // Generated by GitHub Copilot - Nov-05-2026
        if (NativeBuildFiles::isLink($directory) || (file_exists($directory) && ! is_dir($directory))) {
            throw new \RuntimeException('Unsafe native deployment package directory.');
        }
        $apache = $buildExports ? "<FilesMatch \"(?i)\\.phx$\">\nRequire all denied\n</FilesMatch>\n" : "Require all denied\n";
        $iis = $buildExports
            ? '<?xml version="1.0"?><configuration><system.webServer><security><requestFiltering><fileExtensions><remove fileExtension=".phx"/><add fileExtension=".phx" allowed="false"/></fileExtensions></requestFiltering></security></system.webServer></configuration>'
            : '<?xml version="1.0"?><configuration><system.webServer><security><authorization><clear/><add accessType="Deny" users="*"/></authorization></security></system.webServer></configuration>';
        // Generated by GitHub Copilot - Nov-05-2026
        if ($buildExports) {
            if (! is_dir($directory)) {
                throw new \RuntimeException('The build export directory is missing.');
            }
            // Optional Apache/IIS PHX protection, disabled at the operator's request.
            // To enable, uncomment the block below. Review existing server rules first.
            // Without it, the web server must independently deny access to secret-bearing packages.
            /*
            foreach (['.htaccess' => $apache, 'web.config' => $iis] as $file => $contents) {
                $path = $directory . DIRECTORY_SEPARATOR . $file;
                if (file_exists($path) && (NativeBuildFiles::isLink($path) || file_get_contents($path) !== $contents)) {
                    throw new \RuntimeException('Existing build-directory server rules require an operator-reviewed merge.');
                }
            }
            self::write($directory . DIRECTORY_SEPARATOR . '.htaccess', $apache);
            self::write($directory . DIRECTORY_SEPARATOR . 'web.config', $iis);
            */
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // Generated by GitHub Copilot - Nov-05-2026
            // Keep the owner and LocalSystem service accessible across CLI/web runs, never inherited users.
            $script = '$ErrorActionPreference="Stop";'
                . '$path=$env:EXFACE_NATIVE_PRIVATE_DIRECTORY;'
                . '$owner=[System.Security.Principal.WindowsIdentity]::GetCurrent().User;'
                . 'if([System.IO.Directory]::Exists($path)){'
                . '$owner=[System.IO.Directory]::GetAccessControl($path).GetOwner([System.Security.Principal.SecurityIdentifier]);}'
                . '$acl=New-Object System.Security.AccessControl.DirectorySecurity;'
                . '$acl.SetAccessRuleProtection($true,$false);'
                . 'foreach($sid in @($owner.Value,"S-1-5-18") | Select-Object -Unique){'
                . '$identity=New-Object System.Security.Principal.SecurityIdentifier($sid);'
                . '$rule=New-Object System.Security.AccessControl.FileSystemAccessRule('
                . '$identity,'
                . '"FullControl","ContainerInherit,ObjectInherit","None","Allow");'
                . '$acl.AddAccessRule($rule);}'
                . '[System.IO.Directory]::CreateDirectory($path,$acl) | Out-Null;'
                . '[System.IO.Directory]::SetAccessControl($path,$acl);';
            NativeBuildFiles::command('powershell.exe', [
                '-NoProfile', '-NonInteractive', '-EncodedCommand',
                base64_encode(implode("\0", str_split($script)) . "\0")
            ], dirname($directory), 30, ['EXFACE_NATIVE_PRIVATE_DIRECTORY' => $directory]);
        } elseif ((! is_dir($directory) && ! mkdir($directory, 0700)) || ! chmod($directory, 0700)) {
            throw new \RuntimeException('Cannot protect native deployment packages.');
        }
        self::write($directory . DIRECTORY_SEPARATOR . '.htaccess', $apache);
        self::write($directory . DIRECTORY_SEPARATOR . 'web.config', $iis);
    }

    /**
     * Writes only private regular files, never following an existing link.
     *
     * @param string $path
     * @param string $contents
     * @return void
     */
    private static function write(string $path, string $contents): void
    {
        if (NativeBuildFiles::isLink($path) || (file_exists($path) && ! is_file($path))
            || file_put_contents($path, $contents) !== strlen($contents) || ! chmod($path, 0600)) {
            throw new \RuntimeException('Cannot write private deployment metadata.');
        }
    }

    /**
     * Inspects raw tar headers without extracting or trusting sanitized archive listings.
     *
     * Accepts regular files/directories and bounded POSIX path metadata only. Links,
     * devices, sparse files, traversal, credentials, duplicate and case-colliding paths
     * fail closed. GNU long-name and global extension formats are deliberately unsupported.
     *
     * @param string $archive
     * @param string|null $destination Private, empty, link-free extraction directory.
     * @return void
     */
    public static function validateArchive(string $archive, ?string $destination = null): void
    {
        if ($destination !== null && (NativeBuildFiles::isLink($destination) || ! is_dir($destination)
            || iterator_count(new \FilesystemIterator($destination)) !== 0)) {
            throw new \RuntimeException('Extraction requires an empty private directory.');
        }
        $signature = fopen($archive, 'rb');
        if ($signature === false) {
            throw new \RuntimeException('Cannot open the build archive.');
        }
        try {
            if (fread($signature, 3) !== "\x1f\x8b\x08") {
                throw new \RuntimeException('The build archive must use gzip compression.');
            }
        } finally {
            fclose($signature);
        }
        $stream = gzopen($archive, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot inspect the build archive.');
        }
        $seen = [];
        $parents = [];
        $pendingPath = null;
        $expanded = 0;
        $headers = 0;
        try {
            while (true) {
                $header = self::read($stream, 512);
                if ($header === str_repeat("\0", 512)) {
                    if ($pendingPath !== null || self::read($stream, 512) !== str_repeat("\0", 512)) {
                        throw new \RuntimeException('Invalid build archive terminator.');
                    }
                    $padding = 0;
                    while (! gzeof($stream)) {
                        $tail = gzread($stream, 65536);
                        $padding += strlen($tail === false ? '' : $tail);
                        if ($tail === false || trim($tail, "\0") !== '' || $padding > 1048576) {
                            throw new \RuntimeException('Unexpected data after build archive.');
                        }
                    }
                    break;
                }
                $checksum = substr($header, 148, 8);
                if (! preg_match('/^[0-7]+[\x00 ]*$/D', $checksum)
                    || octdec(trim($checksum, "\0 ")) !== array_sum(unpack('C*', substr_replace($header, '        ', 148, 8)))) {
                    throw new \RuntimeException('Invalid build archive checksum.');
                }
                $sizeField = trim(substr($header, 124, 12), "\0 ");
                if (! preg_match('/^[0-7]+$/D', $sizeField)) {
                    throw new \RuntimeException('Unsupported build archive size.');
                }
                $size = octdec($sizeField);
                $expanded += $size;
                if ($expanded > 21474836480 || count($seen) >= 200000 || ++$headers > 400000) {
                    throw new \RuntimeException('Build archive exceeds preparation limits.');
                }
                $type = $header[156];
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
                if ($type === 'x') {
                    if ($pendingPath !== null || $size > 65536) {
                        throw new \RuntimeException('Unsupported build archive metadata.');
                    }
                    $metadata = self::read($stream, (int) (ceil($size / 512) * 512));
                    $metadata = substr($metadata, 0, $size);
                    while ($metadata !== '') {
                        if (! preg_match('/^([1-9][0-9]*) ([^=]+)=/', $metadata, $match)
                            || (int) $match[1] > strlen($metadata) || (int) $match[1] <= strlen($match[0])) {
                            throw new \RuntimeException('Invalid build archive metadata.');
                        }
                        $record = substr($metadata, 0, (int) $match[1]);
                        if (! str_ends_with($record, "\n") || ! in_array($match[2], ['path', 'mtime', 'atime', 'ctime'], true)) {
                            throw new \RuntimeException('Unsupported build archive metadata property.');
                        }
                        if ($match[2] === 'path') {
                            $pendingPath = substr($record, strlen($match[0]), -1);
                        }
                        $metadata = substr($metadata, (int) $match[1]);
                    }
                    continue;
                }
                $name = $pendingPath ?? $name;
                $pendingPath = null;
                $name = rtrim($name, '/');
                if (! in_array($type, ["\0", '0', '5'], true)
                    || ($type === '5' && $size !== 0)
                    || ! preg_match('~^[A-Za-z0-9_.@+() -]+(?:/[A-Za-z0-9_.@+() -]+)*$~D', $name)
                    || preg_match('~(?:^|/) | (?:/|$)~', $name)
                    || preg_match('~(?:^|/)(?:\.\.?|auth\.json|\.env(?:\.[^/]+)?|\.git|\.ssh|\.composer|\.netrc|\.npmrc|\.git-credentials)(?:/|$)|\.(?:pem|key|p12|pfx)$~i', $name)
                    || preg_match('~(?:^|/)(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|/|$)|\.(?:/|$)~i', $name)
                    || ($type !== '5' && isset($parents[strtolower($name)]))
                    || isset($seen[strtolower($name)])) {
                    throw new \RuntimeException('Unsafe or unsupported build archive entry.');
                }
                $parts = explode('/', strtolower($name));
                foreach ($parts as $index => $part) {
                    $ancestor = implode('/', array_slice($parts, 0, $index));
                    if ($ancestor !== '' && isset($seen[$ancestor]) && $seen[$ancestor] !== '5') {
                        throw new \RuntimeException('Conflicting build archive entries.');
                    }
                    $parents[$ancestor] = true;
                }
                $seen[strtolower($name)] = $type;
                $output = null;
                if ($destination !== null) {
                    $path = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
                    $directory = $type === '5' ? $path : dirname($path);
                    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                        throw new \RuntimeException('Cannot create extracted directory.');
                    }
                    if ($type !== '5') {
                        $output = fopen($path, 'xb');
                        if ($output === false || ! chmod($path, 0600)) {
                            throw new \RuntimeException('Cannot create extracted file.');
                        }
                    }
                }
                try {
                    $remaining = $size;
                    while ($remaining > 0) {
                        $chunk = self::read($stream, min(65536, $remaining));
                        if ($output !== null && fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException('Cannot write extracted file.');
                        }
                        $remaining -= strlen($chunk);
                    }
                    self::read($stream, (512 - ($size % 512)) % 512);
                } finally {
                    if (is_resource($output)) {
                        fclose($output);
                    }
                }
            }
            if ($seen === []) {
                throw new \RuntimeException('The build archive is empty.');
            }
        } finally {
            gzclose($stream);
        }
    }

    /**
     * Reads exact tar blocks; truncated payloads fail before publication.
     *
     * @param resource $stream
     * @param int $length
     * @return string
     */
    private static function read($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = gzread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Truncated build archive.');
            }
            $data .= $chunk;
        }
        return $data;
    }
}
