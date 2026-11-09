<?php
namespace axenox\Deployer\Pipelines\Deploy;

use axenox\Deployer\Common\Pipelines\PreparedDeploymentResult;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;

/**
 * Ordinary native pull with explicit operator-owned scheduling instructions.
 * Never registers or starts a scheduler on the host or build server.
 */
final class UpdaterPolling extends UpdaterPull
{
    /**
     * Preserves all parent publication behavior and adds host-side setup guidance.
     *
     * @param PipelineTaskInterface $task
     * @return \Generator<int, string, mixed, PreparedDeploymentResult>
     */
    public function run(PipelineTaskInterface $task): \Generator
    {
        $result = yield from parent::run($task);
        return new PreparedDeploymentResult($result->artifactPath, $result->sha256,
            $result->instructions . "\nPolling is not installed or configured by this pipeline.\n"
            . "On the host, configure Administration > BG Processing > Scheduler to run axenox.PackageManager:SelfUpdate,\n"
            . "or configure an OS scheduler/CRON job for the same action using the host's PHP CLI and absolute action path.\n"
            . "Set a bounded polling interval and prevent overlapping SelfUpdate jobs; no polling loop runs here.\n"
            . "Provision the authenticated HTTPS source and updated native-capable client before enabling the job.\n");
    }
}
