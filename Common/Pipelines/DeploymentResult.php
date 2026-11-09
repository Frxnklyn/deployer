<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\PipelineResultInterface;

/**
 * Carries the reached deployment status to the action that owns persistence, without
 * coupling a generic deployment result to release artifacts or a particular transport.
 *
 * @author Brooklyn Fränzschky
 */
class DeploymentResult implements PipelineResultInterface
{
    private int $status;

    /**
     * Stores the status reached by the deployment pipeline; callers decide how it is persisted.
     *
     * @param int $status Reached pipeline status, which may represent preparation rather than installation.
     */
    public function __construct(int $status)
    {
        $this->status = $status;
    }

    /**
     * Returns the status supplied when the result was created.
     *
     * @return int Status reached by the deployment pipeline.
     */
    public function getStatus(): int
    {
        return $this->status;
    }
}
