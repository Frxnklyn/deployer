<?php
namespace axenox\Deployer\Interfaces\Pipelines;

/**
 * Pipeline family for producing a build from build-specific run inputs.
 *
 * The common {@see PipelineInterface::run()} signature is inherited unchanged.
 *
 * @author Brooklyn Fränzschky
 */
interface BuildPipelineInterface extends PipelineInterface
{
}
