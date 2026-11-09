<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\PipelineResultInterface;

/**
 * Carries a pipeline's reached build status and optional build evidence back to the
 * action that owns persistence. It does not persist records or decide status transitions.
 *
 * @author Brooklyn Fränzschky
 */
class BuildResult implements PipelineResultInterface
{
    private int $status;

    /**
     * Stores the status reached by the pipeline and any evidence it produced.
     *
     * @param int $status Pipeline status for the owning action to persist.
     * @param string|null $archivePath Produced archive path, when available.
     * @param string|null $composerLock Captured Composer lock JSON, when available.
     * @param string|null $packageBom Compact package inventory JSON, when available.
     * @param string|null $composerJson Effective Composer input JSON, when available.
     */
    public function __construct(
        int $status,
        private ?string $archivePath = null,
        private ?string $composerLock = null,
        private ?string $packageBom = null,
        private ?string $composerJson = null
    )
    {
        $this->status = $status;
    }

    /**
     * Returns the status supplied when the result was created.
     *
     * @return int Status reached by the build pipeline.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Returns the path of the archive produced by the pipeline, when available.
     * @return string|null Path to the generated archive, or null if none was produced.
     */
    public function getArchivePath(): ?string
    {
        return $this->archivePath;
    }

    /**
     * Returns the captured Composer lock document, when available.
     * @return string|null Captured Composer lock JSON, or null if unavailable.
     */
    public function getComposerLock(): ?string
    {
        return $this->composerLock;
    }

    /**
     * Returns the compact package inventory captured for persistence.
     * @return string|null Minimal database BOM.
     */
    public function getPackageBom(): ?string
    {
        return $this->packageBom;
    }

    /**
     * Returns the effective Composer input used before installer-script preparation.
     * @return string|null Effective input before installer-script preparation.
     */
    public function getComposerJson(): ?string
    {
        return $this->composerJson;
    }
}
