<?php
namespace axenox\Deployer\Common\Execution;

use RuntimeException;

/**
 * Adds cached Asset-Packagist repositories for packages found in a previous Composer lockfile.
 */
class AssetPackagistFix
{
    /**
     * Adds the latest cached Asset-Packagist package repositories from a prior Composer lock.
     *
     * @param string $composerJson
     * @param string $composerLock
     * @return string
     */
    public static function apply(string $composerJson, string $composerLock): string
    {
        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode($composerLock, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($composer) || ! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            throw new RuntimeException('Asset-Packagist fix requires valid Composer JSON and a lockfile packages array.');
        }

        $repositories = [];
        foreach ($lock['packages'] as $package) {
            $packageName = $package['name'] ?? null;
            if (! is_string($packageName)) {
                continue;
            }
            if (str_starts_with($packageName, 'npm-asset/') || str_starts_with($packageName, 'bower-asset/')) {
                $repositories[] = [
                    'type' => 'composer',
                    'url' => 'https://cdn.asset-packagist.org/p/' . $packageName . '/latest.json',
                    'only' => [$packageName]
                ];
            }
        }

        $existingRepositories = $composer['repositories'] ?? [];
        if (! is_array($existingRepositories)) {
            throw new RuntimeException('Composer repositories must be an array to apply the Asset-Packagist fix.');
        }
        $composer['repositories'] = array_merge($repositories, $existingRepositories);
        $encoded = json_encode($composer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Cannot encode composer.json with the Asset-Packagist fix.');
        }
        return $encoded;
    }
}
