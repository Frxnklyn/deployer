<?php
namespace axenox\Deployer\Interfaces\Pipelines;

use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * Executable workflow configured by prototype UXON and invoked with run inputs.
 *
 * The owning action supplies run-specific task data and remains responsible for
 * run records and persistence.
 *
 * @author Brooklyn Fränzschky
 */
interface PipelineInterface extends iCanBeConvertedToUxon
{
    /**
     * Streams text and returns a structured outcome after the generator is exhausted.
     *
     * Implementations must reject unsupported tasks before side effects. Family
     * interfaces retain this parameter type rather than narrowing it.
     *
     * @param PipelineTaskInterface $task
     * @return \Generator<int, string, mixed, PipelineResultInterface>
     */
    public function run(PipelineTaskInterface $task): \Generator;
}
