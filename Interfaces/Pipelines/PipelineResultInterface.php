<?php
namespace axenox\Deployer\Interfaces\Pipelines;

/**
 * Structured reached state for persistence by the owning action.
 *
 * @author Brooklyn Fränzschky
 */
interface PipelineResultInterface
{
    /**
     * Returns the reached state, not necessarily completed host installation.
     *
     * @return int
     */
    public function getStatus(): int;
}
