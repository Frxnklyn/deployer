<?php
namespace axenox\Deployer\Pipelines\Build;

use axenox\Deployer\Common\Pipelines\BuildResult;
use axenox\Deployer\Interfaces\Pipelines\BuildPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\BuildTaskInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Test-only prototype in an isolated vendor tree, never live prototype discovery.
 *
 * @author Brooklyn Fränzschky
 */
class FactoryTestBuild implements BuildPipelineInterface
{
    use ImportUxonObjectTrait;

    protected string $message = 'default';

    public bool $worked = false;

    /**
     * Accepts Core's pipeline constructor argument.
     *
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
    }

    /**
     * Configures text used to verify UXON mapping.
     *
     * @uxon-property message
     * @uxon-type string
     * @param string $message
     * @return void
     */
    protected function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * Exports an independent configuration for round-trip tests.
     *
     * @return UxonObject
     */
    public function exportUxonObject(): UxonObject
    {
        return new UxonObject(['message' => $this->message]);
    }

    /**
     * Rejects unsupported tasks before any fixture work.
     *
     * @param PipelineTaskInterface $task
     * @return \Generator
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (! $task instanceof BuildTaskInterface) {
            throw new \InvalidArgumentException('Unsupported build task.');
        }
        $this->worked = true;
        yield $this->message;
        return new BuildResult(99);
    }
}
