<?php
namespace axenox\Deployer\Pipelines\Deploy;

use axenox\Deployer\Common\Pipelines\DeploymentResult;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\DeploymentTaskInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Standalone deployment test fixture requiring no release artifact.
 *
 * @author Brooklyn Fränzschky
 */
class FactoryTestDeploy implements DeploymentPipelineInterface
{
    use ImportUxonObjectTrait;

    /**
     * Accepts Core's pipeline constructor argument.
     *
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
    }

    /**
     * This fixture has no configurable properties.
     *
     * @return UxonObject
     */
    public function exportUxonObject(): UxonObject
    {
        return new UxonObject();
    }

    /**
     * Exercises deployment execution independently of build artifacts.
     *
     * @param PipelineTaskInterface $task
     * @return \Generator
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        if (! $task instanceof DeploymentTaskInterface) {
            throw new \InvalidArgumentException('Unsupported deployment task.');
        }
        yield 'default';
        return new DeploymentResult(60);
    }
}
