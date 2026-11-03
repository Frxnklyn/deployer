<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\PipelineResultInterface;

/**
 * Minimal build outcome; artifact metadata awaits its agreed contract.
 *
 * @author Brooklyn Fränzschky
 */
class BuildResult implements PipelineResultInterface
{
    private int $status;

    /**
     * Captures the reached state without inventing a new state machine.
     *
     * @param int $status
     */
    public function __construct(int $status)
    {
        $this->status = $status;
    }

    /**
     * Returns the reached build state for the owning action.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }
}
