<?php
namespace axenox\Deployer\Interfaces\Pipelines;

/**
 * Pipeline family for deployment workflows.
 *
 * This base family does not require a release artifact or self-extractor;
 * specialized pipelines may impose those requirements on their task input.
 *
 * @author Brooklyn Fränzschky
 */
interface DeploymentPipelineInterface extends PipelineInterface
{
}
