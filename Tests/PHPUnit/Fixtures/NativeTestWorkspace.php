<?php
namespace axenox\Deployer\Tests\PHPUnit\Fixtures;

use axenox\Deployer\Common\Pipelines\NativeBuildFiles;

/**
 * Owned scratch trees outside the Deployer worktree, never OS temporary folders.
 *
 * @author Brooklyn Fränzschky
 */
final class NativeTestWorkspace
{
    /**
     * Checks real-install opt-in without writing files or connecting to a database.
     * Real code/configuration must also remain outside the live installation.
     *
     * @return string
     */
    public static function realInstallBase(): string
    {
        $base = getenv('EXFACE_DEPLOYER_REAL_INSTALL_WORKSPACE');
        $base = $base === false || $base === '' ? false : realpath($base);
        $installation = realpath(dirname(__DIR__, 6));
        $port = filter_var(getenv('EXFACE_DEPLOYER_TEST_MYSQL_PORT'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (getenv('EXFACE_DEPLOYER_REAL_INSTALL_TEST') !== '1' || $base === false || ! is_dir($base)
            || str_starts_with(strtolower($base . DIRECTORY_SEPARATOR), strtolower($installation . DIRECTORY_SEPARATOR))
            || getenv('EXFACE_DEPLOYER_TEST_MYSQL_HOST') !== '127.0.0.1'
            || ! getenv('EXFACE_DEPLOYER_TEST_MYSQL_USER') || $port === false) {
            throw new \RuntimeException('Real installation requires explicit opt-in, a workspace outside the live installation, and explicit local test MySQL credentials.');
        }
        return $base;
    }

    /**
     * Creates one unique tree and registers best-effort cleanup for PHP shutdown.
     * A killed process still requires operator cleanup of its exact owned tree.
     *
     * @param string $label
     * @param string|null $base Existing, operator-controlled directory.
     * @return string
     */
    public static function create(string $label, ?string $base = null): string
    {
        $base = $base ?? dirname(__DIR__, 5);
        for ($ancestor = $base; dirname($ancestor) !== $ancestor; $ancestor = dirname($ancestor)) {
            if (NativeBuildFiles::isLink($ancestor)) {
                throw new \RuntimeException('Native test workspace ancestors must be ordinary directories.');
            }
        }
        $base = realpath($base);
        $repository = realpath(dirname(__DIR__, 3));
        if ($base === false || ! is_dir($base)
            || str_starts_with(strtolower($base . DIRECTORY_SEPARATOR), strtolower($repository . DIRECTORY_SEPARATOR))
            || ! preg_match('/^[a-z0-9 -]+$/D', $label)) {
            throw new \RuntimeException('Native test workspace must be outside the Deployer worktree.');
        }
        $root = $base . DIRECTORY_SEPARATOR . '.deployer-test-' . $label . '-' . bin2hex(random_bytes(8));
        if (! mkdir($root, 0700)) {
            throw new \RuntimeException('Cannot create owned native test workspace.');
        }
        register_shutdown_function(
            /**
             * @return void
             */
            static function () use ($root): void {
                try {
                    NativeBuildFiles::remove($root);
                } catch (\Throwable $error) {
                    fwrite(STDERR, "Owned native test workspace cleanup failed; operator review required.\n");
                }
            }
        );
        return $root;
    }
}
