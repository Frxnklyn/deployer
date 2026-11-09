<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Exceptions\UxonSyntaxError;

/**
 * Combines project-supplied license definitions with host-configured license settings
 * for deployment-time enrichment. Host settings take precedence for matching values;
 * runtime BOM evidence is deliberately not imported or treated as configuration.
 *
 * @author Brooklyn Fränzschky
 */
final class DeploymentLicenseConfig
{
    /**
     * Merges project package/license entries with the host's configured entries by identity.
     * Project configuration supplies missing values, while host configuration overrides
     * matching package fields and license-definition fields. Result-only evidence is rejected.
     *
     * @param array $config Host-provided configuration map, including optional license settings.
     * @param string|null $projectJson Project license configuration JSON to import, if present.
     * @return array Configuration map with merged license settings; the input map is not mutated.
     * @throws \InvalidArgumentException If the JSON or supported configuration collections are invalid.
     * @throws UxonMapError If project entries contain result-only license evidence.
     * @throws UxonSyntaxError If project JSON cannot be decoded.
     */
    public static function merge(array $config, ?string $projectJson): array
    {
        if ($projectJson === null || trim($projectJson) === '') {
            return $config;
        }
        try {
            $project = json_decode($projectJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new UxonSyntaxError($error->getMessage(), null, $error, $projectJson);
        }
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
                foreach ($package['licenses'] ?? [] as $license) {
                    if (is_array($license)
                        && (array_key_exists('text_available', $license) || array_key_exists('source', $license))) {
                        throw new UxonMapError(
                            new UxonObject($package),
                            'Project license configuration cannot contain result-only license evidence.'
                        );
                    }
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
