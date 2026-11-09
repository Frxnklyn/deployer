<?php
namespace axenox\Deployer\Pipelines\Deploy;

use axenox\Deployer\Common\Pipelines\NativeOtaPackage;
use axenox\Deployer\Common\Pipelines\PreparedDeploymentResult;
use axenox\Deployer\Common\Pipelines\ReleaseDeploymentTask;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use axenox\Deployer\Common\Pipelines\DeploymentHttpContext;
use axenox\Deployer\Common\Pipelines\NativeOtaHttpHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Publishes a private executable package for explicit host-initiated SelfUpdate.
 * Inherits the bounded native installer's documented UXON settings and limitations.
 * This is ordinary pull, not on-demand release selection or a scheduler.
 *
 * Host root, PHP executable, operating system and connection remain action-owned
 * host inputs; prototype UXON configures only the inherited pipeline options.
 */
class UpdaterPull extends ManualTransfer
{
    /**
     * Delegates the OTA request to the native publication and callback handler.
     *
     * @param ServerRequestInterface $request Incoming OTA request.
     * @param DeploymentHttpContext $context Action-owned publication and reporting context.
     * @return ResponseInterface HTTP response produced by the native OTA handler.
     */
    public function handleDeploymentHttpRequest(ServerRequestInterface $request, DeploymentHttpContext $context): ResponseInterface
    {
        return (new NativeOtaHttpHandler())->handle($request, $context);
    }

    /**
     * Allows a native pull package to bind its installation root explicitly later.
     *
     * @return bool Always true for this deferred-root pull workflow.
     */
    protected function allowsDeferredRoot(): bool
    {
        return true;
    }

    /**
     * Prepares only; host installation is reported separately by the OTA client.
     *
     * @param PipelineTaskInterface $task Release inputs supplied by the owning action.
     * @return \Generator<int, string, mixed, PreparedDeploymentResult> Progress messages and the private OTA package.
     * @throws \InvalidArgumentException If the task is not a release deployment task.
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (! $task instanceof ReleaseDeploymentTask) {
            throw new \InvalidArgumentException('UpdaterPull requires a release deployment task.');
        }
        $package = yield from parent::run($task);
        try {
            yield "Preparing attempt-owned executable native OTA package; no host has been changed.\n";
            return NativeOtaPackage::publish($task, $package);
        } finally {
            $package->discard();
        }
    }
}
