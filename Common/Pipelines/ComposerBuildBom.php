<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicensePackageJsonEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseTextEnricher;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonMapError;

/**
 * Builds a PackageManager license BOM from an installed Composer workspace and its local
 * license configuration/evidence. Installed lock identities are authoritative; this class
 * does not resolve dependencies, install packages, or import host configuration as project data.
 *
 * @author Brooklyn Fränzschky
 */
final class ComposerBuildBom
{
    /**
     * Captures an archive-ready workspace after validating its vendor tree, including lock
     * dependencies, bundled package inventories and project license terms.
     *
     * @param string $workspace Installed Composer workspace to inspect.
     * @param string|null $configuration Optional project license-configuration JSON.
     * @return LicenseBOM BOM assembled from installed package identities and local evidence.
     * @throws \RuntimeException If the workspace lacks required installed package inputs.
     */
    public static function capture(string $workspace, ?string $configuration): LicenseBOM
    {
        NativeBuildFiles::validateEntry($workspace, 'vendor');
        if (! is_file($workspace . DIRECTORY_SEPARATOR . 'composer.lock')) {
            throw new \RuntimeException('Build BOM requires an installed composer.lock.');
        }
        return self::captureInstallation($workspace, $configuration);
    }

    /**
     * Captures an installed Composer project without validating native archive entries.
     *
     * Legacy recipes archive their own source files; this only reads the lock, inventories and
     * local license evidence. As before, an existing vendor/licenses.json may stand in for a
     * missing lock; without either package source the capture fails instead of yielding an empty BOM.
     *
     * @param string $workspace Installed Composer workspace containing vendor data.
     * @param string|null $configuration Optional project license-configuration JSON.
     * @return LicenseBOM BOM assembled from local inventories, lock identities and license evidence.
     */
    public static function captureInstallation(string $workspace, ?string $configuration): LicenseBOM
    {
        $vendor = $workspace . DIRECTORY_SEPARATOR . 'vendor';
        $lockPath = $workspace . DIRECTORY_SEPARATOR . 'composer.lock';
        $inventory = $vendor . DIRECTORY_SEPARATOR . 'licenses.json';
        if (! is_dir($vendor) || (! is_file($lockPath) && ! is_file($inventory))) {
            throw new \RuntimeException('Build BOM requires a vendor folder with composer.lock or vendor/licenses.json.');
        }
        $lock = ['packages' => [], 'packages-dev' => []];
        if (is_file($lockPath)) {
            $lockJson = file_get_contents($lockPath);
            if ($lockJson === false) {
                throw new \RuntimeException('Cannot read the Composer lock for the build BOM.');
            }
            $lock = json_decode($lockJson, true, 512, JSON_THROW_ON_ERROR);
        }
        if (! is_array($lock['packages'] ?? null) || ! is_array($lock['packages-dev'] ?? [])) {
            throw new \RuntimeException('Composer lock must contain package arrays.');
        }
        $bom = new LicenseBOM();
        if (is_file($inventory)) {
            self::mergeData($bom, json_decode(file_get_contents($inventory), true, 512, JSON_THROW_ON_ERROR));
        }
        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            self::mergeData($bom, ['packages' => [$package]]);
        }
        foreach (glob($vendor . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'includes.json') as $include) {
            $owner = basename(dirname($include, 2)) . '/' . basename(dirname($include));
            $ownerPackage = $bom->getPackages()[$owner] ?? null;
            $includes = json_decode(file_get_contents($include), true, 512, JSON_THROW_ON_ERROR);
            $includes = isset($includes['packages']) || isset($includes['licenses'])
                ? array_replace(['packages' => []], $includes) : ['packages' => $includes];
            if ($ownerPackage !== null) {
                foreach ($includes['packages'] as &$included) {
                    $included['bundled_in'] = self::packageUrn($ownerPackage->toComposerArray());
                }
                unset($included);
            }
            self::mergeData($bom, $includes);
        }
        $projectFile = $workspace . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Licenses.config.json';
        if (is_file($projectFile)) {
            $projectData = json_decode(file_get_contents($projectFile), true, 512, JSON_THROW_ON_ERROR);
            self::mergeData($bom, isset($projectData['packages']) || isset($projectData['licenses'])
                ? array_replace(['packages' => []], $projectData) : ['packages' => $projectData]);
        }
        if ($configuration !== null && trim($configuration) !== '') {
            $data = json_decode($configuration, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException('Project license configuration must be a JSON object or array.');
            }
            foreach ($data['packages'] ?? (array_is_list($data) ? $data : []) as $package) {
                foreach ($package['licenses'] ?? [] as $license) {
                    if (array_key_exists('text_available', $license) || array_key_exists('source', $license)) {
                        throw new UxonMapError(new UxonObject($package), 'Project license configuration cannot contain result-only license evidence.');
                    }
                }
            }
            self::mergeData($bom, array_is_list($data) ? ['packages' => $data] : array_replace(['packages' => []], $data));
        }
        // Restore installed identities after applying license-only configuration.
        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            self::mergeData($bom, ['packages' => [array_intersect_key($package, ['name' => true, 'version' => true, 'source' => true, 'dist' => true])]]);
        }
        $manifest = new FindLicensePackageJsonEnricher($vendor);
        $files = new FindLicenseFileEnricher($vendor);
        $text = new FindLicenseTextEnricher($vendor);
        foreach ($bom->getPackages() as $package) {
            $data = $package->toComposerArray();
            foreach (array_unique(array_merge($package->getLicenseNames(), [$package->getLicenseUsed()])) as $license) {
                $definition = $bom->getLicenses()[$license] ?? [];
                foreach (['license_file', 'license_text', 'license_link'] as $property) {
                    if (isset($definition[$property])) {
                        $data[$property][$license] = $definition[$property];
                    }
                }
            }
            $package = new BOMPackage($data);
            $package = $manifest->enrich($package);
            $license = $package->getLicenseUsed();
            $before = $package->getLicenseText($license);
            // Configured vendor-relative files are independent of whether the package has a directory.
            $configuredFile = $package->getLicenseFile($license);
            if ($configuredFile !== null) {
                $selected = $package->toComposerArray();
                $selected['license_file'] = [$license => $configuredFile];
                $selected = $files->enrich(new BOMPackage($selected))->toComposerArray();
                $data = $package->toComposerArray();
                $data['license_text'] = $selected['license_text'] ?? $data['license_text'] ?? [];
                $package = new BOMPackage($data);
            }
            $ecosystem = PackageEcosystemDataType::findEcosystem($package->getName(), $data['ecosystem'] ?? null);
            $installedName = PackageEcosystemDataType::convertNameToAssetPackagist($package->getName(), $ecosystem);
            if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $installedName)
                && is_dir($vendor . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $installedName))) {
                $installed = $package->toComposerArray();
                $installed['name'] = $installedName;
                $enriched = $configuredFile === null
                    ? $text->enrich(new BOMPackage($installed))->toComposerArray() : $installed;
                $enriched['name'] = $package->getName();
                $package = new BOMPackage($enriched);
            }
            if ($package->getLicenseText($license) !== $before && ($package->getLicenseText($license) ?? '') !== '') {
                $enriched = $package->toComposerArray();
                $enriched['license_source'][$license] = 'local';
                $package = new BOMPackage($enriched);
            }
            $bom->addPackage($package);
        }
        return $bom;
    }

    /**
     * Merges one PackageManager-compatible inventory into an existing BOM by package identity.
     *
     * @param LicenseBOM $bom BOM to update.
     * @param array $data Inventory data to import.
     * @return void
     */
    private static function mergeData(LicenseBOM $bom, array $data): void
    {
        $incoming = self::fromInventory($data);
        $bom->setLicenses($incoming->getLicenses());
        foreach ($incoming->getPackages() as $key => $package) {
            $existing = $bom->getPackages()[$key] ?? null;
            if ($existing !== null) {
                $old = $existing->toComposerArray();
                $new = $package->toComposerArray();
                $merged = array_replace($old, $new);
                foreach (['license_text', 'license_link', 'license_file', 'license_source'] as $property) {
                    if (is_array($old[$property] ?? null) && is_array($new[$property] ?? null)) {
                        $merged[$property] = array_replace($old[$property], $new[$property]);
                    }
                }
                if (isset($old['license']) || isset($new['license'])) {
                    $merged['license'] = array_values(array_unique(array_merge($old['license'] ?? [], $new['license'] ?? [])));
                }
                if (isset($old['licenses']) || isset($new['licenses'])) {
                    $merged['licenses'] = array_values(array_replace(
                        array_column($old['licenses'] ?? [], null, 'name'),
                        array_column($new['licenses'] ?? [], null, 'name')
                    ));
                }
                $package = new BOMPackage($merged);
            }
            $bom->addPackage($package);
        }
    }

    /**
     * Restores an inventory array into the installed PackageManager's BOM and package objects.
     *
     * The installed LicenseBOM has no data constructor, so inventories must be rebuilt explicitly.
     *
     * @param array $data Inventory containing package rows and optional license definitions.
     * @return LicenseBOM Reconstructed BOM populated from the supplied inventory.
     */
    public static function fromInventory(array $data): LicenseBOM
    {
        if (! is_array($data['packages'] ?? null)) {
            throw new \RuntimeException('Build BOM must contain a packages array.');
        }
        if (! is_array($data['licenses'] ?? [])) {
            throw new \RuntimeException('Build BOM licenses must be an object.');
        }
        $bom = new LicenseBOM();
        $definitions = [];
        foreach ($data['licenses'] ?? [] as $name => $definition) {
            $name = $definition['name'] ?? $name;
            foreach (['text', 'file', 'link'] as $property) {
                if (isset($definition[$property])) {
                    $definition['license_' . $property] = $definition[$property];
                }
            }
            $definitions[$name] = $definition;
        }
        $bom->setLicenses($definitions);
        foreach ($data['packages'] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || $package['name'] === '') {
                throw new \RuntimeException('Build BOM packages must have a name.');
            }
            foreach ($package['licenses'] ?? [] as $license) {
                $name = $license['name'];
                $package['license'][] = $name;
                foreach (['text', 'file', 'link'] as $property) {
                    if (isset($license[$property])) {
                        $package['license_' . $property][$name] = $license[$property];
                    }
                }
                if (isset($license['source'])) {
                    $package['license_source'][$name] = $license['source'];
                }
            }
            if (! isset($package['license']) && ! empty($package['license_text'])) {
                $package['license'] = array_keys($package['license_text']);
            }
            if (isset($package['license'])) {
                $package['license'] = array_values(array_unique($package['license']));
            }
            $bom->addPackage(new BOMPackage($package));
        }
        return $bom;
    }

    /**
     * Serializes compact saved-build identity and evidence without embedding license text.
     * Full license terms remain in the staged inventory; this snapshot is not a full BOM export.
     *
     * @param LicenseBOM $bom BOM whose package identities and license evidence are summarized.
     * @return string JSON snapshot with compact package rows.
     */
    public static function buildSnapshot(LicenseBOM $bom): string
    {
        $rows = [];
        foreach ($bom->getPackages() as $package) {
            $data = $package->toComposerArray();
            $ecosystem = PackageEcosystemDataType::findEcosystem($package->getName(), $data['ecosystem'] ?? null);
            $row = array_intersect_key($data, ['version' => true, 'bundled_in' => true]);
            $row['name'] = PackageEcosystemDataType::convertNameFromAssetPackagist($package->getName(), $ecosystem);
            $row['ecosystem'] = $ecosystem;
            $row['urn'] = self::packageUrn($data);
            $source = $data['source'] ?? [];
            if (($source['type'] ?? '') === 'git' && is_string($source['reference'] ?? null) && $source['reference'] !== '') {
                $row['source'] = ['type' => 'git', 'reference' => $source['reference']];
            }
            $names = $package->getLicenseNames();
            if ($package->hasLicense()) {
                $row['license_used'] = $package->getLicenseUsed();
                $names[] = $package->getLicenseUsed();
            }
            $recorded = array_column($data['licenses'] ?? [], null, 'name');
            $row['licenses'] = [];
            foreach (array_unique($names) as $name) {
                $evidence = ['name' => $name];
                $hasText = trim($package->getLicenseText($name) ?? '') !== '';
                $origin = $data['license_source'][$name] ?? $recorded[$name]['source'] ?? ($hasText ? 'config' : null);
                if ($origin !== null) {
                    $evidence['source'] = $origin;
                }
                $evidence['text_available'] = $hasText || ($recorded[$name]['text_available'] ?? false);
                $row['licenses'][] = $evidence;
            }
            $rows[] = $row;
        }
        return json_encode(['packages' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Builds the ecosystem/name/version identity used by snapshots and advisory links.
     *
     * @param array $package Composer-style package data containing a name and optional ecosystem/version.
     * @return string Version-specific package URN.
     */
    public static function packageUrn(array $package): string
    {
        $ecosystem = PackageEcosystemDataType::findEcosystem($package['name'], $package['ecosystem'] ?? null);
        return $ecosystem . ':' . PackageEcosystemDataType::convertNameFromAssetPackagist($package['name'], $ecosystem)
            . ':' . ($package['version'] ?? '');
    }
}
