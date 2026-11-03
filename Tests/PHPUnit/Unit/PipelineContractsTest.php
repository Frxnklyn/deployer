<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\BuildResult;
use axenox\Deployer\Common\Pipelines\DeploymentResult;
use axenox\Deployer\Interfaces\Pipelines\BuildPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\BuildTaskInterface;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\DeploymentTaskInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineResultInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use PHPUnit\Framework\TestCase;

/**
 * Guards the approved contract subset without assuming pending lifecycle APIs.
 *
 * @author Brooklyn Fränzschky
 */
class PipelineContractsTest extends TestCase
{
    /**
     * Family interfaces must inherit the same task parameter and generator return.
     *
     * @return void
     */
    public function testPipelineFamiliesInheritTheUxonAndExecutionContracts(): void
    {
        foreach ([PipelineInterface::class, BuildPipelineInterface::class, DeploymentPipelineInterface::class] as $interface) {
            $reflection = new \ReflectionClass($interface);
            self::assertTrue($reflection->isSubclassOf(iCanBeConvertedToUxon::class));
            $run = $reflection->getMethod('run');
            self::assertSame(PipelineInterface::class, $run->getDeclaringClass()->getName());
            self::assertCount(1, $run->getParameters());
            self::assertSame(PipelineTaskInterface::class, (string) $run->getParameters()[0]->getType());
            self::assertSame(\Generator::class, (string) $run->getReturnType());
            self::assertTrue($reflection->hasMethod('exportUxonObject'));
            self::assertTrue($reflection->hasMethod('importUxonObject'));
            self::assertTrue($reflection->hasMethod('getUxonSchemaClass'));
        }
    }

    /**
     * Common deployment contracts must not impose release-artifact requirements.
     *
     * @return void
     */
    public function testTaskFamiliesKeepDeploymentArtifactIndependent(): void
    {
        self::assertTrue(is_subclass_of(BuildTaskInterface::class, PipelineTaskInterface::class));
        self::assertTrue(is_subclass_of(DeploymentTaskInterface::class, PipelineTaskInterface::class));
        self::assertCount(0, (new \ReflectionClass(DeploymentTaskInterface::class))->getMethods());
        $buildTask = new \ReflectionClass(BuildTaskInterface::class);
        self::assertTrue($buildTask->hasMethod('getComment'));
        self::assertTrue($buildTask->hasMethod('getNotes'));
    }

    /**
     * Outcomes preserve explicit states, including publication without installation.
     *
     * @return void
     */
    public function testResultsPreserveTheReachedStatusWithoutRequiringArtifacts(): void
    {
        foreach ([BuildResult::class, DeploymentResult::class] as $resultClass) {
            foreach ([50, 60, 62, 90, 99] as $status) {
                $result = new $resultClass($status);
                self::assertInstanceOf(PipelineResultInterface::class, $result);
                self::assertSame($status, $result->getStatus());
            }
        }
    }

    /**
     * A consumer retrieves the result only after fully consuming text output.
     *
     * This fixture demonstrates generator consumption, not a production pipeline.
     *
     * @return void
     */
    public function testConsumerStreamsTextBeforeReadingTheStructuredResult(): void
    {
        $task = $this->createStub(BuildTaskInterface::class);
        $expectedResult = new BuildResult(99);
        $pipeline = $this->createMock(BuildPipelineInterface::class);
        $pipeline->expects(self::once())->method('run')->with($task)->willReturnCallback(
            /**
             * Emits fixture output and returns its structured outcome.
             *
             * @param PipelineTaskInterface $input
             * @return \Generator<int, string, mixed, PipelineResultInterface>
             */
            function (PipelineTaskInterface $input) use ($task, $expectedResult): \Generator {
                self::assertSame($task, $input);
                yield 'Preparing';
                yield 'Finished';
                return $expectedResult;
            }
        );

        $generator = $pipeline->run($task);
        self::assertInstanceOf(\Generator::class, $generator);
        $messages = [];
        foreach ($generator as $message) {
            self::assertIsString($message);
            $messages[] = $message;
        }
        self::assertSame(['Preparing', 'Finished'], $messages);
        self::assertSame($expectedResult, $generator->getReturn());
    }
}
