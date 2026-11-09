<?php
namespace axenox\Deployer\Common\Pipelines;

use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Exceptions\FileNotFoundError;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves native OTA artifacts and validates native lifecycle callbacks over HTTPS.
 * Deployment lookup/reporting and any legacy redeployment remain action-owned; this
 * transport does not prepare packages or expose model/data-sheet capabilities.
 */
final class NativeOtaHttpHandler
{
    /**
     * Handles native artifact downloads and monotonic lifecycle callbacks for one host.
     *
     * @param ServerRequestInterface $request Incoming PSR-7 request.
     * @param DeploymentHttpContext $context Action-owned lookup, reporting and response context.
     * @return ResponseInterface Download, callback acknowledgement or validation/conflict response.
     */
    public function handle(ServerRequestInterface $request, DeploymentHttpContext $context): ResponseInterface
    {
        $headers = $context->headers;
        $download = $request->getMethod() === 'GET';
        $params = $request->getQueryParams();
        $attempt = $download ? null : ($params['deployment'] ?? null);
        if ($attempt !== null && (! is_string($attempt) || ! preg_match('/^(?:0x)?[a-f0-9]{32}$/iD', $attempt))) {
            return new Response(400, $headers, 'Invalid deployment identity.');
        }
        $deployment = $context->read($attempt);
        if ($deployment !== null && LegacyDeploymentHttpHandler::isLegacyPublication($deployment)) {
            return (new LegacyDeploymentHttpHandler())->handle($request, $context);
        }
        if ($deployment === null && $download) {
            if (BooleanDataType::cast($params['redeploy'] ?? false)) {
                if (strtolower($request->getUri()->getScheme()) !== 'https') {
                    return new Response(400, $headers, 'Native OTA downloads require HTTPS.');
                }
                if ($request->getHeaderLine('X-Deployer-Native-OTA') !== '1') {
                    return new Response(426, $headers, 'This deployment requires a native-OTA-capable SelfUpdate client.');
                }
                try {
                    $context->redeployLegacy();
                } catch (\Throwable $error) {
                    return new Response(500, $headers, 'OTA redeployment failed: ' . $error->getMessage());
                }
                $deployment = $context->read();
            }
            if ($deployment === null) {
                return new Response(304, $headers, 'No published update is available.');
            }
        }
        if (strtolower($request->getUri()->getScheme()) !== 'https') {
            return new Response(400, $headers, $download
                ? 'Native OTA downloads require HTTPS.' : 'Native OTA callbacks require HTTPS.');
        }
        if ($download) {
            if ($request->getHeaderLine('X-Deployer-Native-OTA') !== '1') {
                return new Response(426, $headers, 'This deployment requires a native-OTA-capable SelfUpdate client.');
            }
            return $this->download($deployment, $context);
        }
        if (! is_string($attempt) || ! preg_match('/^(?:0x)?[a-f0-9]{32}$/iD', $attempt)) {
            return new Response(400, $headers, 'Native OTA callbacks require the downloaded deployment identity.');
        }
        if ($deployment === null) {
            return new Response(404, $headers, 'Deployment not found for this host.');
        }
        $currentStatus = (int) $deployment['status'];
        if ($currentStatus >= 90) {
            return new Response(409, $headers, 'Deployment is already terminal.');
        }
        $status = $params['status'] ?? null;
        if ($status !== null) {
            if (! is_scalar($status) || ! in_array((string) $status, ['62', '67', '70', '73', '75', '76', '78', '90', '99'], true)) {
                return new Response(400, $headers, 'Unsupported native OTA callback status.');
            }
            $status = (int) $status;
            if ($status < $currentStatus) {
                return new Response(409, $headers, 'Native OTA status may not move backwards.');
            }
        }
        $final = BooleanDataType::cast($params['final'] ?? false);
        if ($final && $status === null) {
            return new Response(400, $headers, 'Native OTA final callbacks require an explicit status.');
        }
        if ($status !== null && $status >= 90 && ! $final) {
            return new Response(400, $headers, 'Native OTA terminal statuses require a final callback.');
        }
        $received = (string) $request->getBody();
        $values = ['log' => $deployment['log'] . ($received === '.' ? $received : PHP_EOL . $received)];
        if ($status !== null) {
            $values['status'] = $status;
        }
        if ($final && $status >= 90) {
            $values['completed_on'] = DateTimeDataType::now();
        }
        if (! $context->report($values, ['status' => $deployment['status'], 'log' => $deployment['log']])) {
            return new Response(409, $headers, 'Deployment changed while processing callback; reload before retry.');
        }
        return new Response(200, $headers);
    }

    /**
     * Opens the immutable attempt artifact, hashes it and reports its first download transition.
     *
     * @param array $deployment Action-provided deployment snapshot.
     * @param DeploymentHttpContext $context Action-provided installation and project paths.
     * @return ResponseInterface Streamed artifact response, or a conflict if its state changed.
     */
    private function download(array $deployment, DeploymentHttpContext $context): ResponseInterface
    {
        $project = NativeDeploymentPackage::projectPath($context->installationBase, $context->projectFolderRelative);
        $path = NativeOtaPackage::path($project, (string) $deployment['uid']);
        if (! is_file($path)) {
            throw new FileNotFoundError('The selected native OTA artifact is missing.');
        }
        $resource = fopen($path, 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Cannot open the selected native OTA artifact.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $resource);
            rewind($resource);
            $headers = array_merge($context->headers, [
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
                'Content-Length' => (string) fstat($resource)['size'],
                'X-Deployer-Deployment' => (string) $deployment['uid'],
                'X-Deployer-SHA256' => hash_final($hash)
            ]);
            if ((int) $deployment['status'] === 60
                && ! $context->report(['status' => 62], ['status' => $deployment['status']])) {
                fclose($resource);
                return new Response(409, $context->headers, 'Deployment changed while opening download; retry.');
            }
            return new Response(200, $headers, Utils::streamFor($resource));
        } catch (\Throwable $error) {
            fclose($resource);
            throw $error;
        }
    }
}
