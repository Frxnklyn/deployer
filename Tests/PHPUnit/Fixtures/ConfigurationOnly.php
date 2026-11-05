<?php
namespace example\Native\Pipelines\Deploy;

use axenox\Deployer\Common\Pipelines\DeploymentResult;
use axenox\Deployer\Common\Pipelines\DeploymentTask;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Non-release fixture proving that deployment does not universally require artifacts.
 *
 * @author Brooklyn Fränzschky
 */
final class ConfigurationOnly implements DeploymentPipelineInterface
{
    use ImportUxonObjectTrait;

    private string $mode = '';

    /**
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
    }

    /**
     * @param string $mode
     * @return void
     */
    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    /**
     * @return UxonObject
     */
    public function exportUxonObject(): UxonObject
    {
        return new UxonObject(['mode' => $this->mode]);
    }

    /**
     * @param PipelineTaskInterface $task
     * @return \Generator
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (get_class($task) !== DeploymentTask::class) {
            throw new \InvalidArgumentException('Configuration-only deployment must not require a release.');
        }
        yield $this->mode === 'nontext' ? ['not text'] : "Configuration-only fixture completed.\n";
        return $this->mode === 'noresult' ? null : new DeploymentResult(99);
    }
}
