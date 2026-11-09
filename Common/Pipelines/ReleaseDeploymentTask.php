<?php
namespace axenox\Deployer\Common\Pipelines;

/**
 * Adds action-resolved build and project identity to a deployment attempt for release-based
 * pipelines. These values come from the selected build/host records, not pipeline UXON.
 *
 * @author Brooklyn Fränzschky
 */
final class ReleaseDeploymentTask extends DeploymentTask
{
    /**
     * Adds the selected release inputs to the deployment identity.
     *
     * @param string $deploymentId Identity of the deployment attempt resolved by the action.
     * @param string $hostId Identity of the target host resolved by the action.
     * @param string $buildId Identity of the selected build resolved by the action.
     * @param string $buildName Name of the selected build release.
     * @param string $projectPath Action-resolved project path containing the selected build.
     * @param string $hostAlias Historical filename alias supplied by the action; empty for internal-only preparation.
     */
    public function __construct(
        string $deploymentId,
        string $hostId,
        public readonly string $buildId,
        public readonly string $buildName,
        public readonly string $projectPath,
        public readonly string $hostAlias = ''
    ) {
        parent::__construct($deploymentId, $hostId);
    }
}
