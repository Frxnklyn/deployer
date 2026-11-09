<?php
namespace axenox\Deployer\Factories;

use axenox\Deployer\Factories\PipelineFactory;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Creates a deployment pipeline and supplies the host-owned runtime settings.
 *
 * Pipeline UXON may configure the selected pipeline, but it must not override
 * host attributes such as its connection, installation root, PHP executable,
 * or operating system. Those values are injected from the host record instead.
 */
final class HostDeploymentPipelineFactory
{
    /**
     * Instantiate a host's selected pipeline and apply its trusted host settings.
     *
     * The UXON configuration contains pipeline-specific options only. Host
     * attributes are read through the callback so both Deploy and HTTP handling
     * use the same boundary and cannot smuggle host settings through UXON.
     *
     * @param WorkbenchInterface $workbench Workbench used to resolve the app-owned prototype.
     * @param string $prototype Selected deployment pipeline prototype.
     * @param UxonObject $configuration Pipeline-specific UXON settings from the host.
     * @param callable(string):mixed $hostValue Reads a host attribute by its model alias.
     * @param string[] $hostAliases Normalized aliases of attributes belonging to the host.
     * @return DeploymentPipelineInterface The configured pipeline instance.
     * @throws \InvalidArgumentException If UXON attempts to set host-owned values,
     *     the prototype is not a deployment pipeline, or required host settings are missing.
     */
    public static function create(
        WorkbenchInterface $workbench,
        string $prototype,
        UxonObject $configuration,
        callable $hostValue,
        array $hostAliases
    ): DeploymentPipelineInterface {
        foreach (array_keys($configuration->toArray()) as $key) {
            $normalized = strtolower(str_replace('_', '', $key));
            if (str_starts_with($normalized, 'host') || in_array($normalized, $hostAliases, true)
                || in_array($normalized, ['connection', 'relativedeploypath'], true)) {
                throw new \InvalidArgumentException('Host attributes cannot be configured through pipeline UXON.');
            }
        }

        // Resolve and validate the app-owned pipeline before injecting host settings.
        $pipeline = PipelineFactory::createPipelineFromUxon($workbench, $prototype, $configuration);
        if (! $pipeline instanceof DeploymentPipelineInterface) {
            throw new \InvalidArgumentException('Selected prototype is not a deployment pipeline.');
        }

        // A pipeline may accept a connection, but only the host record selects it.
        if (is_callable([$pipeline, 'setConnection'])) {
            $connectionUid = $hostValue('data_connection');
            if (! $connectionUid) {
                throw new \InvalidArgumentException('Selected deployment pipeline requires a host data connection.');
            }
            $pipeline->setConnection(DataConnectionFactory::createFromModel($workbench, $connectionUid));
        }
        foreach ([
            'setHostPathToInstallationRoot' => 'path_abs_to_api',
            'setHostPhpExecutable' => 'php_cli',
            'setHostOperatingSystem' => 'operating_system'
        ] as $setter => $attribute) {
            if (is_callable([$pipeline, $setter])) {
                $value = $hostValue($attribute);
                // Keep the pipeline's established PHP CLI fallback when the host value is empty.
                $pipeline->$setter($attribute === 'php_cli' ? ($value ?: 'php') : ($value ?? ''));
            }
        }
        return $pipeline;
    }
}
