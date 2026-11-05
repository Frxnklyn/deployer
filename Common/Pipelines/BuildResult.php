<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\PipelineResultInterface;

/**
 * Build outcome and optional evidence for persistence by the owning action.
 *
 * @author Brooklyn Fränzschky
 */
class BuildResult implements PipelineResultInterface
{
    private int $status;

    /**
     * Captures the reached state without inventing a new state machine.
     *
     * @param int $status
     * @param string|null $archivePath
     * @param string|null $composerLock
     * @param string|null $packageBom
     * @param string|null $composerJson
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
     * Returns the reached build state for the owning action.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return string|null
     */
    public function getArchivePath(): ?string
    {
        return $this->archivePath;
    }

    /**
     * @return string|null
     */
    public function getComposerLock(): ?string
    {
        return $this->composerLock;
    }

    /**
     * @return string|null Minimal database BOM.
     */
    public function getPackageBom(): ?string
    {
        return $this->packageBom;
    }

    /**
     * @return string|null Effective input before installer-script preparation.
     */
    public function getComposerJson(): ?string
    {
        return $this->composerJson;
    }
}
