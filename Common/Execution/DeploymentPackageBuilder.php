<?php
namespace axenox\Deployer\Common\Execution;

use RuntimeException;

/**
 * Builds a configured self-extracting deployment artifact from a template and build archive.
 */
class DeploymentPackageBuilder
{
    /**
     * Creates a self-extracting PHP deployment file containing the build archive.
     *
     * @param string $templatePath
     * @param string $archivePath
     * @param string $outputPath
     * @param array<string, mixed> $configuration
     */
    public function build(string $templatePath, string $archivePath, string $outputPath, array $configuration): void
    {
        if (! is_file($templatePath)) {
            throw new RuntimeException('Self-extractor template was not found: ' . $templatePath);
        }
        if (! is_file($archivePath)) {
            throw new RuntimeException('Build archive was not found: ' . $archivePath);
        }

        $template = file_get_contents($templatePath);
        if ($template === false) {
            throw new RuntimeException('Cannot read the self-extractor template.');
        }

        $keepReleases = $configuration['keep_releases'] ?? 4;
        if (is_string($keepReleases) && preg_match('/^-?\d+$/', $keepReleases) === 1) {
            $keepReleases = (int) $keepReleases;
        }
        if (! is_int($keepReleases) || $keepReleases < -1) {
            throw new RuntimeException('Deployment keep_releases must be -1 or a nonnegative integer.');
        }
        foreach (['shared_dirs', 'copy_dirs', 'deploy_config'] as $arrayKey) {
            if (! is_array($configuration[$arrayKey] ?? [])) {
                throw new RuntimeException('Deployment configuration field "' . $arrayKey . '" must be an array.');
            }
        }
        $basicPath = $configuration['basic_deploy_path'] ?? '';
        $relativePath = $configuration['relative_deploy_path'] ?? '';
        if (! is_string($basicPath) || $basicPath === '') {
            throw new RuntimeException('The host base deployment path (path_abs_to_api) must be configured.');
        }
        if (! is_string($relativePath)) {
            throw new RuntimeException('The host relative deployment path (path_rel_to_releases) must be a string; an empty string deploys at the base path.');
        }

        $replacements = [
            "'[#basic#]'" => var_export($basicPath, true),
            "'[#relative#]'" => var_export($relativePath, true),
            '[#shared#]' => var_export($configuration['shared_dirs'] ?? [], true),
            '[#copy#]' => var_export($configuration['copy_dirs'] ?? [], true),
            '[#releases#]' => (string) $keepReleases,
            '[#deploy_config#]' => var_export($configuration['deploy_config'] ?? [], true),
            "'[#php#]'" => var_export((string) ($configuration['php_path'] ?? 'php'), true)
        ];
        $template = strtr($template, $replacements);
        if (preg_match('/\[#(?:basic|relative|shared|copy|releases|deploy_config|php)#\]/', $template) === 1) {
            throw new RuntimeException('Self-extractor template contains an unresolved configuration placeholder.');
        }

        $output = fopen($outputPath, 'wb');
        if ($output === false) {
            throw new RuntimeException('Cannot create self-extractor file: ' . $outputPath);
        }
        try {
            if (fwrite($output, $template) === false) {
                throw new RuntimeException('Cannot write the self-extractor template.');
            }
            $archive = fopen($archivePath, 'rb');
            if ($archive === false) {
                throw new RuntimeException('Cannot open build archive: ' . $archivePath);
            }
            try {
                if (stream_copy_to_stream($archive, $output) === false) {
                    throw new RuntimeException('Cannot append the build archive to the self-extractor.');
                }
            } finally {
                fclose($archive);
            }
        } finally {
            fclose($output);
        }
    }
}
