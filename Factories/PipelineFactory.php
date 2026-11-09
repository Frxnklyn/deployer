<?php
namespace axenox\Deployer\Factories;

use axenox\Deployer\Common\Selectors\PipelinePrototypeSelector;
use axenox\Deployer\Interfaces\Pipelines\BuildPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineInterface;
use axenox\Deployer\Interfaces\Selectors\PipelinePrototypeSelectorInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\AbstractSelectableComponentFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Resolves app-owned build or deployment pipeline prototypes through Core.
 *
 * The selected prototype receives only its own UXON configuration here.
 * Actions remain responsible for supplying host attributes, connections and
 * run-specific task inputs.
 *
 * @author Brooklyn Fränzschky
 */
abstract class PipelineFactory extends AbstractSelectableComponentFactory
{
    /**
     * Resolves, validates and configures one concrete pipeline prototype.
     *
     * Prototypes receive the workbench constructor argument, following Core factories.
     * The owning action must supply host attributes, connections and task inputs
     * separately; this factory does not classify or filter prototype UXON keys.
     *
     * File selectors use Core app-qualified paths; class selectors must identify
     * an app namespace under its Pipelines/Build or Pipelines/Deploy namespace.
     * The selected class must implement the family interface matching that path.
     *
     * @param WorkbenchInterface $workbench Workbench used to resolve the app-owned prototype.
     * @param PipelinePrototypeSelectorInterface|string $selectorOrString Prototype selector or its string form.
     * @param UxonObject $uxon Pipeline-specific configuration to import by copy.
     * @return PipelineInterface Instantiated pipeline with the supplied UXON imported.
     * @throws InvalidArgumentException If the selector, class, family, instance or workbench is invalid.
     */
    public static function createPipelineFromUxon(
        WorkbenchInterface $workbench,
        $selectorOrString,
        UxonObject $uxon
    ): PipelineInterface {

        if (is_string($selectorOrString)) {
            $selector = new PipelinePrototypeSelector($workbench, $selectorOrString);
        } elseif ($selectorOrString instanceof PipelinePrototypeSelectorInterface) {
            $selector = $selectorOrString;
            if ($selector->getWorkbench() !== $workbench) {
                throw new InvalidArgumentException('Pipeline selector belongs to a different workbench.');
            }
        } else {
            throw new InvalidArgumentException('Expected a pipeline prototype selector or string.');
        }

        $value = $selector->toString();
        if ($selector->isFilepath()) {
            // Core paths are app-qualified, not absolute OS paths or vendor-prefixed paths.
            if (! preg_match('~^[A-Za-z_][A-Za-z0-9_]*/[A-Za-z_][A-Za-z0-9_]*/Pipelines/(Build|Deploy)/(?:[A-Za-z_][A-Za-z0-9_]*/)*[A-Za-z_][A-Za-z0-9_]*\.php$~D', $value, $match)) {
                throw new InvalidArgumentException('Invalid app-qualified pipeline prototype path.');
            }
            $class = PhpFilePathDataType::findClassInVendorFile($workbench, $value);
            $family = $match[1];
        } elseif ($selector->isClassname()) {
            $class = ltrim($value, '\\');
            $parts = explode('\\', $class);
            if (count($parts) < 5 || $parts[2] !== 'Pipelines' || ! in_array($parts[3], ['Build', 'Deploy'], true)
                || ! preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$~D', $class)) {
                throw new InvalidArgumentException('Expected a class in an app build or deployment pipeline namespace.');
            }
            $family = $parts[3];
        } else {
            throw new InvalidArgumentException('Expected a qualified pipeline class or app-qualified PHP path.');
        }

        $expectedInterface = $family === 'Build' ? BuildPipelineInterface::class : DeploymentPipelineInterface::class;
        if (! class_exists($class)) {
            throw new InvalidArgumentException('Pipeline prototype class not found: ' . $class);
        }
        $reflection = new \ReflectionClass($class);
        if (! $reflection->isInstantiable() || ! $reflection->implementsInterface($expectedInterface)) {
            throw new InvalidArgumentException('Pipeline prototype must be concrete and implement ' . $expectedInterface . '.');
        }

        $pipeline = parent::createFromSelector($selector, [$workbench]);
        if (! $pipeline instanceof $expectedInterface || ! $pipeline instanceof $class) {
            throw new InvalidArgumentException('App returned a pipeline incompatible with the selected prototype.');
        }
        $pipeline->importUxonObject($uxon->copy());
        return $pipeline;
    }
}
