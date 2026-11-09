<?php
namespace axenox\Deployer\Common\Pipelines;

/**
 * Gives HTTP handlers only a deployment snapshot, response settings and action-provided
 * lifecycle callbacks. Model objects and data sheets stay within the owning action; this
 * context does not grant handlers authority to change deployment identity or configuration.
 */
final class DeploymentHttpContext
{
    public readonly string $projectPath;
    private \Closure $reader;
    private \Closure $writer;
    private \Closure $redeployer;

    /**
     * Binds action-owned read, report and legacy-redeploy callbacks to immutable request context.
     *
     * @param string $installationBase Action-resolved installation base directory.
     * @param string $projectFolderRelative Action-resolved project folder below that base.
     * @param array $headers Response headers prepared by the action.
     * @param int $downloadMaxExecutionTime Maximum execution time selected for legacy downloads.
     * @param callable $reader Reads a deployment snapshot by optional attempt identity.
     * @param callable $writer Persists only allowed lifecycle values through the action.
     * @param callable $redeployer Invokes the action's compatibility redeployment path.
     */
    public function __construct(
        public readonly string $installationBase,
        public readonly string $projectFolderRelative,
        public readonly array $headers,
        public readonly int $downloadMaxExecutionTime,
        callable $reader,
        callable $writer,
        callable $redeployer
    ) {
        $this->projectPath = $installationBase . DIRECTORY_SEPARATOR . $projectFolderRelative;
        $this->reader = \Closure::fromCallable($reader);
        $this->writer = \Closure::fromCallable($writer);
        $this->redeployer = \Closure::fromCallable($redeployer);
    }

    /**
     * Reads a host-scoped deployment snapshot, optionally selecting one attempt.
     *
     * @param string|null $attempt Deployment identity, or null for the action's current selection.
     * @return array|null Snapshot returned by the owning action, or null when not found.
     */
    public function read(?string $attempt = null): ?array
    {
        return ($this->reader)($attempt);
    }

    /**
     * Requests an action-owned lifecycle update after validating the handler's writable fields.
     *
     * @param array $values Lifecycle values to report; identity/configuration keys are rejected.
     * @param array|null $expected Optional expected values for the action's conditional update.
     * @return bool Whether the action accepted the report (false can indicate a concurrent change).
     * @throws \InvalidArgumentException If values include fields outside the lifecycle allowlist.
     */
    public function report(array $values, ?array $expected = null): bool
    {
        if (array_diff(array_keys($values), ['status', 'log', 'completed_on']) !== []) {
            throw new \InvalidArgumentException('HTTP lifecycle reports cannot change deployment identity or configuration.');
        }
        return ($this->writer)($values, $expected);
    }

    /**
     * Invokes the legacy redeployment callback owned by the action.
     *
     * @return void
     */
    public function redeployLegacy(): void
    {
        ($this->redeployer)();
    }
}
