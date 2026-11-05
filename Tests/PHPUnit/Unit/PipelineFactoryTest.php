<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Selectors\PipelinePrototypeSelector;
use axenox\Deployer\Factories\PipelineFactory;
use axenox\Deployer\Interfaces\Pipelines\BuildTaskInterface;
use axenox\Deployer\Interfaces\Pipelines\DeploymentTaskInterface;
use axenox\Deployer\Pipelines\Build\FactoryTestBuild;
use axenox\Deployer\Pipelines\Deploy\FactoryTestDeploy;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\FileNotFoundError;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Fixtures/vendor/axenox/deployer/Pipelines/Build/FactoryTestBuild.php';
require_once dirname(__DIR__) . '/Fixtures/vendor/axenox/deployer/Pipelines/Deploy/FactoryTestDeploy.php';
require_once dirname(__DIR__) . '/Fixtures/vendor/axenox/deployer/Pipelines/Build/FactoryTestAbstract.php';
require_once dirname(__DIR__) . '/Fixtures/vendor/axenox/deployer/Pipelines/Build/FactoryTestWrongFamily.php';
require_once dirname(__DIR__) . '/Fixtures/vendor/example/otherapp/Pipelines/Build/FactoryTestExternal.php';

/**
 * Exercises selector validation, Core app delegation, UXON import, and real fixture runs.
 *
 * @author Brooklyn Fränzschky
 */
class PipelineFactoryTest extends TestCase
{
    /**
     * Creates an app boundary that constructs the selected fixture with supplied arguments.
     *
     * @param string $class
     * @return WorkbenchInterface
     */
    private function workbenchFor(string $class): WorkbenchInterface
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $filemanager = $this->createStub(Filemanager::class);
        $filemanager->method('getPathToVendorFolder')->willReturn(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'vendor');
        $workbench->method('filemanager')->willReturn($filemanager);
        $app = $this->createMock(AppInterface::class);
        $appAlias = implode('.', array_slice(explode('\\', $class), 0, 2));
        $workbench->expects(self::once())->method('getApp')->with(
            self::callback(
                /**
                 * Checks Core's app selector instead of bypassing app ownership.
                 *
                 * @param AppSelectorInterface $selector
                 * @return bool
                 */
                static function (AppSelectorInterface $selector) use ($appAlias): bool {
                    return strcasecmp($selector->toString(), $appAlias) === 0;
                }
            )
        )->willReturn($app);
        $app->expects(self::once())->method('get')->with(
            self::isInstanceOf(PipelinePrototypeSelector::class),
            null,
            [$workbench]
        )->willReturnCallback(
            /**
             * Models construction by the app without a model/database dependency.
             *
             * @param mixed $selector
             * @param mixed $selectorClass
             * @param array $arguments
             * @return object
             */
            static function ($selector, $selectorClass, array $arguments) use ($class): object {
                return new $class(...$arguments);
            }
        );
        return $workbench;
    }

    /**
     * Qualified paths and classes retain Core's cross-app selector behavior.
     *
     * @return void
     */
    public function testSelectorRecognizesPathsAndClassesAcrossApps(): void
    {
        $workbench = $this->createStub(WorkbenchInterface::class);
        foreach ([
            'axenox/Deployer/Pipelines/Build/FactoryTestBuild.php' => 'axenox.Deployer',
            '\\axenox\\Deployer\\Pipelines\\Build\\FactoryTestBuild' => 'axenox.Deployer',
            'example/OtherApp/Pipelines/Deploy/Custom.php' => 'example.OtherApp'
        ] as $value => $app) {
            $selector = new PipelinePrototypeSelector($workbench, $value);
            self::assertSame($value, $selector->toString());
            self::assertSame($app, $selector->getPrototypeAppSelector()->toString());
            self::assertSame('pipeline prototype', $selector->getComponentType());
        }
    }

    /**
     * Path, class and selector-object inputs import UXON and execute the same build.
     *
     * @return void
     */
    public function testBuildPipelineCanBeCreatedConfiguredAndRun(): void
    {
        foreach (['class', 'path', 'selector'] as $input) {
            $workbench = $this->workbenchFor(FactoryTestBuild::class);
            $selection = $input === 'path'
                ? 'axenox/deployer/Pipelines/Build/FactoryTestBuild.php'
                : FactoryTestBuild::class;
            if ($input === 'selector') {
                $selection = new PipelinePrototypeSelector($workbench, $selection);
            }
            $uxon = new UxonObject(['message' => 'configured']);
            $pipeline = PipelineFactory::createPipelineFromUxon($workbench, $selection, $uxon);
            $uxon->setProperty('message', 'changed by caller');
            self::assertSame('configured', $pipeline->exportUxonObject()->getProperty('message'));
            $run = $pipeline->run($this->createStub(BuildTaskInterface::class));
            self::assertSame(['configured'], iterator_to_array($run, false));
            self::assertSame(99, $run->getReturn()->getStatus());
        }
    }

    /**
     * The common deployment contract requires no release artifact.
     *
     * @return void
     */
    public function testDeploymentRunsWithoutAnArtifact(): void
    {
        $workbench = $this->workbenchFor(FactoryTestDeploy::class);
        $pipeline = PipelineFactory::createPipelineFromUxon($workbench, FactoryTestDeploy::class, new UxonObject());
        $run = $pipeline->run($this->createStub(DeploymentTaskInterface::class));
        self::assertSame(['default'], iterator_to_array($run, false));
        self::assertSame(60, $run->getReturn()->getStatus());
    }

    /**
     * The selected app, rather than a hard-coded Deployer app, constructs its pipeline.
     *
     * @return void
     */
    public function testPipelineFromAnotherAppCanBeConfiguredAndRun(): void
    {
        $class = \example\OtherApp\Pipelines\Build\FactoryTestExternal::class;
        $pipeline = PipelineFactory::createPipelineFromUxon(
            $this->workbenchFor($class),
            $class,
            new UxonObject(['message' => 'external app'])
        );
        self::assertInstanceOf($class, $pipeline);
        $run = $pipeline->run($this->createStub(BuildTaskInterface::class));
        self::assertSame(['external app'], iterator_to_array($run, false));
        self::assertSame(99, $run->getReturn()->getStatus());
    }

    /**
     * Invalid selector inputs must fail before construction or configuration.
     *
     * @return void
     */
    public function testInvalidPrototypesAreRejectedBeforeAppConstruction(): void
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $workbench->expects(self::never())->method('getApp');
        foreach ([
            '',
            '../axenox/Deployer/Pipelines/Build/FactoryTestBuild.php',
            'axenox/Deployer/Pipelines/Build/../FactoryTestBuild.php',
            'C:/axenox/Deployer/Pipelines/Build/FactoryTestBuild.php',
            'vendor/axenox/Deployer/Pipelines/Build/FactoryTestBuild.php',
            BuildTaskInterface::class,
            'axenox\\Deployer\\Pipelines\\Build\\Missing',
            'axenox\\Deployer\\Pipelines\\Build\\FactoryTestAbstract',
            'axenox\\Deployer\\Pipelines\\Build\\FactoryTestWrongFamily',
            []
        ] as $selection) {
            try {
                PipelineFactory::createPipelineFromUxon($workbench, $selection, new UxonObject());
                self::fail('Invalid selector must fail.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /**
     * A syntactically valid but absent prototype preserves Core's explicit file error.
     *
     * @return void
     */
    public function testMissingPrototypeFileIsRejectedBeforeAppConstruction(): void
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $filemanager = $this->createStub(Filemanager::class);
        $filemanager->method('getPathToVendorFolder')->willReturn(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'vendor'
        );
        $workbench->method('filemanager')->willReturn($filemanager);
        $workbench->expects(self::never())->method('getApp');
        $this->expectException(FileNotFoundError::class);
        PipelineFactory::createPipelineFromUxon(
            $workbench,
            'axenox/deployer/Pipelines/Build/MissingPrototype.php',
            new UxonObject()
        );
    }

    /**
     * A selector cannot silently route construction through a different workbench.
     *
     * @return void
     */
    public function testForeignWorkbenchSelectorIsRejected(): void
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $workbench->expects(self::never())->method('getApp');
        $selector = new PipelinePrototypeSelector($this->createStub(WorkbenchInterface::class), FactoryTestBuild::class);
        $this->expectException(InvalidArgumentException::class);
        PipelineFactory::createPipelineFromUxon($workbench, $selector, new UxonObject());
    }

    /**
     * Unknown settings fail using Core's existing UXON diagnostics.
     *
     * @return void
     */
    public function testUnknownUxonFailsExplicitly(): void
    {
        $workbench = $this->workbenchFor(FactoryTestBuild::class);
        $this->expectException(UxonMapError::class);
        PipelineFactory::createPipelineFromUxon($workbench, FactoryTestBuild::class, new UxonObject(['unknown' => true]));
    }

    /**
     * A nonmatching app service must never receive the selected prototype's UXON.
     *
     * @return void
     */
    public function testWrongAppResultIsRejectedBeforeImport(): void
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $app = $this->createMock(AppInterface::class);
        $workbench->method('getApp')->willReturn($app);
        $app->method('get')->willReturn(new \stdClass());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('incompatible');
        PipelineFactory::createPipelineFromUxon($workbench, FactoryTestBuild::class, new UxonObject(['message' => 'private']));
    }

    /**
     * Fixture exports can configure a fresh factory instance without modifying the first.
     *
     * @return void
     */
    public function testExportCanConfigureANewPipeline(): void
    {
        $first = PipelineFactory::createPipelineFromUxon(
            $this->workbenchFor(FactoryTestBuild::class),
            FactoryTestBuild::class,
            new UxonObject(['message' => 'round trip'])
        );
        $export = $first->exportUxonObject();
        $second = PipelineFactory::createPipelineFromUxon(
            $this->workbenchFor(FactoryTestBuild::class),
            FactoryTestBuild::class,
            $export
        );
        $export->setProperty('message', 'changed');
        self::assertNotSame($first, $second);
        self::assertSame('round trip', $first->exportUxonObject()->getProperty('message'));
        self::assertSame('round trip', $second->exportUxonObject()->getProperty('message'));
    }

    /**
     * Unsupported task rejection occurs when the generator starts, before fixture work.
     *
     * @return void
     */
    public function testUnsupportedTaskFailsBeforePipelineWork(): void
    {
        $workbench = $this->workbenchFor(FactoryTestBuild::class);
        $pipeline = PipelineFactory::createPipelineFromUxon($workbench, FactoryTestBuild::class, new UxonObject());
        $run = $pipeline->run($this->createStub(DeploymentTaskInterface::class));
        try {
            iterator_to_array($run);
            self::fail('Unsupported task must fail.');
        } catch (\InvalidArgumentException $error) {
            self::assertFalse($pipeline->worked);
        }
    }

    /**
     * Verifies actual Core app construction as well as the mocked app-boundary tests.
     *
     * @return void
     */
    public function testInstalledCoreAppConstructsAndConfiguresThePrototype(): void
    {
        $pipeline = PipelineFactory::createPipelineFromUxon(
            new Workbench(),
            FactoryTestBuild::class,
            new UxonObject(['message' => 'real app'])
        );
        self::assertInstanceOf(FactoryTestBuild::class, $pipeline);
        self::assertSame('real app', $pipeline->exportUxonObject()->getProperty('message'));
    }
}
