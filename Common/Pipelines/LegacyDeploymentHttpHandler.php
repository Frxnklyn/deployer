<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Actions\Deploy;
use axenox\PackageManager\Common\Updater\SelfUpdateInstaller;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Exceptions\FileNotFoundError;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves legacy self-update publications and their lifecycle callbacks for compatibility.
 * It does not prepare a selected deployment pipeline's artifact or substitute for that
 * pipeline; native push selection also prevents this handler from serving non-legacy files.
 */
final class LegacyDeploymentHttpHandler
{
    /**
     * Records whether the host has explicitly selected a push pipeline.
     *
     * @param bool $selectedPush Whether legacy file delivery must be limited to legacy publications.
     */
    public function __construct(private bool $selectedPush = false)
    {
    }

    /**
     * Classifies the historical recipe selectors eligible for compatibility delivery.
     *
     * @param array $deployment Deployment snapshot containing the saved recipe filename.
     * @return bool Whether the saved selector represents a legacy publication.
     */
    public static function isLegacyPublication(array $deployment): bool
    {
        $prototype = str_replace('\\', '/', (string) $deployment['deploy_recipe_file']);
        return $prototype === '' || (bool) preg_match('~(?:^|/)Recipes/Deploy/[^/]+\.php$~iD', $prototype)
            || (bool) preg_match('~^LocalBld[A-Za-z]+(?:\.php)?$~D', $prototype);
    }

    /**
     * Handles a legacy download request or callback using only the supplied action context.
     *
     * @param ServerRequestInterface $request Incoming PSR-7 request.
     * @param DeploymentHttpContext $context Action-owned deployment lookup and reporting callbacks.
     * @return ResponseInterface Legacy download, callback acknowledgement or validation response.
     */
    public function handle(ServerRequestInterface $request, DeploymentHttpContext $context): ResponseInterface
    {
        $download = $request->getMethod() === 'GET';
        $params = $request->getQueryParams();
        $attempt = $download ? null : ($params['deployment'] ?? null);
        if ($attempt !== null && (! is_string($attempt) || ! preg_match('/^(?:0x)?[a-f0-9]{32}$/iD', $attempt))) {
            return new Response(400, $context->headers, 'Invalid deployment identity.');
        }
        $deployment = $context->read($attempt);
        if ($deployment === null && $download && BooleanDataType::cast($params['redeploy'] ?? false)) {
            try {
                $context->redeployLegacy();
                $deployment = $context->read();
            } catch (\Throwable $error) {
                return new Response(500, $context->headers, 'OTA redeployment failed: ' . $error->getMessage());
            }
        }
        if ($deployment === null) {
            return new Response($download ? 304 : 404, $context->headers, 'No published update is available.');
        }
        if (! $this->selectedPush && ! self::isLegacyPublication($deployment)) {
            return new Response(409, $context->headers, 'Configure the host deployment pipeline before accessing this publication.');
        }
        if ($download) {
            return $this->download($deployment, $context);
        }
        $received = (string) $request->getBody();
        $status = $params['status'] ?? null;
        $final = array_key_exists('final', $params) ? $params['final']
            : (bool) preg_match('/^(?:Finished self-update successfully!|FAILED self-update!)/', $received);
        $values = ['log' => $deployment['log'] . ($received === '.' ? $received : PHP_EOL . $received)];
        if ($final) {
            $status = $status ?? (array_key_exists('error', $params) || self::isCliError($received) ? 90 : 99);
            $values['completed_on'] = DateTimeDataType::now();
        }
        if ($status !== null) {
            $values['status'] = $status;
        }
        $context->report($values);
        return new Response(200, $context->headers);
    }

    /**
     * Detects legacy CLI failure markers used when no explicit status is supplied.
     *
     * @param string $output Callback body from the legacy self-update client.
     * @return bool Whether the output contains a recognized failure marker.
     */
    public static function isCliError(string $output): bool
    {
        return mb_strpos($output, 'ERROR') !== false
            || mb_strpos($output, 'FAILED') !== false
            || mb_stripos($output, SelfUpdateInstaller::MESSAGE_INSTALLATION_FAILED)
            || mb_stripos($output, 'PHP Fatal error');
    }

    /**
     * Opens the selected historical build/host artifact and reports the download state.
     *
     * @param array $deployment Action-provided deployment snapshot.
     * @param DeploymentHttpContext $context Action-provided project path, headers and limits.
     * @return ResponseInterface File response for the historical publication.
     */
    private function download(array $deployment, DeploymentHttpContext $context): ResponseInterface
    {
        $filename = $deployment['build__name'] . '_' . Deploy::getHostAlias($deployment['host__name']) . '.phx';
        $path = $context->projectPath . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . $filename;
        if (! file_exists($path)) {
            throw new FileNotFoundError('Deployment file ' . $path . ' not found!');
        }
        $current = (int) ini_get('max_execution_time');
        if ($current > 0 && $current < $context->downloadMaxExecutionTime) {
            set_time_limit($context->downloadMaxExecutionTime);
        }
        $headers = array_merge($context->headers, [
            'Expires' => 0,
            'Cache-Control', 'must-revalidate, post-check=0, pre-check=0',
            'Pragma' => 'public',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Content-Type' => 'application/x-httpd-php'
        ]);
        $stream = Utils::streamFor(fopen($path, 'r'));
        $context->report(['status' => 62]);
        return new Response(200, $headers, $stream);
    }
}
