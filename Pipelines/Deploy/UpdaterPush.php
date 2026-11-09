<?php
namespace axenox\Deployer\Pipelines\Deploy;

use axenox\Deployer\Common\Pipelines\DeploymentResult;
use axenox\Deployer\Common\Pipelines\NativeOtaPackage;
use axenox\Deployer\Common\Pipelines\NativePushTransport;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\Core\CommonLogic\UxonObject;
use axenox\Deployer\Common\Pipelines\DeploymentHttpContext;
use axenox\Deployer\Common\Pipelines\LegacyDeploymentHttpHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pushes the same standalone native package used by OTA, without recipe execution.
 *
 * Prototype UXON is allowlisted to installer and transport options. The owning
 * action supplies the host connection and other host settings, and UXON cannot
 * provide connection credentials or request URLs.
 */
class UpdaterPush extends ManualTransfer
{
    /**
     * Delegates supported HTTP lifecycle requests to the legacy deployment handler.
     *
     * @param ServerRequestInterface $request Incoming request.
     * @param DeploymentHttpContext $context Action-owned publication and reporting context.
     * @return ResponseInterface HTTP response produced by the deployment handler.
     */
    public function handleDeploymentHttpRequest(ServerRequestInterface $request, DeploymentHttpContext $context): ResponseInterface
    {
        return (new LegacyDeploymentHttpHandler(true))->handle($request, $context);
    }

    private ?HttpConnector $connection = null;
    private ?string $trustedCaBundle = null;
    private int $deadline = 900;

    /**
     * Imports only documented non-host settings; public host setters are not UXON.
     *
     * Imports UXON through the transport-specific allowlist.
     *
     * @param UxonObject $uxon Pipeline-specific configuration to import.
     * @param array $skip_property_names Properties Core should skip during import.
     * @return void
     * @throws \InvalidArgumentException If UXON contains a setting outside the allowlist.
     */
    public function importUxonObject(UxonObject $uxon, array $skip_property_names = [])
    {
        $this->importUxonObjectWithOrder($uxon, $skip_property_names);
    }

    /**
     * Applies the same allowlist to the trait's public ordered import entry point.
     *
     * Applies the native-push allowlist before ordered Core UXON import.
     *
     * @param UxonObject $uxon Pipeline-specific configuration to import.
     * @param array $skip_property_names Properties Core should skip during import.
     * @param array $propertiesToSetFirst Property names Core should import first.
     * @param array $propertiesToSetLast Property names Core should import last.
     * @return void
     * @throws \InvalidArgumentException If UXON contains a setting outside the allowlist.
     */
    public function importUxonObjectWithOrder(UxonObject $uxon, array $skip_property_names = [],
        array $propertiesToSetFirst = [], array $propertiesToSetLast = [])
    {
        $allowed = ['config', 'keep_releases', 'shared_dirs', 'copy_dirs', 'local_vendors',
            'local_packages', 'php_memory_limit', 'trusted_ca_bundle', 'deadline_seconds'];
        foreach (array_keys($uxon->toArray()) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported native push UXON setting; host inputs must come from the action.');
            }
        }
        parent::importUxonObjectWithOrder($uxon, $skip_property_names, $propertiesToSetFirst, $propertiesToSetLast);
    }

    /**
     * Optional readable PEM trust bundle; verification can never be disabled.
     *
     * @uxon-property trusted_ca_bundle
     * @uxon-type string
     * @param string $path Readable local CA bundle used in addition to default TLS trust.
     * @return $this
     * @throws \InvalidArgumentException If the path is not a readable file.
     */
    public function setTrustedCaBundle(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('Native push CA bundle must be a readable local file.');
        }
        $this->trustedCaBundle = $path;
        return $this;
    }

    /**
     * Bounds the full HTTP upload/response request, including installer execution.
     *
     * @uxon-property deadline_seconds
     * @uxon-type integer
     * @uxon-default 900
     * @param int $seconds Total request deadline in seconds.
     * @return $this
     * @throws \InvalidArgumentException If the deadline is outside the supported range.
     */
    public function setDeadlineSeconds(int $seconds): self
    {
        if ($seconds < 10 || $seconds > 1800) {
            throw new \InvalidArgumentException('Native push deadline must be between 10 and 1800 seconds.');
        }
        $this->deadline = $seconds;
        return $this;
    }

    /**
     * Retains all installer settings and non-secret push options across roundtrips.
     *
     * @return UxonObject
     */
    public function exportUxonObject(): UxonObject
    {
        $settings = parent::exportUxonObject()->toArray();
        $settings['deadline_seconds'] = $this->deadline;
        if ($this->trustedCaBundle !== null) {
            $settings['trusted_ca_bundle'] = $this->trustedCaBundle;
        }
        return new UxonObject($settings);
    }

    /**
     * Action-owned connection only; never accepts credentials or URLs from UXON.
     *
     * @param HttpConnectionInterface $connection Action-supplied host HTTP connection.
     * @return $this
     * @throws \InvalidArgumentException If the connection cannot enforce secure request options.
     */
    public function setConnection(HttpConnectionInterface $connection): self
    {
        if (! $connection instanceof HttpConnector) {
            throw new \InvalidArgumentException('Native push requires an HTTP connector supporting secure request options.');
        }
        $this->connection = $connection;
        return $this;
    }

    /**
     * Retains a private manual-recovery package on every transport/install failure.
     *
     * @param PipelineTaskInterface $task Release inputs supplied by the owning action.
     * @return \Generator<int, string, mixed, DeploymentResult> Progress messages and the reached deployment status.
     * @throws \InvalidArgumentException If the task is not a release task or no connection was injected.
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (! $task instanceof ReleaseDeploymentTask || $this->connection === null) {
            throw new \InvalidArgumentException('Native push requires an action-supplied HTTP connection and release task.');
        }
        $preparation = parent::run($task);
        yield from $preparation;
        $package = NativeOtaPackage::publish($task, $preparation->getReturn());
        yield "Native push manual-recovery package (private; contains configuration): " . $package->artifactPath
            . "\nSHA-256: " . $package->sha256 . "\n"
            . "If installation fails, stop traffic/jobs and installer workers before executing this same package with --recover.\n"
            . "Filesystem recovery does not undo database changes. Legacy/unmanaged layouts are not adopted.\n";
        try {
            $status = NativePushTransport::upload($this->connection, $package, $task->deploymentId,
                $this->deadline, $this->trustedCaBundle);
        } catch (\Throwable $error) {
            yield "Native push failed (status 90). Retained private package above is available for operator recovery; no installation success is claimed.\n";
            return new DeploymentResult(90);
        }
        yield $status === 99
            ? "Native push installation independently verified by matching completion receipt.\n"
            : "Native push uploaded only (status 60); installer completion has not been verified.\n";
        return new DeploymentResult($status);
    }
}
