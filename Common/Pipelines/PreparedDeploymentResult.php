<?php
namespace axenox\Deployer\Common\Pipelines;

/**
 * A prepared deployment artifact awaiting transfer or operator action. Its retained file
 * and hash are not evidence that the target host installed or activated the release.
 *
 * @author Brooklyn Fränzschky
 */
final class PreparedDeploymentResult extends DeploymentResult
{
    /**
     * Records the private artifact and marks the result as prepared, not installed.
     *
     * @param string $artifactPath Path to the prepared artifact retained for later use.
     * @param string $sha256 SHA-256 digest used to guard upload and cleanup.
     * @param string $instructions Operator-facing transfer or installation instructions.
     * @param string[] $companionArtifacts Additional unchanged copies sharing the same digest.
     */
    public function __construct(
        public readonly string $artifactPath,
        public readonly string $sha256,
        public readonly string $instructions,
        private readonly array $companionArtifacts = []
    ) {
        parent::__construct(60);
    }

    /**
     * Removes only unchanged owned artifacts, typically when action persistence fails.
     *
     * @return void
     * @throws \RuntimeException If an unchanged artifact cannot be removed.
     */
    public function discard(): void
    {
        foreach (array_merge([$this->artifactPath], $this->companionArtifacts) as $path) {
            if (is_file($path) && ! NativeBuildFiles::isLink($path)
                && hash_equals($this->sha256, hash_file('sha256', $path)) && ! unlink($path)) {
                throw new \RuntimeException('Cannot remove the unpublished deployment package.');
            }
        }
    }
}
