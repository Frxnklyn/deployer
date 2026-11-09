<?php
namespace axenox\Deployer\Common\Pipelines;

/**
 * Rewrites an in-memory Composer manifest to prioritize Asset-Packagist cache repositories
 * for asset packages named by an explicitly supplied previous lock. It does not retrieve
 * or infer build history, edit files, or suppress the workaround when its required lock
 * input is absent or invalid.
 */
final class AssetPackagistCache
{
    /**
     * Adds a scoped cache repository for each distinct npm/bower asset in the prior lock,
     * ahead of existing Composer repositories while preserving list or named-map form.
     *
     * @param string $composerJson Composer manifest JSON to transform; the source is not modified.
     * @param string|null $previousLock Explicit previous-successful-build lock JSON.
     * @return string Transformed manifest JSON.
     * @throws \InvalidArgumentException If either JSON document or its required structure is invalid.
     * @throws \RuntimeException If the required previous lock is absent or blank.
     */
    public static function apply(string $composerJson, ?string $previousLock): string
    {
        if ($previousLock === null || trim($previousLock) === '') {
            throw new \RuntimeException('The asset cache workaround requires a previous successful build with composer.lock.');
        }
        try {
            $manifest = json_decode($composerJson, false, 512, JSON_THROW_ON_ERROR);
            $lock = json_decode($previousLock, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('The asset cache workaround requires valid Composer and previous-lock JSON.');
        }
        if (! $manifest instanceof \stdClass || ! $lock instanceof \stdClass
            || ! isset($lock->packages) || ! is_array($lock->packages)
            || (isset($lock->{'packages-dev'}) && ! is_array($lock->{'packages-dev'}))
            || (isset($manifest->repositories) && ! is_array($manifest->repositories) && ! $manifest->repositories instanceof \stdClass)) {
            throw new \InvalidArgumentException('Invalid Composer repository or previous-lock structure for the asset cache workaround.');
        }
        $repositories = [];
        $seen = [];
        foreach (array_merge($lock->packages, $lock->{'packages-dev'} ?? []) as $package) {
            if (! $package instanceof \stdClass || ! is_string($package->name ?? null)) {
                throw new \InvalidArgumentException('Previous lock packages must have a package name.');
            }
            $name = $package->name;
            if (! str_starts_with($name, 'npm-asset/') && ! str_starts_with($name, 'bower-asset/')) {
                continue;
            }
            if (! preg_match('~^(?:npm|bower)-asset/[a-z0-9][a-z0-9_.-]*$~D', $name)) {
                throw new \InvalidArgumentException('Invalid asset package identity in the previous lock.');
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $repositories[] = (object) [
                'type' => 'composer',
                'url' => 'https://cdn.asset-packagist.org/p/' . $name . '/latest.json',
                'only' => [$name]
            ];
        }
        $existing = $manifest->repositories ?? [];
        if (is_array($existing)) {
            $manifest->repositories = array_merge($repositories, $existing);
        } else {
            // Composer supports named repositories and named disabled defaults.
            $named = [];
            foreach ($repositories as $index => $repository) {
                $key = 'deployer-asset-cache-' . $index;
                while (property_exists($existing, $key) || array_key_exists($key, $named)) {
                    $key .= '-cache';
                }
                $named[$key] = $repository;
            }
            $manifest->repositories = (object) array_merge($named, (array) $existing);
        }
        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
