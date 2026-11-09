<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\DeploymentTaskInterface;

/**
 * Holds the deployment and host identities resolved by the owning action. Generic
 * deployment pipelines receive no build/release inputs unless a specialized task is used.
 *
 * @author Brooklyn Fränzschky
 */
class DeploymentTask implements DeploymentTaskInterface
{
    /**
     * Stores the action-resolved identity for this deployment attempt.
     *
     * @param string $deploymentId Identity of the deployment attempt resolved by the action.
     * @param string $hostId Identity of the target host resolved by the action.
     */
    public function __construct(public readonly string $deploymentId, public readonly string $hostId)
    {
    }
}
