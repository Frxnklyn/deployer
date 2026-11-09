<?php
namespace axenox\Deployer\Interfaces\Pipelines;

/**
 * Structured outcome returned by a pipeline for persistence by its owning action.
 *
 * @author Brooklyn Fränzschky
 */
interface PipelineResultInterface
{
    /**
     * Returns the pipeline's reached status state.
     *
     * A preparation-only result may use a nonterminal status; this value does
     * not by itself assert that a host installation completed.
     *
     * @return int
     */
    public function getStatus(): int;
}
