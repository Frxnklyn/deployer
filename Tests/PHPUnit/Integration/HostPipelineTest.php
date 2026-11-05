<?php
namespace axenox\Deployer\Tests\PHPUnit\Integration;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Widgets\InputComboTable;
use exface\Core\Widgets\InputUxon;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Isolated additive schema and transactional host editor validation, no retained records.
 *
 * @author Brooklyn Fränzschky
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class HostPipelineTest extends TestCase
{
    private ?Workbench $workbench = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        if (getenv('EXFACE_DEPLOYER_PERSISTENCE_TEST') !== '1') {
            self::markTestSkipped('Set EXFACE_DEPLOYER_PERSISTENCE_TEST=1 for isolated model/migration checks.');
        }
        $this->workbench = new Workbench();
        $this->workbench->start();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->workbench !== null) {
            $this->workbench->stop();
            restore_error_handler();
            restore_exception_handler();
        }
    }

    /**
     * Repeated UP/DOWN touches only a random fixture table, preserving deploy_config.
     *
     * @return void
     */
    public function testAdditiveMigrationPreservesLegacySettingsAndOptInAcrossRollback(): void
    {
        $connection = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.Deployer.host')
            ->getMetaObject()->getDataConnection();
        $table = 'pipeline_test_host_' . bin2hex(random_bytes(6));
        $migration = file_get_contents(dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'Install' . DIRECTORY_SEPARATOR . 'Sql' . DIRECTORY_SEPARATOR . 'MySQL'
            . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . '1.4'
            . DIRECTORY_SEPARATOR . '20261104_1647_01_NEW_host_pipeline.sql');
        $migration = str_replace('axxdep_host', $table, $migration);
        [$up, $down] = explode('-- DOWN', explode('-- UP', $migration, 2)[1], 2);
        try {
            $connection->runSql("CREATE TABLE {$table} (oid INT PRIMARY KEY, deploy_config LONGTEXT, path_rel_to_releases VARCHAR(100))");
            $connection->runSql("INSERT INTO {$table} VALUES (1, '{\"default_app_config\":{\"Fixture.config.json\":{\"key\":\"preserved\"}}}', 'legacy')");
            $connection->runSql($up, true);
            $connection->runSql($up, true);
            $row = $connection->runSql("SELECT * FROM {$table}")->getResultArray()[0];
            self::assertNull($row['prototype_file']);
            self::assertSame('legacy', $row['path_rel_to_releases']);
            $configuration = $row['deploy_config'];
            $selector = 'axenox/deployer/Pipelines/Deploy/ManualTransfer.php';
            $connection->runSql("UPDATE {$table} SET prototype_file='{$selector}'");
            $connection->runSql($down, true);
            $connection->runSql($down, true);
            $row = $connection->runSql("SELECT * FROM {$table}")->getResultArray()[0];
            self::assertArrayNotHasKey('prototype_file', $row);
            self::assertSame($selector, $row['trash_prototype_file']);
            self::assertSame($configuration, $row['deploy_config']);
            $connection->runSql($up, true);
            $row = $connection->runSql("SELECT * FROM {$table}")->getResultArray()[0];
            self::assertSame($selector, $row['prototype_file']);
            self::assertArrayNotHasKey('trash_prototype_file', $row);
            self::assertSame($configuration, $row['deploy_config']);
        } finally {
            $connection->runSql("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Imports only new metadata inside a rolled-back transaction and builds the real editor.
     *
     * @return void
     */
    public function testHostEditorUsesDeployDiscoveryAndExistingConfigurationAttribute(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR;
        $prototype = json_decode(file_get_contents($root . 'axenox.Deployer.pipeline_prototype_deploy' . DIRECTORY_SEPARATOR . '02_OBJECT.json'), true, 512, JSON_THROW_ON_ERROR);
        $host = json_decode(file_get_contents($root . 'axenox.Deployer.host' . DIRECTORY_SEPARATOR . '02_OBJECT.json'), true, 512, JSON_THROW_ON_ERROR);
        $attributes = json_decode(file_get_contents($root . 'axenox.Deployer.host' . DIRECTORY_SEPARATOR . '04_ATTRIBUTE.json'), true, 512, JSON_THROW_ON_ERROR);
        $transaction = $this->workbench->data()->startTransaction();
        try {
            $row = $prototype['rows'][0];
            unset($row['_EXPORT_SUMMARY']);
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.OBJECT');
            $sheet->addRows([$row]);
            $sheet->dataSave($transaction);
            $rows = [];
            foreach ($attributes['rows'] as $row) {
                if ($row['ALIAS'] === 'prototype_file') {
                    unset($row['_EXPORT_SUMMARY']);
                    $rows[] = $row;
                }
            }
            self::assertCount(1, $rows);
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.ATTRIBUTE');
            $sheet->addRows($rows);
            $sheet->dataSave($transaction);
            $this->workbench->model()->clearCache();
            $page = UiPageFactory::createBlank($this->workbench, '');
            $editor = $host['rows'][0]['DEFAULT_EDITOR_UXON'];
            $editor['object_alias'] = 'axenox.Deployer.host';
            $page->setContents(new UxonObject($editor));
            $page->getWidgetRoot()->getWidgets();
            $selector = $page->getWidget('deployment_pipeline_selector');
            self::assertInstanceOf(InputComboTable::class, $selector);
            self::assertSame('prototype_file', $selector->getAttributeAlias());
            self::assertFalse($selector->isRequired());
            $discovery = $selector->getAttribute()->getRelation()->getRightObject();
            self::assertSame('pipeline_prototype_deploy', $discovery->getAlias());
            self::assertSame('*/*/Pipelines/Deploy/*.php', $discovery->getDataAddress());
            self::assertFalse($discovery->isWritable());
            self::assertSame('PATHNAME_RELATIVE', $discovery->getUidAttribute()->getAlias());
            $action = $this->getMockBuilder(\axenox\Deployer\Actions\Deploy::class)
                ->disableOriginalConstructor()->onlyMethods(['getWorkbench'])->getMock();
            $action->method('getWorkbench')->willReturn($this->workbench);
            $aliases = (new \ReflectionMethod(\axenox\Deployer\Actions\Deploy::class, 'getHostAttributeAliases'))->invoke($action);
            self::assertContains('prototypefile', $aliases);
            self::assertContains('pathabstoapi', $aliases);
            $config = $page->getWidget('deployment_pipeline_config');
            self::assertInstanceOf(InputUxon::class, $config);
            self::assertSame('deploy_config', $config->getAttributeAlias());
            self::assertSame('generic', $config->getSchema());
            self::assertSame('=deployment_pipeline_selector', $config->getRootPrototype()->toString());
        } finally {
            $transaction->rollback();
        }
    }
}
