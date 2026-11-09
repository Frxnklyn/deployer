<?php
namespace axenox\Deployer\Facades;

use exface\Core\CommonLogic\Tasks\CliTask;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\ActionFactory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use axenox\Deployer\Actions\Deploy;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\Facades\HttpBadRequestError;
use exface\Core\Exceptions\DataSheets\DataNotFoundError;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use axenox\Deployer\Common\Deployment\DeploymentRecordWriter;
use axenox\Deployer\Factories\HostDeploymentPipelineFactory;
use axenox\Deployer\Common\Pipelines\DeploymentHttpContext;
use axenox\Deployer\Common\Pipelines\LegacyDeploymentHttpHandler;
use axenox\Deployer\Interfaces\Pipelines\DeploymentHttpHandlerInterface;
use exface\Core\CommonLogic\UxonObject;

/**
 * Routes deployment HTTP requests to the selected host pipeline or legacy handler.
 *
 * The facade resolves the host and pipeline UXON, injects host-owned runtime
 * settings through the shared pipeline factory, then delegates request handling. It also
 * supplies publication lookup and conditional lifecycle persistence callbacks;
 * it does not implement the pipeline's transport protocol.
 *
 * Supported routes:
 *
 * - `GET api/deployer/ota/<project_alias>/<host_uid>` - handle a deployment publication request.
 * - `GET api/deployer/ota/<project_alias>/<host_uid>?redeploy=true` - preserve explicit legacy redeployment behavior.
 * - `POST api/deployer/ota/<project_alias>/<host_uid>` - report deployment lifecycle/log data.
 *
 * @author Andrej Kabachnik
 */
class DeployerFacade extends AbstractHttpFacade
{
    /**
     * Returns the route prefix used to dispatch deployment HTTP requests.
     *
     * @return string Default facade route.
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/deployer';
    }

    /**
     * Matches the OTA route and dispatches supported methods to the host handler.
    *
    * @param ServerRequestInterface $request Incoming HTTP request.
    * @return ResponseInterface Handler response or a bad-request response for an unmatched route.
    */
    protected function createResponse(ServerRequestInterface $request): ResponseInterface
    {
        $path = ltrim(StringDataType::substringAfter($request->getUri()->getPath(), $this->getUrlRouteDefault()), '/');
        $parts = explode('/', $path, 3);
        if (count($parts) === 3 && mb_strtolower($parts[0]) === 'ota'
            && in_array($request->getMethod(), ['GET', 'POST'], true)) {
            return $this->createResponseForDeployment(urldecode($parts[1]), urldecode($parts[2]), $request);
        }
        $error = new HttpBadRequestError($request, 'Cannot match route ' . ($parts[0] ?? ''));
        $this->getWorkbench()->getLogger()->logException($error);
        return $this->createResponseFromError($error, $request);
    }

    /**
     * Compatibility route hook for log requests; delegates to common deployment handling.
     *
     * @param string $projectAlias Project alias from the route.
     * @param string $hostName Host UID from the route.
     * @param ServerRequestInterface $request Incoming HTTP request.
     * @return ResponseInterface Response returned by the selected deployment handler.
     */
    protected function createResponseForLog(string $projectAlias, string $hostName, ServerRequestInterface $request): ResponseInterface
    {
        return $this->createResponseForDeployment($projectAlias, $hostName, $request);
    }

    /**
     * Compatibility route hook for OTA requests; delegates to common deployment handling.
     *
     * @param string $projectAlias Project alias from the route.
     * @param string $hostName Host UID from the route.
     * @param ServerRequestInterface $request Incoming HTTP request.
     * @return ResponseInterface Response returned by the selected deployment handler.
     */
    protected function createResponseForOTA(string $projectAlias, string $hostName, ServerRequestInterface $request): ResponseInterface
    {
        return $this->createResponseForDeployment($projectAlias, $hostName, $request);
    }

    /**
     * Resolves the host pipeline and delegates the request to its HTTP handler.
     *
     * For hosts without a selected prototype, the legacy handler remains the
     * compatibility path. A selected pipeline without HTTP support returns 409.
     *
     * @param string $projectAlias Project alias from the route.
     * @param string $hostName Host UID from the route.
     * @param ServerRequestInterface $request Incoming HTTP request.
     * @return ResponseInterface Response produced by the selected handler.
     * @throws DataNotFoundError If the host does not belong to the requested project.
     */
    protected function createResponseForDeployment(string $projectAlias, string $hostName, ServerRequestInterface $request): ResponseInterface
    {
        $host = $this->createHostSheet($projectAlias, $hostName);
        $host->dataRead();
        if ($host->isEmpty()) {
            throw new DataNotFoundError($host, 'Host "' . $hostName . '" not found in project "' . $projectAlias . '"');
        }
        $prototype = trim((string) $host->getCellValue('prototype_file', 0));
        $context = $this->createDeploymentHttpContext($projectAlias, $hostName);
        if ($prototype === '') {
            return (new LegacyDeploymentHttpHandler())->handle($request, $context);
        }
        $pipeline = HostDeploymentPipelineFactory::create(
            $this->getWorkbench(), $prototype,
            UxonObject::fromJson($host->getCellValue('deploy_config', 0) ?: '{}'),
            fn (string $attribute) => $host->getCellValue($attribute, 0),
            $this->getHostAttributeAliases()
        );
        if (! $pipeline instanceof DeploymentHttpHandlerInterface) {
            return new Response(409, $context->headers, 'Selected deployment pipeline does not support deployment HTTP requests.');
        }
        return $pipeline->handleDeploymentHttpRequest($request, $context);
    }

    /**
     * Builds a host lookup sheet constrained by project alias and host UID.
     *
     * @param string $projectAlias Project alias from the route.
     * @param string $hostName Host UID from the route.
     * @return DataSheetInterface Host sheet ready for reading.
     */
    protected function createHostSheet(string $projectAlias, string $hostName): DataSheetInterface
    {
        $host = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.host');
        $host->getColumns()->addFromSystemAttributes();
        $host->getColumns()->addMultiple([
            'prototype_file', 'deploy_config', 'path_abs_to_api', 'php_cli', 'operating_system', 'data_connection'
        ]);
        $host->getFilters()->addConditionFromString('project__alias', $projectAlias, ComparatorDataType::EQUALS);
        $host->getFilters()->addConditionFromString('uid', $hostName, ComparatorDataType::EQUALS);
        $host->setRowsLimit(1);
        return $host;
    }

    /**
     * Returns normalized aliases of every attribute on the host model.
     *
     * @return string[] Lowercase aliases with underscores removed.
     */
    protected function getHostAttributeAliases(): array
    {
        $aliases = [];
        foreach ($this->getWorkbench()->model()->getObject('axenox.Deployer.host')->getAttributes() as $attribute) {
            $aliases[] = strtolower(str_replace('_', '', $attribute->getAlias()));
        }
        return $aliases;
    }

    /**
     * Creates callbacks and shared paths used by a pipeline HTTP handler.
     *
     * The read callback returns the latest publication or a requested attempt
     * snapshot. The report callback applies an optional expected-state filter
     * before persistence; the redeploy callback runs the historical action only
     * when requested by the handler.
     *
     * @param string $projectAlias Project alias for publication lookup.
     * @param string $hostName Host UID for publication lookup.
     * @return DeploymentHttpContext Context supplied to the selected handler.
     */
    protected function createDeploymentHttpContext(string $projectAlias, string $hostName): DeploymentHttpContext
    {
        $selected = null;
        return new DeploymentHttpContext(
            $this->getWorkbench()->getInstallationPath(),
            rtrim(FilePathDataType::normalize($this->getApp()->getConfig()->getOption('PROJECTS_FOLDER_RELATIVE_TO_BASE')), '\\/')
                . DIRECTORY_SEPARATOR . $projectAlias,
            $this->buildHeadersCommon(),
            (int) $this->getApp()->getConfig()->getOption('OTA_DOWNLOAD_MAX_EXECUTION_TIME'),
            /**
             * Reads the latest publication or one named attempt for later lifecycle reports.
             *
             * @param string|null $attempt Attempt UID, or null to select the latest publication.
             * @return array<string, mixed>|null Selected deployment snapshot, or null if not found.
             */
            function (?string $attempt) use ($projectAlias, $hostName, &$selected): ?array {
                $selected = $attempt === null
                    ? $this->createDeploymentPublishedSheet($projectAlias, $hostName)
                    : $this->createDeploymentSheet($projectAlias, $hostName);
                if ($attempt !== null) {
                    $selected->getFilters()->addConditionFromString('uid', $attempt, ComparatorDataType::EQUALS);
                }
                $attributes = ['uid', 'log', 'status', 'deploy_recipe_file', 'build__name', 'host__name'];
                $selected->getColumns()->addMultiple($attributes);
                $selected->dataRead();
                if ($selected->isEmpty()) {
                    $selected = null;
                    return null;
                }
                $snapshot = [];
                foreach ($attributes as $attribute) {
                    $snapshot[$attribute] = $selected->getCellValue($attribute, 0);
                }
                return $snapshot;
            },
            /**
             * Applies a lifecycle report only while its previously read state still matches.
             *
             * @param array<string, mixed> $values Deployment attributes to update.
             * @param array<string, mixed>|null $expected Optional expected current values.
             * @return bool Whether exactly one deployment row was updated.
             */
            function (array $values, ?array $expected) use (&$selected): bool {
                if ($selected === null) {
                    throw new \LogicException('Read a deployment before reporting its lifecycle.');
                }
                return DeploymentRecordWriter::update($selected->extractSystemColumns(), $values, 0, $expected) === 1;
            },
            fn () => $this->redeploy($projectAlias, $hostName)
        );
    }

    /**
     * Tests legacy action output for a CLI error marker.
     *
     * @param string $cliOutput Action result message.
     * @return bool Whether the output represents a CLI error.
     */
    protected function isCliError(string $cliOutput): bool
    {
        return LegacyDeploymentHttpHandler::isCliError($cliOutput);
    }

    /**
     * Starts a new deployment of the last successfully deployed build.
     *
     * This compatibility path is invoked only by explicit redeploy requests;
     * ordinary publication reads do not start a deployment.
     *
     * @param string $projectAlias Project alias.
     * @param string $hostName Host UID.
     * @return void
     * @throws RuntimeException If no successful deployment exists or the action reports an error.
     */
    protected function redeploy(string $projectAlias, string $hostName)
    {
        $lastCompletedSheet = $this->createDeploymentCompletedSheet($projectAlias, $hostName);
        $lastCompletedSheet->getColumns()->addMultiple([
            'build__name'
        ]);
        if ($lastCompletedSheet->isEmpty()) {
            throw new RuntimeException('Cannot redeploy to host "' . $hostName . '": no previous successfully deployments found');
        }
        $buildName = $lastCompletedSheet->getCellValue('build__name', 0);
        $task = new CliTask(
            $this->getWorkbench(),
            'deploy',
            [
                'build' => $buildName,
                'host' => $hostName
            ]
        );
        $deployAction = ActionFactory::createFromString($this->getWorkbench(), Deploy::class);
        $result = $deployAction->handle($task);
        $output = $result->getMessage();
        if ($this->isCliError($output)) {
            throw new RuntimeException('Redeployment failed: ' . $output);
        }
    }

    /**
     * Creates a sheet for the latest deployment attempt at one host.
     *
     * @param string $projectAlias Project alias.
     * @param string $hostName Host UID.
     * @return DataSheetInterface Deployment sheet sorted newest first and limited to one row.
     */
    protected function createDeploymentSheet(string $projectAlias, string $hostName): DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.deployment');
        $ds->getColumns()->addFromSystemAttributes();
        $ds->getFilters()->addConditionFromString('host__project__alias', $projectAlias, ComparatorDataType::EQUALS);
        $ds->getFilters()->addConditionFromString('host', $hostName, ComparatorDataType::EQUALS);
        $ds->getSorters()->addFromString('started_on', SortingDirectionsDataType::DESC);
        $ds->setRowsLimit(1);
        return $ds;
    }

    /**
     * Creates a latest-deployment sheet limited to published, nonterminal statuses.
     *
     * @param string $projectAlias Project alias.
     * @param string $hostName Host UID.
     * @return DataSheetInterface Deployment sheet filtered to publication statuses 60 through 89.
     */
    protected function createDeploymentPublishedSheet(string $projectAlias, string $hostName): DataSheetInterface
    {
        $ds = $this->createDeploymentSheet($projectAlias, $hostName);
        $ds->getFilters()->addConditionFromString('status', 60, ComparatorDataType::GREATER_THAN_OR_EQUALS);
        $ds->getFilters()->addConditionFromString('status', 90, ComparatorDataType::LESS_THAN);
        return $ds;
    }

    /**
     * Creates a deployment sheet limited to successfully completed status 99.
     *
     * @param string $projectAlias Project alias.
     * @param string $hostName Host UID.
     * @return DataSheetInterface Deployment sheet filtered to successful rows.
     */
    protected function createDeploymentCompletedSheet(string $projectAlias, string $hostName): DataSheetInterface
    {
        $ds = $this->createDeploymentSheet($projectAlias, $hostName);
        $ds->getFilters()->addConditionFromString('status', 99, ComparatorDataType::EQUALS);
        return $ds;
    }

    /**
     * Adds HTTP Basic authentication extraction to the inherited middleware stack.
     *
     * @return array Middleware instances in request-processing order.
     */
    protected function getMiddleware(): array
    {
        $middleware = parent::getMiddleware();
        $middleware[] = new AuthenticationMiddleware(
            $this,
            [
                [AuthenticationMiddleware::class, 'extractBasicHttpAuthToken']
            ]
        );
        return $middleware;
    }

    /**
     * Returns the common response headers inherited from the base facade.
     *
     * @return array<string, string> HTTP response headers.
     */
    protected function buildHeadersCommon(): array
    {
        // TODO add more headers
        return parent::buildHeadersCommon();
    }
}
