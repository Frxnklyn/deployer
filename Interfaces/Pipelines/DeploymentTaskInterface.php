<?php
namespace axenox\Deployer\Interfaces\Pipelines;

/**
 * Common deployment run inputs.
 *
 * A deployment pipeline may require a release-specific task implementation,
 * but this base contract does not require a build artifact.
 *
 * @author Brooklyn Fränzschky
 */
interface DeploymentTaskInterface extends PipelineTaskInterface
{
}
