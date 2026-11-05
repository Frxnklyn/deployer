<?php
/**
 * Standalone CLI entry point; deliberately never loads the old installation.
 *
 * @author Brooklyn Fränzschky
 */
use axenox\Deployer\Common\Pipelines\NativeHostInstaller;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80100) {
    exit(1);
}
require __DIR__ . DIRECTORY_SEPARATOR . 'NativeBuildFiles.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'NativeDeploymentPackage.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'NativeHostInstaller.php';

try {
    $args = array_slice($argv, 1);
    if (count($args) < 2 || count($args) > 3 || $args[0] !== '--root'
        || (isset($args[2]) && $args[2] !== '--recover')) {
        throw new RuntimeException('Usage: php installer.php --root <absolute-host-root> [--recover]');
    }
    NativeHostInstaller::run(__DIR__, $args[1], isset($args[2]));
    fwrite(STDOUT, isset($args[2]) ? "Filesystem recovery completed; database state requires operator review.\n"
        : "Application installation and filesystem activation completed. No deployment callback was sent.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Installation stopped: " . $error->getMessage() . "\n");
    exit(1);
}
