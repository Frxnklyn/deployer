<?php
namespace axenox\Deployer\Actions;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\DataSheets\DataCollector;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\TaskFactory;
use exface\Core\Interfaces\Actions\iCreateData;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultDataInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Audits saved package inventories of selected builds and saves their advisories.
 * 
 * Provide one or more `axenox.Deployer.build` rows with their UIDs. Package information 
 * saved in `composer_lock` and `package_bom` is read from the builds and scanned by
 * `axenox.PackageManager.Audit`, independently of the installed project files.
 * Findings are created as `axenox.Deployer.advisory` records and linked through
 * `axenox.Deployer.build_advisory`. The action returns the created build links.
 * 
 * The action calls `axenox.PackageManager.Audit` once per build, passing both `composer_lock`
 * and `package_bom`. Audit runs each applicable configured scanner once. Multi-ecosystem 
 * scanners like Google OSV combine both inventories and avoid duplicate queries for matching 
 * packages and versions. Other scanners use the inputs they support - Composer or SBOM.
 *
 * Multiple scanners can still check the same package, which will be visible in the sources of
 * the created advisories. 
 * 
 * This action does not implement deduplication. It relies on `PreventDuplicatesBehavior`
 * on the destination objects.
 * 
 * See [technical documentation](../Docs/Developer_docs/AuditBuild.md) for more details.
 */
class AuditBuild extends AbstractAction implements iCreateData
{
    private const ADVISORY_COLUMNS = [
        'level', 'name', 'type', 'package', 'ecosystem', 'details_url', 'description',
        'remediation', 'versions_affected', 'version_fixed', 'public_id'
    ];

    /**
     * {@inheritDoc}
     * 
     * @see AbstractAction::init()
     */
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setInputObjectAlias('axenox.Deployer.build');
    }

    /**
     * Audits each build and persists findings and links in the supplied transaction.
     * 
     * {@inheritDoc}
     * 
     * @see AbstractAction::perform()
     * @param TaskInterface $task
     * @param DataTransactionInterface $transaction
     * @return ResultInterface
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $logbook = $this->getLogBook($task);
        
        // Complete the selected build data without modifying the original input sheet.
        $buildsSheet = $this->getInputDataSheet($task)->copy();
        $collector = new DataCollector($buildsSheet->getMetaObject());
        $collector->addAttributeAlias($buildsSheet->getMetaObject()->getUidAttribute()->getAlias());
        $buildLabelColName = $buildsSheet->getMetaObject()->getLabelAttribute()->getAlias();
        $collector->addAttributeAlias($buildLabelColName);
        $collector->addAttributeAlias('composer_lock');
        $collector->addAttributeAlias('package_bom');
        $collector->enrich($buildsSheet);
        $buildUidColName = $buildsSheet->getUidColumnName();

        // Keep the nested audit and all persistence within the supplied transaction.
        $auditSheet = ActionFactory::createFromString($this->getWorkbench(), Audit::class);
        $buildAdvisorySheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.build_advisory');
        $buildAdvisorySheet->getColumns()->addMultiple(['build', 'advisory', 'version']);
        foreach ($buildsSheet->getRows() as $buildRow) {
            $composerLock = $buildRow['composer_lock'] ?? '';
            $packageBom = $buildRow['package_bom'] ?? '';
            if (trim($composerLock) === '' && trim($packageBom) === '') {
                $logbook->addLine('**Skipping** build ' . $buildRow[$buildLabelColName] . ' because composer_lock and package_bom are missing or empty.');
                continue;
            }
            $logbook->addLine('**Auditing** build ' . $buildRow[$buildLabelColName]);
            $logbook->addIndent(+1);
            
            // Scan this build's saved artifacts, independently of the current installation.
            $auditTask = TaskFactory::createEmpty($this->getWorkbench());
            $auditTask->setActionSelector($auditSheet->getAliasWithNamespace());
            if (trim($composerLock) !== '') {
                $auditTask->setParameter(Audit::TASK_PARAM_COMPOSER_LOCK, $composerLock);
            }
            if (trim($packageBom) !== '') {
                $auditTask->setParameter(Audit::TASK_PARAM_SBOM, $packageBom);
            }
            $auditResult = $auditSheet->handle($auditTask, $transaction);
            if (! $auditResult instanceof ResultDataInterface) {
                throw new ActionRuntimeError($this, 'PackageManager Audit did not return findings data.');
            }
            $logbook->addLine($auditResult->getMessage());
            $findings = $auditResult->getData()->getRows();
            if ($findings === []) {
                $logbook->addIndent(-1);
                continue;
            }

            // Save model-supported finding fields and obtain the persisted advisory UIDs.
            $advisoriesSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.advisory');
            $advisoriesSheet->getColumns()->addMultiple(self::ADVISORY_COLUMNS);
            foreach ($findings as $finding) {
                $advisoriesSheet->addRow($this->mapAdvisoryRow($finding));
            }
            $advisoriesSheet->dataCreate(false, $transaction);
            $advisoryUidsCol = $advisoriesSheet->getUidColumn();
            if ($advisoryUidsCol === null || $advisoriesSheet->countRows() !== count($findings)) {
                throw new ActionRuntimeError($this, 'Created advisories must return one UID for each finding.');
            }
            // Link each persisted advisory to its build and collect the saved links.
            $buildLinks = $buildAdvisorySheet->copy();
            $buildLinks->removeRows();
            foreach ($advisoryUidsCol->getValues() as $findingIndex => $advisoryUid) {
                if ($advisoryUid === null || $advisoryUid === '') {
                    throw new ActionRuntimeError($this, 'Cannot link an advisory without its persisted UID.');
                }
                $buildLinks->addRow($this->mapBuildAdvisoryRow($buildRow[$buildUidColName], $advisoryUid, $findings[$findingIndex]));
            }
            $buildLinks->dataCreate(false, $transaction);
            $buildAdvisorySheet->addRows($buildLinks->getRows());
            $logbook->addIndent(-1);
        }
        $summary = 'Audited ' . $buildsSheet->countRows() . ' build(s); saved ' . $buildAdvisorySheet->countRows() . ' advisory link(s).';
        $logbook->addLine($summary);
        $result = ResultFactory::createDataResult($task, $buildAdvisorySheet, $summary);
        $result->setDataModified($buildAdvisorySheet->countRows() > 0);
        return $result;
    }

    /**
     * Maps scanner evidence to the attributes owned by the advisory model.
     * 
     * Audit's deterministic ID is not a database UID. Scanner provenance and
        * installed versions do not belong to shared advisory records.
     * 
     * @param array<string, string> $finding
     * @return array<string, string>
     */
    protected function mapAdvisoryRow(array $finding) : array
    {
        return [
            'level' => $finding['LEVEL'],
            'name' => $finding['NAME'],
            'type' => $finding['TYPE'],
            'package' => $finding['PACKAGE'],
            'ecosystem' => $finding['ECOSYSTEM'] !== '' ? $finding['ECOSYSTEM'] : PackageEcosystemDataType::UNKNOWN,
            'details_url' => $finding['DETAILS_URL'],
            'description' => $finding['DESCRIPTION'],
            'remediation' => $finding['REMEDIATION'],
            'versions_affected' => $finding['VERSIONS_AFFECTED'],
            'version_fixed' => $finding['VERSION_FIXED'],
            'public_id' => $finding['PUBLIC_ID']
        ];
    }

    /**
     * Keeps installed-version evidence on the build-specific advisory assignment.
     * 
     * Shared advisories can affect builds containing different package releases.
     * 
     * @param string $buildUid
     * @param string $advisoryUid
     * @param array<string, string> $finding
     * @return array<string, string>
     */
    protected function mapBuildAdvisoryRow(string $buildUid, string $advisoryUid, array $finding) : array
    {
        return [
            'build' => $buildUid,
            'advisory' => $advisoryUid,
            'version' => $finding['VERSION_INSTALLED']
        ];
    }

    /**
     * {@inheritDoc}
     * 
     * @see AbstractAction::isTriggerWidgetRequired()
     * @return bool|null
     */
    public function isTriggerWidgetRequired() : ?bool
    {
        return false;
    }
}