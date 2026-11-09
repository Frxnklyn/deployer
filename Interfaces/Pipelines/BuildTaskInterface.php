<?php
namespace axenox\Deployer\Interfaces\Pipelines;

/**
 * Build-specific run inputs exposed to build pipeline implementations.
 *
 * @author Brooklyn Fränzschky
 */
interface BuildTaskInterface extends PipelineTaskInterface
{
    /**
     * Returns the optional comment attached to the build request.
     *
     * @return string|null
     */
    public function getComment();

    /**
     * Returns the optional notes attached to the build request.
     *
     * @return string|null
     */
    public function getNotes();
}
