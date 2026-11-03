<?php
namespace axenox\Deployer\Interfaces\Pipelines;

/**
 * Build-specific run inputs; further accessors require the remaining contract decisions.
 *
 * @author Brooklyn Fränzschky
 */
interface BuildTaskInterface extends PipelineTaskInterface
{
    /**
     * Returns the comment supplied for this build.
     *
     * @return string|null
     */
    public function getComment();

    /**
     * Returns the notes supplied for this build.
     *
     * @return string|null
     */
    public function getNotes();
}
