<?php
namespace axenox\Deployer\Tests\PHPUnit\Integration;

use axenox\Deployer\Actions\AuditBuild;
use axenox\PackageManager\Actions\Audit;
use exface\Core\Behaviors\PreventDuplicatesBehavior;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Exercises real model duplicate behaviors without running scanners or retaining test records.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class AuditBuildPersistenceTest extends TestCase
{
    private bool $handlersInstalled = false;

    private ?Workbench $workbench = null;

    /**
     * Workbench connections and global diagnostics must not outlive an isolated database test.
     *
     * {@inheritDoc}
     *
     * @see TestCase::tearDown()
     */
    protected function tearDown() : void
    {
        try {
            if ($this->workbench !== null) {
                $this->workbench->stop();
            }
        } finally {
            if ($this->handlersInstalled) {
                restore_error_handler();
                restore_exception_handler();
            }
            parent::tearDown();
        }
    }

    /**
     * The migration must normalize legacy links and preserve evidence when downgrading.
     *
     * Random isolated fixture tables exercise the real migration SQL without changing app tables.
     *
     * @return void
     */
    public function testMigrationBackfillsUrnsAndRefusesLossyDowngrades() : void
    {
        if (getenv('EXFACE_DEPLOYER_PERSISTENCE_TEST') !== '1') {
            self::markTestSkipped('Set EXFACE_DEPLOYER_PERSISTENCE_TEST=1 to enable isolated migration checks.');
        }
        $workbench = $this->workbench = new Workbench();
        $workbench->start();
        $this->handlersInstalled = true;
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.Deployer.advisory');
        $connection = $sheet->getMetaObject()->getDataConnection();
        if (! $connection instanceof SqlDataConnectorInterface) {
            self::markTestSkipped('The migration check requires a SQL data connection.');
        }
        $suffix = bin2hex(random_bytes(6));
        $advisoryTable = 'audit_test_advisory_' . $suffix;
        $linkTable = 'audit_test_link_' . $suffix;
        $migration = file_get_contents(dirname(__DIR__, 3)
            . '/Install/Sql/MySQL/Migrations/1.4/20261030_1200_01_NEW_build_advisory_bom_urn.sql');
        $migration = strtr($migration, ['axxdep_build_advisory' => $linkTable, 'axxdep_advisory' => $advisoryTable]);
        [$up, $down] = explode('-- DOWN', explode('-- UP', $migration, 2)[1], 2);
        $sqlMode = $connection->runSql('SELECT @@SESSION.sql_mode AS mode')->getResultArray()[0]['mode'];
        self::assertMatchesRegularExpression('/^[A-Z_0-9,]*$/', $sqlMode);
        try {
            $connection->runSql("CREATE TABLE {$advisoryTable} (oid INT PRIMARY KEY, ecosystem VARCHAR(50), package VARCHAR(100), public_id VARCHAR(50), version_fixed VARCHAR(50))");
            $connection->runSql("CREATE TABLE {$linkTable} (oid INT PRIMARY KEY, advisory_id INT, version VARCHAR(100))");
            $connection->runSql("INSERT INTO {$advisoryTable} VALUES
                (1, 'npm', 'npm-asset/scope--package--variant', 'CVE-2099-0001', '1.5.0'),
                (2, 'bower', 'bower-asset/jquery', NULL, NULL),
                (3, 'npm', '@scope/native', NULL, NULL)");
            $connection->runSql("INSERT INTO {$linkTable} VALUES (1, 1, '1.0.0'), (2, 2, NULL), (3, 3, '2.0.0')");
            $connection->runSql($up, true);
            $connection->runSql($up, true);
            $expected = ['npm:@scope/package--variant:1.0.0', 'bower:jquery:', 'npm:@scope/native:2.0.0'];
            self::assertSame($expected, array_column($connection->runSql("SELECT bom_urn FROM {$linkTable} ORDER BY oid")->getResultArray(), 'bom_urn'));
            $connection->runSql($down, true);
            self::assertSame($expected, array_column($connection->runSql("SELECT trash_bom_urn FROM {$linkTable} ORDER BY oid")->getResultArray(), 'trash_bom_urn'));
            $connection->runSql($up, true);
            $connection->runSql("UPDATE {$advisoryTable} SET public_id = REPEAT('CVE-2099-0001; ', 10), version_fixed = REPEAT('1.5.0; ', 10) WHERE oid = 1");
            $evidence = $connection->runSql("SELECT public_id, version_fixed FROM {$advisoryTable} WHERE oid = 1")->getResultArray()[0];
            self::assertGreaterThan(50, strlen($evidence['public_id']));
            self::assertGreaterThan(50, strlen($evidence['version_fixed']));
            self::expectException(DataQueryFailedError::class);
            $connection->runSql($down, true);
        } finally {
            $connection->runSql("SET SESSION sql_mode = '{$sqlMode}'");
            $connection->runSql("DROP TABLE IF EXISTS {$linkTable}");
            $connection->runSql("DROP TABLE IF EXISTS {$advisoryTable}");
        }
    }

    /**
    * Model-owned advisory updates must retain version links and follow changed uniqueness criteria.
     *
     * Requires an installed Deployer model and at least one existing build. All writes are rolled
     * back, and the selected build itself is never changed.
     *
     * @return void
     */
    public function testMultipleVersionsShareAdvisoriesWithoutLosingAssignments() : void
    {
        if (getenv('EXFACE_DEPLOYER_PERSISTENCE_TEST') !== '1') {
            self::markTestSkipped('Set EXFACE_DEPLOYER_PERSISTENCE_TEST=1 to enable rollback-only database checks.');
        }
        $workbench = $this->workbench = new Workbench();
        $workbench->start();
        $this->handlersInstalled = true;
        $builds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.Deployer.build');
        $builds->getColumns()->addFromUidAttribute();
        $builds->setRowsLimit(1);
        $builds->setAutoCount(false);
        $builds->dataRead();
        if ($builds->isEmpty()) {
            self::markTestSkipped('The persistence check requires an existing build.');
        }
        $buildUid = $builds->getUidColumn()->getValue(0);
        $name = 'BOM URN regression ' . bin2hex(random_bytes(6));
        $finding = array_replace(array_fill_keys(Audit::COLUMNS, ''), [
            'NAME' => $name, 'PACKAGE' => 'example-package', 'ECOSYSTEM' => 'npm', 'TYPE' => 'vulnerability',
            'LEVEL' => 100, 'DESCRIPTION' => 'First description', 'REMEDIATION' => 'Upgrade first release',
            'PUBLIC_ID' => 'CVE-2099-0001', 'VERSIONS_AFFECTED' => '<1.5.0', 'VERSION_FIXED' => '1.5.0'
        ]);
        $findings = [];
        foreach (['1.0.0', '2.0.0', '', '3.0.0-rc', '3.0.0-RC'] as $version) {
            $findings[] = array_replace($finding, [
                'VERSION_INSTALLED' => $version, 'BOM_URN' => 'npm:example-package:' . $version
            ]);
        }
        $findings[1] = array_replace($findings[1], [
            'LEVEL' => 300, 'PUBLIC_ID' => 'GHSA-2345-6789-2345', 'VERSIONS_AFFECTED' => '>=2.0.0 <2.5.0',
            'VERSION_FIXED' => '2.5.0', 'DESCRIPTION' => 'Second description', 'REMEDIATION' => 'Upgrade second release'
        ]);
        $findings[] = $findings[0];
        $findings[] = array_replace($findings[0], ['ECOSYSTEM' => 'bower', 'BOM_URN' => 'bower:example-package:1.0.0']);
        $action = ActionFactory::createFromString($workbench, AuditBuild::class);
        $save = new \ReflectionMethod(AuditBuild::class, 'saveFindings');
        $transaction = $workbench->data()->startTransaction();
        try {
            $first = $save->invoke($action, $buildUid, $findings, $transaction);
            self::assertCount(6, $first->getRows());
            $firstRows = array_column($first->getRows(), null, 'bom_urn');
            $npmAdvisory = $firstRows['npm:example-package:1.0.0']['advisory'];
            foreach (['2.0.0', '', '3.0.0-rc', '3.0.0-RC'] as $version) {
                self::assertSame($npmAdvisory, $firstRows['npm:example-package:' . $version]['advisory']);
            }
            self::assertNotSame($npmAdvisory, $firstRows['bower:example-package:1.0.0']['advisory']);

            $repeated = $save->invoke($action, $buildUid, array_reverse($findings), $transaction);
            self::assertCount(6, $repeated->getRows());
            $uidAlias = $first->getUidColumnName();
            $firstUids = array_column($first->getRows(), $uidAlias, 'bom_urn');
            $repeatedUids = array_column($repeated->getRows(), $uidAlias, 'bom_urn');
            ksort($firstUids);
            ksort($repeatedUids);
            self::assertSame($firstUids, $repeatedUids);

            $advisories = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.Deployer.advisory');
            $advisories->getColumns()->addMultiple(['name', 'ecosystem', 'level', 'public_id', 'version_fixed', 'versions_affected', 'description', 'remediation']);
            $advisories->getFilters()->addConditionFromString('name', $name, EXF_COMPARATOR_EQUALS);
            $advisories->setRowsLimit(3);
            $advisories->setAutoCount(false);
            $advisories->dataRead();
            self::assertCount(2, $advisories->getRows());
            $byEcosystem = array_column($advisories->getRows(), null, 'ecosystem');
            self::assertSame(100, (int) $byEcosystem['npm']['level']);
            self::assertSame('1.5.0', $byEcosystem['npm']['version_fixed']);
            self::assertSame('CVE-2099-0001', $byEcosystem['npm']['public_id']);
            self::assertSame('<1.5.0', $byEcosystem['npm']['versions_affected']);
            self::assertSame('First description', $byEcosystem['npm']['description']);
            self::assertSame('Upgrade first release', $byEcosystem['npm']['remediation']);

            $changed = $save->invoke($action, $buildUid, [$findings[1]], $transaction);
            self::assertSame($firstUids['npm:example-package:2.0.0'], $changed->getUidColumn()->getValue(0));
            $advisories->dataRead();
            $byEcosystem = array_column($advisories->getRows(), null, 'ecosystem');
            self::assertSame('Second description', $byEcosystem['npm']['description']);
            self::assertSame('2.5.0', $byEcosystem['npm']['version_fixed']);

            $links = $first->copy();
            $links->removeRows();
            $links->getFilters()->addConditionFromString('build', $buildUid, EXF_COMPARATOR_EQUALS);
            $links->getFilters()->addConditionFromString('advisory__name', $name, EXF_COMPARATOR_EQUALS);
            $links->setRowsLimit(7);
            $links->setAutoCount(false);
            $links->dataRead();
            self::assertCount(6, $links->getRows());

            $duplicateBehavior = null;
            foreach ($advisories->getMetaObject()->getBehaviors() as $behavior) {
                if ($behavior instanceof PreventDuplicatesBehavior) {
                    $duplicateBehavior = $behavior;
                    break;
                }
            }
            self::assertInstanceOf(PreventDuplicatesBehavior::class, $duplicateBehavior);
            $duplicateBehavior->setCompareAttributes(['name', 'package', 'ecosystem', 'type', 'public_id']);
            $withChangedKey = $save->invoke($action, $buildUid, [$findings[0], $findings[1]], $transaction);
            self::assertCount(2, $withChangedKey->getRows());
            $changedAdvisoryUids = $withChangedKey->getColumnValues('advisory');
            self::assertNotSame($changedAdvisoryUids[0], $changedAdvisoryUids[1]);
            self::assertSame($npmAdvisory, $changedAdvisoryUids[1]);
            $advisories->setRowsLimit(4);
            $advisories->dataRead();
            self::assertCount(3, $advisories->getRows());
        } finally {
            $transaction->rollback();
        }
        $advisories->dataRead();
        self::assertCount(0, $advisories->getRows());
    }
}