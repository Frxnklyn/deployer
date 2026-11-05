<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\DeploymentTaskInterface;

/**
 * Action-owned run identity; generic deployment does not require a release.
 *
 * @author Brooklyn Fränzschky
 */
class DeploymentTask implements DeploymentTaskInterface
{
    /**
     * @param string $deploymentId
     * @param string $hostId
     */
    public function __construct(public readonly string $deploymentId, public readonly string $hostId)
    {
    }
}
