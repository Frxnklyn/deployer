<?php
/**
 * New-runtime-only adapter. Uses the throwing application-install API, not the
 * legacy Composer hook/CLI action which can swallow per-app failures.
 *
 * @author Brooklyn Fränzschky
 */
try {
    $release = $argv[1] ?? '';
    if (! is_dir($release) || ! chdir($release)) {
        throw new RuntimeException('New runtime directory is unavailable.');
    }
    $runtimeLock = fopen(dirname(dirname($release)) . DIRECTORY_SEPARATOR . 'runtime.lock', 'c+b');
    if ($runtimeLock === false || ! flock($runtimeLock, LOCK_SH | LOCK_NB)) {
        throw new RuntimeException('Filesystem recovery holds the runtime lock.');
    }
    require $release . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (($argv[2] ?? '') !== '--worker') {
        $aliases = \axenox\PackageManager\Actions\ListApps::findAppAliasesInVendorFolders(
            $release . DIRECTORY_SEPARATOR . 'vendor'
        );
        $aliases = array_values(array_unique(array_merge(['exface.Core'], $aliases)));
        $runner = \exface\Core\Facades\ConsoleFacade\CliCommandRunner::class;
        foreach ($aliases as $alias) {
            $receipt = $release . DIRECTORY_SEPARATOR . '.native-worker-result';
            if (file_exists($receipt)) {
                unlink($receipt);
            }
            $arguments = ['-d', 'memory_limit=' . ini_get('memory_limit'), __FILE__, $release, '--worker', $alias];
            if (is_callable([$runner, 'runCliCommandIntoArray'])) {
                $runner::runCliCommandIntoArray(PHP_BINARY, $arguments, $release, [0], 3600);
            } else {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'NativeBuildFiles.php';
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'NativeHostInstaller.php';
                \axenox\Deployer\Common\Pipelines\NativeHostInstaller::command(
                    array_merge([PHP_BINARY], $arguments), $release, 3600
                );
            }
            if (! is_file($receipt) || file_get_contents($receipt) !== $alias) {
                throw new RuntimeException('Application installer exited without confirmation.');
            }
            unlink($receipt);
        }
        if (file_put_contents($release . DIRECTORY_SEPARATOR . '.native-install-result', basename($release)) === false) {
            throw new RuntimeException('Cannot confirm completed application installation.');
        }
        exit(0);
    }
    $bootstrap = new \axenox\PackageManager\StaticInstaller();
    $workbench = $bootstrap->getWorkbench();
    try {
        $action = \exface\Core\Factories\ActionFactory::createFromString($workbench, 'axenox.PackageManager.InstallApp');
        $selector = new \exface\Core\CommonLogic\Selectors\AppSelector($workbench, $argv[3]);
        foreach ($action->installApp($selector) as $message) {
            if (is_string($message) && preg_match('/^\s*(?:ERROR|FAILED)\b/m', $message)) {
                throw new RuntimeException('An application installer reported failure.');
            }
        }
    } finally {
        $workbench->stop();
    }
    if (file_put_contents($release . DIRECTORY_SEPARATOR . '.native-worker-result', $argv[3]) === false) {
        throw new RuntimeException('Cannot confirm application installation.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "New-runtime application installation failed; diagnostics suppressed.\n");
    exit(1);
}
