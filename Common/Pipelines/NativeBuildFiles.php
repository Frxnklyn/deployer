<?php
namespace axenox\Deployer\Common\Pipelines;

use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use exface\Core\Exceptions\CliRuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Supplies the native build and installation paths with shell-free process execution,
 * archive-entry checks, credential scanning and link-safe filesystem cleanup. It does not
 * create archives itself or expose subprocess diagnostics that may contain credentials.
 *
 * @author Brooklyn Fränzschky
 */
final class NativeBuildFiles
{
    /**
     * Runs argv without a shell and deliberately discards potentially secret diagnostics.
     *
     * Core's streaming runner is shell-based and its exec fallback ignores cwd/timeout.
     * The array runner enforces both and throws on nonzero exits.
     *
     * @param string $executable Executable path or command name.
     * @param array $arguments Argument vector passed without shell concatenation.
     * @param string $cwd Working directory required for the command.
     * @param int $timeout Maximum command runtime in seconds.
     * @param array $environment Additional child-process environment values.
     * @return void
     */
    public static function command(string $executable, array $arguments, string $cwd, int $timeout, array $environment = []): void
    {
        try {
            CliCommandRunner::runCliCommandIntoArray($executable, $arguments, $cwd, [0], $timeout, $environment);
        } catch (CliRuntimeException $error) {
            $output = implode("\n", $error->getCliOutput());
            $hint = '';
            if (preg_match('/curl error 60|certificate verify failed|unable to get local issuer certificate/i', $output)) {
                $hint = ' TLS certificate validation failed. Configure a trusted CA bundle for the selected CLI PHP; do not disable certificate verification.';
            } elseif (preg_match('/could not authenticate|invalid credentials|authentication (?:failed|required)|HTTP[\/ ][^\r\n]*\b(?:401|403)\b/i', $output)) {
                $hint = ' Repository authentication failed. Check composer_auth on the build variant and server COMPOSER.AUTH.';
            } elseif (str_contains($output, 'Your requirements could not be resolved to an installable set of packages')) {
                $hint = ' Composer dependency resolution failed. Check package constraints and the selected CLI PHP/extensions.';
            }
            throw new \RuntimeException('Native build command failed with exit code ' . $error->getExitCode() . '.' . $hint);
        } catch (ProcessTimedOutException $error) {
            throw new \RuntimeException('Native build command timed out after ' . $timeout . ' seconds.');
        } catch (\Throwable $error) {
            // Do not retain the original exception: it includes stdout/stderr and the command.
            throw new \RuntimeException('Native build command failed: unavailable executable or process failure.');
        }
    }

    /**
     * Rejects traversal, options, links, special files and credential-bearing archive entries.
     *
     * @param string $workspace Root of the workspace to inspect.
     * @param string $entry Safe workspace-relative path to validate.
     * @param string[] $secrets Credential values that must not occur in archived files.
     * @return void
     */
    public static function validateEntry(string $workspace, string $entry, array $secrets = []): void
    {
        if (! preg_match('~^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$~D', $entry)
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $entry)) {
            throw new \InvalidArgumentException('Archive entries must be safe workspace-relative paths.');
        }
        $parts = explode('/', $entry);
        $path = $workspace;
        foreach ($parts as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (self::isLink($path)) {
                throw new \RuntimeException('Links are not supported in build archives.');
            }
        }
        if (! file_exists($path)) {
            throw new \RuntimeException('A required archive entry is missing.');
        }
        self::validateTree($path, $secrets);
    }

    /**
     * Recursively rejects links, special files and credential-bearing paths/content.
     *
     * @param string $path Entry or directory tree to inspect.
     * @param string[] $secrets Credential values to search for in regular files.
     * @return void
     */
    private static function validateTree(string $path, array $secrets): void
    {
        if (self::isLink($path) || (! is_dir($path) && ! is_file($path))) {
            throw new \RuntimeException('Links and special files are not supported in build archives.');
        }
        $name = strtolower(basename($path));
        if (in_array($name, ['auth.json', '.composer', '.git', '.env', '.ssh', '.netrc', '.npmrc', '.git-credentials'], true)
            || str_starts_with($name, '.env.') || preg_match('/\.(pem|key|p12|pfx)$/i', $name)) {
            throw new \RuntimeException('Credential files are not permitted in build archives.');
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $child) {
                self::validateTree($child->getPathname(), $secrets);
            }
        } elseif ($secrets !== []) {
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot inspect an archive entry for credentials.');
            }
            $tail = '';
            $overlap = max(array_map('strlen', $secrets));
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Cannot inspect an archive entry for credentials.');
                    }
                    $contents = $tail . $chunk;
                    foreach ($secrets as $secret) {
                        if (strpos($contents, $secret) !== false) {
                            throw new \RuntimeException('An archive entry contains configured authentication material.');
                        }
                    }
                    $tail = substr($contents, -$overlap);
                }
            } finally {
                fclose($stream);
            }
        }
    }

    /**
     * Extracts password/token values without treating non-secret usernames as credentials.
     *
     * @param array $authentication Nested authentication data to inspect.
     * @return string[] Unique non-empty secret values, excluding username fields.
     */
    public static function authenticationSecrets(array $authentication): array
    {
        $secrets = [];
        foreach ($authentication as $key => $value) {
            if (is_array($value)) {
                $secrets = array_merge($secrets, self::authenticationSecrets($value));
            } elseif (strtolower((string) $key) !== 'username' && is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }
        return array_values(array_unique($secrets));
    }

    /**
     * Deletes only the private workspace, never following links.
     *
     * @param string $path Owned file, link or directory tree to remove.
     * @return void
     */
    public static function remove(string $path): void
    {
        if (self::isLink($path) && PHP_OS_FAMILY === 'Windows' && ! is_link($path)) {
            self::deletePath($path, true);
        } elseif (self::isLink($path) || is_file($path)) {
            self::deletePath($path, false);
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $child) {
                self::remove($child->getPathname());
            }
            self::deletePath($path, true);
        }
    }

    /**
     * Windows may retain a completed process/scanner handle briefly after unlink.
     * Retry only the same owned entry; persistent errors still fail cleanup.
     *
     * @param string $path Owned path to unlink or remove.
     * @param bool $directory Whether the path is a directory to remove with rmdir.
     * @return void
     */
    private static function deletePath(string $path, bool $directory): void
    {
        $attempts = PHP_OS_FAMILY === 'Windows' ? 51 : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($directory ? @rmdir($path) : @unlink($path)) {
                return;
            }
            if ($attempt + 1 < $attempts) {
                usleep(100000);
            }
        }
        throw new \RuntimeException('Cannot remove an owned build workspace ' . ($directory ? 'directory.' : 'file.'));
    }

    /**
     * Detects symbolic links and Windows reparse points without treating ordinary Windows
     * readlink results as links; only the final path component is compared.
     *
     * @param string $path Filesystem path to inspect.
     * @return bool Whether the path is a link or reparse point.
     */
    public static function isLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        $target = @readlink($path);
        $parent = realpath(dirname($path));
        if ($target === false || $parent === false) {
            return false;
        }
        $expected = rtrim($parent, '\\/') . DIRECTORY_SEPARATOR . basename($path);
        return strcasecmp(str_replace('/', '\\', rtrim($target, '\\/')), str_replace('/', '\\', $expected)) !== 0;
    }
}
