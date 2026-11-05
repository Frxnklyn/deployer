<?php
namespace axenox\Deployer\Common\Selectors;

use axenox\Deployer\Interfaces\Selectors\PipelinePrototypeSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\PrototypeSelectorTrait;

/**
 * Uses Core prototype selection conventions for cross-app pipelines.
 *
 * @author Brooklyn Fränzschky
 */
class PipelinePrototypeSelector extends AbstractSelector implements PipelinePrototypeSelectorInterface
{
    use PrototypeSelectorTrait;

    /**
     * Returns the component label used in selector diagnostics.
     *
     * @return string
     */
    public function getComponentType(): string
    {
        return 'pipeline prototype';
    }
}
