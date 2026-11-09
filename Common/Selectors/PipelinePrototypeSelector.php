<?php
namespace axenox\Deployer\Common\Selectors;

use axenox\Deployer\Interfaces\Selectors\PipelinePrototypeSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\CommonLogic\Selectors\Traits\PrototypeSelectorTrait;

/**
 * Selects an app-owned pipeline prototype using Core selector conventions.
 *
 * Accepts the qualified class-name and app-qualified file-path forms handled
 * by Core's prototype selector trait.
 *
 * @author Brooklyn Fränzschky
 */
class PipelinePrototypeSelector extends AbstractSelector implements PipelinePrototypeSelectorInterface
{
    use PrototypeSelectorTrait;

    /**
     * Returns the human-readable component type used in selector diagnostics.
     *
     * @return string
     */
    public function getComponentType(): string
    {
        return 'pipeline prototype';
    }
}
