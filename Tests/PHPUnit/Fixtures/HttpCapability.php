<?php
namespace example\Native\Pipelines\Deploy;

use axenox\Deployer\Common\Pipelines\DeploymentHttpContext;
use axenox\Deployer\Common\Pipelines\DeploymentResult;
use axenox\Deployer\Interfaces\Pipelines\DeploymentHttpHandlerInterface;
use axenox\Deployer\Interfaces\Pipelines\DeploymentPipelineInterface;
use axenox\Deployer\Interfaces\Pipelines\PipelineTaskInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HttpCapability implements DeploymentPipelineInterface, DeploymentHttpHandlerInterface
{
    use ImportUxonObjectTrait;

    public static array $received = [];
    private string $message = '';
    private string $root = '';
    private string $php = '';
    private string $os = '';

    public function __construct(WorkbenchInterface $workbench)
    {
    }

    public function setMessage(string $value): void
    {
        $this->message = $value;
    }

    public function setHostPathToInstallationRoot(string $value): void
    {
        $this->root = $value;
    }

    public function setHostPhpExecutable(string $value): void
    {
        $this->php = $value;
    }

    public function setHostOperatingSystem(string $value): void
    {
        $this->os = $value;
    }

    public function exportUxonObject(): UxonObject
    {
        return new UxonObject(['message' => $this->message]);
    }

    public function run(PipelineTaskInterface $task): \Generator
    {
        yield 'fixture';
        return new DeploymentResult(60);
    }

    public function handleDeploymentHttpRequest(ServerRequestInterface $request, DeploymentHttpContext $context): ResponseInterface
    {
        self::$received = [$request->getMethod(), $this->message, $this->root, $this->php, $this->os];
        return new Response($request->getMethod() === 'GET' ? 202 : 204, $context->headers);
    }
}
