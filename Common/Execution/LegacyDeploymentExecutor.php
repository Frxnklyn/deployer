<?php
namespace axenox\Deployer\Common\Execution;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Maps supported legacy deployment selections to native artifact publishing or transfer instructions.
 */
class LegacyDeploymentExecutor
{
    private DeploymentPackageBuilder $packageBuilder;
    private ClientInterface $httpClient;

    /**
     * @param DeploymentPackageBuilder|null $packageBuilder
     * @param ClientInterface|null $httpClient
     */
    public function __construct(DeploymentPackageBuilder $packageBuilder = null, ClientInterface $httpClient = null)
    {
        $this->packageBuilder = $packageBuilder ?? new DeploymentPackageBuilder();
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * Executes a supported legacy deployment selection without loading a recipe engine.
     *
     * @param string $recipe
     * @param string $buildName
     * @param string $hostAlias
     * @param string $archivePath
     * @param string $outputDirectory
     * @param string $templatePath
     * @param array<string, mixed> $hostConfiguration
     * @param array<string, mixed> $deployConfiguration
     * @param array<string, mixed> $connectionConfiguration
     * @return \Generator<int, string, mixed, array{published: bool, artifact_path: string}>
     */
    public function execute(
        string $recipe,
        string $buildName,
        string $hostAlias,
        string $archivePath,
        string $outputDirectory,
        string $templatePath,
        array $hostConfiguration,
        array $deployConfiguration,
        array $connectionConfiguration
    ): \Generator {
        $this->assertSupportedRecipe($recipe);
        $this->assertSafeArtifactName($buildName);
        if ($hostAlias === '') {
            throw new RuntimeException('The selected host does not produce a valid artifact name.');
        }

        $artifactPath = $outputDirectory . DIRECTORY_SEPARATOR . $buildName . '_' . $hostAlias . '.phx';
        $configuration = [
            'basic_deploy_path' => $hostConfiguration['basic_deploy_path'] ?? '',
            'relative_deploy_path' => $hostConfiguration['relative_deploy_path'] ?? '',
            'shared_dirs' => ['backup', 'data', 'logs', 'translations'],
            'copy_dirs' => ['config'],
            'keep_releases' => $deployConfiguration['keep_releases'] ?? 4,
            'deploy_config' => $deployConfiguration,
            'php_path' => $hostConfiguration['php_path'] ?? 'php'
        ];
        $this->packageBuilder->build($templatePath, $archivePath, $artifactPath, $configuration);
        yield 'Created deployment artifact "' . $artifactPath . '".' . PHP_EOL;

        $published = false;
        switch ($recipe) {
            case 'LocalBldUpdaterPush':
                yield from $this->uploadToUpdater($artifactPath, $connectionConfiguration);
                yield $this->rollbackInstructions();
                break;
            case 'LocalBldUpdaterPull':
                yield $this->updaterPullInstructions($artifactPath, $hostConfiguration['php_path'] ?? 'php');
                yield $this->rollbackInstructions();
                $published = true;
                break;
            case 'LocalBldUpdaterCron':
                yield $this->updaterCronInstructions($artifactPath, $hostConfiguration['php_path'] ?? 'php');
                yield $this->rollbackInstructions();
                $published = true;
                break;
            case 'LocalBldUsbSelfExtractor':
                yield $this->manualTransferInstructions($artifactPath, $hostConfiguration['php_path'] ?? 'php');
                yield $this->rollbackInstructions();
                break;
            case 'LocalBldAzureAppServiceManualInstall':
                yield $this->azureInstructions(basename($artifactPath), $hostConfiguration['php_path'] ?? 'php');
                yield $this->rollbackInstructions();
                break;
        }

        return [
            'published' => $published,
            'artifact_path' => $artifactPath
        ];
    }

    /**
     * Produces a clear failure for recipes that depended on removed procedural execution.
     *
     * @param string $recipe
     */
    private function assertSupportedRecipe(string $recipe): void
    {
        $supported = [
            'LocalBldUpdaterPush',
            'LocalBldUpdaterPull',
            'LocalBldUpdaterCron',
            'LocalBldUsbSelfExtractor',
            'LocalBldAzureAppServiceManualInstall'
        ];
        if (! in_array($recipe, $supported, true)) {
            $reason = in_array($recipe, [
                'LocalBldSshSelfExtractor',
                'LocalBldSshInstall',
                'LocalBldSshInstallWait'
            ], true)
                ? 'SSH deployment recipes are no longer supported; use an updater or manual-transfer recipe.'
                : 'Custom and unknown recipe selections are not executed; select a supported updater or manual-transfer recipe.';
            throw new RuntimeException('Deployment recipe "' . $recipe . '" is not supported. ' . $reason);
        }
    }

    /**
     * Prevents legacy build names from escaping the project archive directory.
     *
     * @param string $buildName
     */
    private function assertSafeArtifactName(string $buildName): void
    {
        if ($buildName === '' || preg_match('/[<>:"\/\\\\|?*\x00-\x1F]/', $buildName) === 1 || rtrim($buildName, ". ") !== $buildName) {
            throw new RuntimeException('The selected build name cannot be used safely as an artifact filename.');
        }
    }

    /**
     * Uploads the self-extractor to the configured PackageManager updater endpoint.
     *
     * @param string $artifactPath
     * @param array<string, mixed> $connectionConfiguration
     * @return \Generator<int, string>
     */
    private function uploadToUpdater(string $artifactPath, array $connectionConfiguration): \Generator
    {
        $url = $connectionConfiguration['url'] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
            throw new RuntimeException('Updater push requires a data connection with a valid HTTP(S) URL.');
        }

        $file = fopen($artifactPath, 'rb');
        if ($file === false) {
            throw new RuntimeException('Cannot open the deployment artifact for updater upload.');
        }
        try {
            $options = [
                'verify' => true,
                'timeout' => 900,
                'connect_timeout' => 30,
                'multipart' => [[
                    'name' => basename($artifactPath),
                    'filename' => basename($artifactPath),
                    'contents' => $file
                ]]
            ];
            $user = $connectionConfiguration['user'] ?? '';
            if (is_string($user) && $user !== '') {
                $options['auth'] = [
                    $user,
                    (string) ($connectionConfiguration['password'] ?? '')
                ];
            }
            $response = $this->httpClient->request('POST', $url, $options);
            yield 'Updater accepted the deployment artifact (HTTP ' . $response->getStatusCode() . ').' . PHP_EOL;
            $responseBody = trim((string) $response->getBody());
            if ($responseBody !== '') {
                yield $responseBody . PHP_EOL;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Updater push failed; verify the endpoint and credentials, then retry.', 0, $e);
        } finally {
            fclose($file);
        }
    }

    /**
     * Provides the existing PackageManager pull workflow instructions.
     *
     * @param string $artifactPath
     * @param string $phpPath
     */
    private function updaterPullInstructions(string $artifactPath, string $phpPath): string
    {
        return 'Deployment artifact "' . $artifactPath . '" is published for download through the target host scheduler.' . PHP_EOL
            . 'If it is not installed within 10-30 minutes, run "vendor/bin/action axenox.PackageManager:SelfUpdate" on the host.' . PHP_EOL
            . 'For manual installation, run "vendor/bin/action axenox.PackageManager:SelfUpdate --download-only=true", then "'
            . $phpPath . ' -d memory_limit=2G path/to/' . basename($artifactPath) . '".' . PHP_EOL;
    }

    /**
     * Provides the existing PackageManager scheduler workflow instructions.
     *
     * @param string $artifactPath
     * @param string $phpPath
     */
    private function updaterCronInstructions(string $artifactPath, string $phpPath): string
    {
        return $this->updaterPullInstructions($artifactPath, $phpPath);
    }

    /**
     * Provides transfer and execution instructions for a manually transferred artifact.
     *
     * @param string $artifactPath
     * @param string $phpPath
     */
    private function manualTransferInstructions(string $artifactPath, string $phpPath): string
    {
        return 'Transfer the self-extractor to the host and execute it there:' . PHP_EOL
            . '1) Download "' . $artifactPath . '"' . PHP_EOL
            . '2) Upload it to the host.' . PHP_EOL
            . '3) Open the host command line as an administrator to avoid symlink permission errors.' . PHP_EOL
            . '4) Run "' . $phpPath . ' -d memory_limit=2G path/to/' . basename($artifactPath) . '".' . PHP_EOL;
    }

    /**
     * Provides the existing Azure App Service manual upload instructions.
     *
     * @param string $filename
     * @param string $phpPath
     */
    private function azureInstructions(string $filename, string $phpPath): string
    {
        return 'Upload the build to Azure App Service and install it there:' . PHP_EOL
            . '1) Download "' . $filename . '" to your downloads folder.' . PHP_EOL
            . '2) Use Azure Kudu zipdeploy with the Azure FTP(S) credentials and App Service name.' . PHP_EOL
            . '3) Wait for the upload to finish, then open the App Service SSH console.' . PHP_EOL
            . '4) Run "' . $phpPath . ' -d memory_limit=2G path/to/' . $filename . '".' . PHP_EOL;
    }

    /**
     * Preserves the existing rollback guidance for a failed self-extractor installation.
     */
    private function rollbackInstructions(): string
    {
        return 'Rollback: replace the current symlink with the last working release, then run '
            . '"current/vendor/bin/action axenox.PackageManager:InstallApp exface.Core".' . PHP_EOL;
    }
}
