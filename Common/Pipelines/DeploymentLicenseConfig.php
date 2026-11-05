<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\PackageManager\DataTypes\PackageEcosystemDataType;

/**
 * Merges license configuration as data, without importing runtime BOM evidence.
 *
 * @author Brooklyn Fränzschky
 */
final class DeploymentLicenseConfig
{
    /**
     * Keeps host precedence, matching packages and license definitions by identity.
     *
     * Uses the Composer-style configuration supported by the installed PackageManager;
     * does not depend on the legacy Deploy helper's unavailable BOM serialization API.
     *
     * @param array $config
     * @param string|null $projectJson
     * @return array
     */
    public static function merge(array $config, ?string $projectJson): array
    {
        if ($projectJson === null || trim($projectJson) === '') {
            return $config;
        }
        $project = json_decode($projectJson, true, 512, JSON_THROW_ON_ERROR);
        $host = $config['Licenses.config.json'] ?? [];
        if (! is_array($project) || ! is_array($host)) {
            throw new \InvalidArgumentException('License configuration must be an object or package list.');
        }
        $packages = [];
        $licenses = [];
        foreach ([$project, $host] as $input) {
            $definitions = $input['licenses'] ?? [];
            $entries = $input['packages'] ?? (array_is_list($input) ? $input : []);
            if (! is_array($definitions) || ! is_array($entries)) {
                throw new \InvalidArgumentException('Invalid license configuration collections.');
            }
            foreach ($definitions as $key => $definition) {
                if (! is_array($definition)) {
                    throw new \InvalidArgumentException('License definitions require object values.');
                }
                $name = is_int($key) ? ($definition['name'] ?? null) : $key;
                if (! is_string($name) || $name === '') {
                    throw new \InvalidArgumentException('License definitions require names and object values.');
                }
                foreach (['text' => 'license_text', 'link' => 'license_link', 'file' => 'license_file'] as $source => $target) {
                    if (array_key_exists($source, $definition)) {
                        $definition[$target] = $definition[$source];
                        unset($definition[$source]);
                    }
                }
                unset($definition['name']);
                $licenses[$name] = array_replace($licenses[$name] ?? [], $definition);
            }
            foreach ($entries as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null) || $package['name'] === '') {
                    throw new \InvalidArgumentException('Configured packages require names.');
                }
                $key = PackageEcosystemDataType::buildPackageKey($package['name'], $package['ecosystem'] ?? null);
                $previous = $packages[$key] ?? [];
                $merged = array_replace($previous, $package);
                foreach (['license_text', 'license_link', 'license_file'] as $property) {
                    if (isset($previous[$property], $package[$property])
                        && is_array($previous[$property]) && is_array($package[$property])) {
                        $merged[$property] = array_replace($previous[$property], $package[$property]);
                    }
                }
                $packages[$key] = $merged;
            }
        }
        $project = array_is_list($project) ? [] : $project;
        $host = array_is_list($host) ? [] : $host;
        $config['Licenses.config.json'] = array_replace($project, $host, [
            'packages' => array_values($packages),
            'licenses' => $licenses
        ]);
        return $config;
    }
}
