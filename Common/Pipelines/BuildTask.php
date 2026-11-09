<?php
namespace axenox\Deployer\Common\Pipelines;

use axenox\Deployer\Interfaces\Pipelines\BuildTaskInterface;

/**
 * Carries the action-resolved inputs for one native build, separating runtime paths,
 * secrets, project configuration and previous-build evidence from pipeline UXON. These
 * values are supplied by the owning action; the task is not a configuration importer.
 *
 * @author Brooklyn Fränzschky
 */
final class BuildTask implements BuildTaskInterface
{
    /**
     * Captures inputs resolved by the owning build action; pipeline UXON is not used to
     * override action-owned paths, executable locations or previous-build identity.
     *
     * @param string $projectPath Action-resolved project workspace path.
     * @param string $archiveDirectory Action-resolved build archive directory.
     * @param string $buildName Action-resolved build name.
     * @param string $phpExecutable CLI PHP executable selected for the run.
     * @param string $composerPhar Composer executable selected for the run.
     * @param string|null $composerJson Complete per-run Composer manifest replacement, if supplied.
     * @param string $composerAuth Authentication defaults supplied by the action/server.
     * @param string|null $licensesJson Project license configuration, not host configuration.
     * @param string|null $comment Build comment supplied by the action.
     * @param string|null $notes Build notes supplied by the action.
     * @param string|null $previousComposerLock Previous successful build lock for this variant only.
     */
    public function __construct(
        public string $projectPath,
        public string $archiveDirectory,
        public string $buildName,
        public string $phpExecutable,
        public string $composerPhar,
        public ?string $composerJson = null,
        public string $composerAuth = '{}',
        public ?string $licensesJson = null,
        private ?string $comment = null,
        private ?string $notes = null,
        public ?string $previousComposerLock = null
    ) {
    }

    /**
     * Returns the action-supplied build comment.
     * @return string|null Build comment, or null when none was supplied.
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * Returns the action-supplied build notes.
     * @return string|null Build notes, or null when none were supplied.
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }
}
