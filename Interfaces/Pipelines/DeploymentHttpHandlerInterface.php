<?php
namespace axenox\Deployer\Interfaces\Pipelines;

use axenox\Deployer\Common\Pipelines\DeploymentHttpContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Optional HTTP transport for deployment publications and their lifecycle reports.
 *
 * Implementations handle an HTTP request using action-supplied persistence and
 * path context. Where explicitly supported, a handler may use the context's
 * redeploy callback; this interface itself defines no deployment-run API.
 */
interface DeploymentHttpHandlerInterface
{
    /**
     * Handles a deployment-related request in the supplied action-owned context.
     *
     * @param ServerRequestInterface $request Incoming PSR-7 request.
     * @param DeploymentHttpContext $context Host, publication and reporting callbacks.
     * @return ResponseInterface HTTP response for the request.
     */
    public function handleDeploymentHttpRequest(ServerRequestInterface $request, DeploymentHttpContext $context): ResponseInterface;
}
