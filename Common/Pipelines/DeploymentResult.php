<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\PipelineResultInterface;

/**
 * Minimal deployment outcome, independent of release artifacts and transport.
 *
 * @author Brooklyn Fränzschky
 */
class DeploymentResult implements PipelineResultInterface
{
    private int $status;

    /**
     * Captures a reached state without equating preparation with installation.
     *
     * @param int $status
     */
    public function __construct(int $status)
    {
        $this->status = $status;
    }

    /**
     * Returns the reached deployment state for the owning action.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }
}
