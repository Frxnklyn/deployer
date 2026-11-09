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
 * Verifies pipeline storage and the real Core variant editor without retaining app changes.
 *
 * @author Brooklyn Fränzschky
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class BuildVariantPipelineTest extends TestCase
{
    private ?Workbench $workbench = null;

    /**
     * Opens the installed local runtime only for explicitly enabled database tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EXFACE_DEPLOYER_PERSISTENCE_TEST') !== '1') {
            self::markTestSkipped('Set EXFACE_DEPLOYER_PERSISTENCE_TEST=1 for isolated pipeline model/migration checks.');
        }
        $this->workbench = new Workbench();
        $this->workbench->start();
    }

    /**
     * Releases Core connections and diagnostics after each isolated process.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        try {
            if ($this->workbench !== null) {
                $this->workbench->stop();
                restore_error_handler();
                restore_exception_handler();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Applies the actual migration only to a randomly named fixture table.
     *
     * Repeated upgrades and rollbacks must preserve legacy values and saved UXON.
     *
     * @return void
     */
    public function testMigrationPreservesLegacyAndPipelineSettingsAcrossRollback(): void
    {
        $connection = DataSheetFactory::createFromObjectIdOrAlias(
            $this->workbench,
            'axenox.Deployer.build_variant'
        )->getMetaObject()->getDataConnection();
        $table = 'pipeline_test_variant_' . bin2hex(random_bytes(6));
        $migration = file_get_contents(dirname(__DIR__, 3)
            . '/Install/Sql/MySQL/Migrations/1.4/20261104_1006_01_NEW_build_variant_pipeline_config.sql');
        $migration = str_replace('axxdep_build_variant', $table, $migration);
        [$up, $down] = explode('-- DOWN', explode('-- UP', $migration, 2)[1], 2);
        try {
            $connection->runSql("CREATE TABLE {$table} (
                oid INT PRIMARY KEY, composer_json LONGTEXT NOT NULL, composer_auth_json LONGTEXT NULL
            )");
            $connection->runSql("INSERT INTO {$table} VALUES (1, '{\"require\":{}}', NULL)");
            $connection->runSql($up, true);
            $connection->runSql($up, true);
            $row = $connection->runSql("SELECT * FROM {$table} WHERE oid = 1")->getResultArray()[0];
            self::assertSame('{"require":{}}', $row['composer_json']);
            self::assertNull($row['composer_auth_json']);
            self::assertNull($row['prototype_file']);
            self::assertNull($row['config_uxon']);

            $path = 'example/otherapp/Pipelines/Build/ComposerInstall.php';
            $uxon = json_encode(['description' => str_repeat('x', 70000)], JSON_THROW_ON_ERROR);
            $connection->runSql("UPDATE {$table} SET prototype_file = '{$path}', config_uxon = '{$uxon}' WHERE oid = 1");
            $connection->runSql($down, true);
            $connection->runSql($down, true);
            $row = $connection->runSql("SELECT * FROM {$table} WHERE oid = 1")->getResultArray()[0];
            self::assertArrayNotHasKey('prototype_file', $row);
            self::assertArrayNotHasKey('config_uxon', $row);
            self::assertSame($path, $row['trash_prototype_file']);
            self::assertSame($uxon, $row['trash_config_uxon']);
            self::assertSame('{"require":{}}', $row['composer_json']);

            $connection->runSql($up, true);
            $row = $connection->runSql("SELECT * FROM {$table} WHERE oid = 1")->getResultArray()[0];
            self::assertSame($path, $row['prototype_file']);
            self::assertSame($uxon, $row['config_uxon']);
            self::assertArrayNotHasKey('trash_prototype_file', $row);
            self::assertArrayNotHasKey('trash_config_uxon', $row);
        } finally {
            $connection->runSql("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * Runs Composer data conversion only against disposable fixture tables/views.
     * Invalid, custom and conflicting inputs remain unchanged and appear in the report.
     *
     * @return void
     */
    public function testComposerDataMigrationIsRepeatableAndPreservesConflictsAndDesignerEdits(): void
    {
        $connection = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.Deployer.build_variant')
            ->getMetaObject()->getDataConnection();
        $prefix = 'pipeline_data_test_' . bin2hex(random_bytes(5));
        $project = $prefix . '_project';
        $variant = $prefix . '_variant';
        $report = $prefix . '_report';
        $backup = $prefix . '_backup';
        $sql = file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Install' . DIRECTORY_SEPARATOR
            . 'Sql' . DIRECTORY_SEPARATOR . 'MySQL' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR
            . '1.4' . DIRECTORY_SEPARATOR . '20261105_1245_01_MIGRATE_composer_build_pipelines.sql');
        $sql = strtr($sql, [
            'axxdep_build_pipeline_migration_backup' => $backup,
            'axxdep_build_pipeline_migration' => $report,
            'axxdep_build_variant' => $variant,
            'axxdep_project' => $project
        ]);
        [$up, $down] = explode('-- DOWN', explode('-- UP', $sql, 2)[1], 2);
        try {
            $connection->runSql("CREATE TABLE {$project} (oid BINARY(16) PRIMARY KEY, build_recipe VARCHAR(100) NOT NULL)");
            $connection->runSql("CREATE TABLE {$variant} (
                oid BINARY(16) PRIMARY KEY, project_oid BINARY(16) NOT NULL,
                composer_json LONGTEXT NOT NULL, composer_auth_json LONGTEXT NULL,
                prototype_file VARCHAR(512) NULL, config_uxon LONGTEXT NULL
            )");
            $connection->runSql("INSERT INTO {$project} VALUES
                (UNHEX(LPAD('1',32,'0')), 'ComposerInstall'),
                (UNHEX(LPAD('2',32,'0')), 'ComposerInstallAssetFix'),
                (UNHEX(LPAD('3',32,'0')), 'CustomBuild'),
                (UNHEX(LPAD('4',32,'0')), 'CloneLocal')");
            $connection->runSql("INSERT INTO {$variant} VALUES
                (UNHEX(LPAD('1',32,'0')), UNHEX(LPAD('1',32,'0')), '{\"require\":{\"example/app\":\"^1\"}}', '{\"http-basic\":{\"example.invalid\":{\"password\":\"fixture-secret\"}}}', NULL, NULL),
                (UNHEX(LPAD('2',32,'0')), UNHEX(LPAD('2',32,'0')), '{\"require\":{}}', NULL, '', '{}'),
                (UNHEX(LPAD('3',32,'0')), UNHEX(LPAD('3',32,'0')), '{\"require\":{}}', NULL, NULL, NULL),
                (UNHEX(LPAD('4',32,'0')), UNHEX(LPAD('1',32,'0')), 'invalid-json', NULL, NULL, NULL),
                (UNHEX(LPAD('5',32,'0')), UNHEX(LPAD('1',32,'0')), '{\"require\":{}}', 'invalid-auth', NULL, NULL),
                (UNHEX(LPAD('6',32,'0')), UNHEX(LPAD('1',32,'0')), '{\"require\":{}}', NULL, NULL, '{\"existing\":true}'),
                (UNHEX(LPAD('7',32,'0')), UNHEX(LPAD('1',32,'0')), '{\"require\":{}}', NULL, 'example/app/Pipelines/Build/Other.php', '{\"unchanged\":true}'),
                (UNHEX(LPAD('8',32,'0')), UNHEX(LPAD('4',32,'0')), '{\"require\":{}}', NULL, NULL, NULL),
                (UNHEX(LPAD('9',32,'0')), UNHEX(LPAD('1',32,'0')), '[]', NULL, NULL, NULL)");
            $connection->runSql($up, true);
            $first = $connection->runSql("SELECT HEX(oid) AS id, composer_json, composer_auth_json, prototype_file, config_uxon
                FROM {$variant} ORDER BY oid")->getResultArray();
            $connection->runSql($up, true);
            self::assertSame($first, $connection->runSql("SELECT HEX(oid) AS id, composer_json, composer_auth_json, prototype_file, config_uxon
                FROM {$variant} ORDER BY oid")->getResultArray());
            self::assertSame('axenox/deployer/Pipelines/Build/ComposerInstall.php', $first[0]['prototype_file']);
            $settings = json_decode($first[0]['config_uxon'], true);
            self::assertSame(['example/app' => '^1'], $settings['composer_json']['require']);
            self::assertSame('fixture-secret', $settings['composer_auth']['http-basic']['example.invalid']['password']);
            self::assertArrayNotHasKey('asset_packagist_cache_fix', $settings);
            self::assertTrue(json_decode($first[1]['config_uxon'], true)['asset_packagist_cache_fix']);
            self::assertArrayNotHasKey('composer_auth', json_decode($first[1]['config_uxon'], true));
            self::assertSame($first[0]['composer_auth_json'], '{"http-basic":{"example.invalid":{"password":"fixture-secret"}}}');
            $statuses = $connection->runSql("SELECT migration_status FROM {$report} ORDER BY oid")->getResultArray();
            self::assertSame([
                'ALREADY_SELECTED', 'ALREADY_SELECTED', 'MANUAL_REPLACEMENT_REQUIRED', 'INVALID_COMPOSER_JSON',
                'INVALID_COMPOSER_AUTH', 'TARGET_CONFIG_CONFLICT', 'ALREADY_SELECTED',
                'MANUAL_REPLACEMENT_REQUIRED', 'INVALID_COMPOSER_JSON'
            ], array_column($statuses, 'migration_status'));
            $connection->runSql("UPDATE {$variant} SET config_uxon = '{\"designer_edited\":true}' WHERE oid = UNHEX(LPAD('1',32,'0'))");
            $connection->runSql($down, true);
            $connection->runSql($down, true);
            $rows = $connection->runSql("SELECT prototype_file, config_uxon FROM {$variant} ORDER BY oid")->getResultArray();
            self::assertSame('{"designer_edited":true}', $rows[0]['config_uxon']);
            self::assertSame('', $rows[1]['prototype_file']);
            self::assertSame('{}', $rows[1]['config_uxon']);
            $connection->runSql($up, true);
            $rows = $connection->runSql("SELECT prototype_file, config_uxon FROM {$variant} ORDER BY oid")->getResultArray();
            self::assertSame('{"designer_edited":true}', $rows[0]['config_uxon']);
            self::assertTrue(json_decode($rows[1]['config_uxon'], true)['asset_packagist_cache_fix']);
            self::assertSame(9, count($rows));
        } finally {
            $connection->runSql("DROP VIEW IF EXISTS {$report}");
            $connection->runSql("DROP TABLE IF EXISTS {$backup}");
            $connection->runSql("DROP TABLE IF EXISTS {$variant}");
            $connection->runSql("DROP TABLE IF EXISTS {$project}");
        }
    }

    /**
     * Converts host selections/settings only in disposable tables, preserving paths and history.
     *
     * @return void
     */
    public function testHostDataMigrationMapsRetainedRecipesWithoutDroppingSettings(): void
    {
        $connection = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.Deployer.build_variant')
            ->getMetaObject()->getDataConnection();
        $prefix = 'pipeline_host_test_' . bin2hex(random_bytes(5));
        $project = $prefix . '_project';
        $host = $prefix . '_host';
        $report = $prefix . '_report';
        $backup = $prefix . '_backup';
        $sql = file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Install' . DIRECTORY_SEPARATOR
            . 'Sql' . DIRECTORY_SEPARATOR . 'MySQL' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR
            . '1.4' . DIRECTORY_SEPARATOR . '20261105_1500_01_MIGRATE_host_deployment_pipelines.sql');
        $sql = strtr($sql, [
            'axxdep_host_pipeline_migration_backup' => $backup,
            'axxdep_host_pipeline_migration' => $report,
            'axxdep_host' => $host,
            'axxdep_project' => $project
        ]);
        [$up, $down] = explode('-- DOWN', explode('-- UP', $sql, 2)[1], 2);
        try {
            $connection->runSql("CREATE TABLE {$project} (oid BINARY(16) PRIMARY KEY, deployment_recipe VARCHAR(100) NOT NULL)");
            $connection->runSql("CREATE TABLE {$host} (
                oid BINARY(16) PRIMARY KEY, project_oid BINARY(16) NOT NULL,
                prototype_file VARCHAR(512) NULL, deploy_config LONGTEXT NULL,
                path_abs_to_api VARCHAR(512) DEFAULT 'unchanged-root',
                path_rel_to_releases VARCHAR(100) DEFAULT 'unchanged-relative-path',
                php_cli VARCHAR(255) DEFAULT 'unchanged-php', connection_oid BINARY(16) NULL
            )");
            $connection->runSql("INSERT INTO {$project} VALUES
                (UNHEX(LPAD('1',32,'0')), 'LocalBldUsbSelfExtractor'),
                (UNHEX(LPAD('2',32,'0')), 'LocalBldUpdaterPull'),
                (UNHEX(LPAD('3',32,'0')), 'LocalBldUpdaterCron'),
                (UNHEX(LPAD('4',32,'0')), 'LocalBldUpdaterPush'),
                (UNHEX(LPAD('5',32,'0')), 'LocalBldAzureAppServiceManualInstall'),
                (UNHEX(LPAD('6',32,'0')), 'LocalBldSshSelfExtractor'),
                (UNHEX(LPAD('7',32,'0')), 'CustomDeploy'),
                (UNHEX(LPAD('8',32,'0')), 'LocalBldSshInstall'),
                (UNHEX(LPAD('9',32,'0')), 'LocalBldSshInstallWait')");
            $connection->runSql("INSERT INTO {$host} (oid, project_oid, prototype_file, deploy_config) VALUES
                (UNHEX(LPAD('1',32,'0')), UNHEX(LPAD('1',32,'0')), NULL, '{\"default_app_config\":{\"app.config.json\":{\"secret\":\"fixture-secret\"}},\"keep_releases\":7,\"local_vendors\":[\"demo\"],\"local_packages\":[\"other/local\"],\"shared_dirs\":[\"data\"],\"copy_dirs\":[\"config\"]}'),
                (UNHEX(LPAD('2',32,'0')), UNHEX(LPAD('2',32,'0')), NULL, NULL),
                (UNHEX(LPAD('3',32,'0')), UNHEX(LPAD('3',32,'0')), '', '{}'),
                (UNHEX(LPAD('4',32,'0')), UNHEX(LPAD('4',32,'0')), NULL, '{\"keep_releases\":null}'),
                (UNHEX(LPAD('5',32,'0')), UNHEX(LPAD('5',32,'0')), NULL, '{\"app_service_name\":\"example-app\"}'),
                (UNHEX(LPAD('6',32,'0')), UNHEX(LPAD('6',32,'0')), NULL, '{}'),
                (UNHEX(LPAD('7',32,'0')), UNHEX(LPAD('7',32,'0')), NULL, '{}'),
                (UNHEX(LPAD('8',32,'0')), UNHEX(LPAD('8',32,'0')), NULL, '{}'),
                (UNHEX(LPAD('9',32,'0')), UNHEX(LPAD('9',32,'0')), NULL, '{}'),
                (UNHEX(LPAD('10',32,'0')), UNHEX(LPAD('5',32,'0')), NULL, '{}'),
                (UNHEX(LPAD('11',32,'0')), UNHEX(LPAD('1',32,'0')), NULL, 'invalid-json'),
                (UNHEX(LPAD('12',32,'0')), UNHEX(LPAD('1',32,'0')), NULL, '[]'),
                (UNHEX(LPAD('13',32,'0')), UNHEX(LPAD('1',32,'0')), NULL, '{\"custom_hook\":\"do-not-drop\"}'),
                (UNHEX(LPAD('14',32,'0')), UNHEX(LPAD('1',32,'0')), NULL, '{\"default_app_config\":{},\"config\":{}}'),
                (UNHEX(LPAD('15',32,'0')), UNHEX(LPAD('1',32,'0')), 'example/app/Pipelines/Deploy/Other.php', '{\"keep\":true}'),
                (UNHEX(LPAD('16',32,'0')), UNHEX(LPAD('1',32,'0')), NULL, '{\"default_app_config\":null}'),
                (UNHEX(LPAD('17',32,'0')), UNHEX(LPAD('1',32,'0')), NULL, '{\"operator_os\":\"Windows\"}')");
            $original = $connection->runSql("SELECT * FROM {$host} ORDER BY oid")->getResultArray();
            $connection->runSql($up, true);
            $first = $connection->runSql("SELECT * FROM {$host} ORDER BY oid")->getResultArray();
            $connection->runSql($up, true);
            self::assertSame($first, $connection->runSql("SELECT * FROM {$host} ORDER BY oid")->getResultArray());
            $rows = [];
            foreach ($first as $row) {
                $rows[(int) ltrim(bin2hex($row['oid']), '0')] = $row;
                self::assertSame('unchanged-root', $row['path_abs_to_api']);
                self::assertSame('unchanged-relative-path', $row['path_rel_to_releases']);
                self::assertSame('unchanged-php', $row['php_cli']);
                self::assertNull($row['connection_oid']);
            }
            foreach ([1 => 'ManualTransfer', 2 => 'UpdaterPull', 3 => 'UpdaterPolling', 4 => 'UpdaterPush', 5 => 'AzureManualTransfer'] as $id => $prototype) {
                self::assertStringEndsWith('/' . $prototype . '.php', $rows[$id]['prototype_file']);
            }
            $configuration = json_decode($rows[1]['deploy_config'], true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('default_app_config', $configuration);
            self::assertSame(['app.config.json' => ['secret' => 'fixture-secret']], $configuration['config']);
            self::assertSame(7, $configuration['keep_releases']);
            self::assertSame(['demo'], $configuration['local_vendors']);
            self::assertSame(['other/local'], $configuration['local_packages']);
            self::assertSame(['data'], $configuration['shared_dirs']);
            self::assertSame(['config'], $configuration['copy_dirs']);
            foreach ([2, 3, 4, 16] as $id) {
                self::assertSame(['keep_releases' => 4], json_decode($rows[$id]['deploy_config'], true));
            }
            $reasons = $connection->runSql("SELECT HEX(oid) AS uid, migration_status FROM {$report} ORDER BY oid")->getResultArray();
            self::assertSame('AZURE_APP_NAME_REQUIRED', $reasons[9]['migration_status']);
            self::assertSame('INVALID_DEPLOY_CONFIG', $reasons[10]['migration_status']);
            self::assertSame('INVALID_DEPLOY_CONFIG', $reasons[11]['migration_status']);
            self::assertSame('UNMAPPED_CONFIG_KEYS', $reasons[12]['migration_status']);
            self::assertSame('APP_CONFIG_KEY_CONFLICT', $reasons[13]['migration_status']);
            self::assertSame('UNMAPPED_CONFIG_KEYS', $reasons[16]['migration_status']);
            foreach ([6, 7, 8, 9] as $id) {
                self::assertSame('MANUAL_REPLACEMENT_REQUIRED', $reasons[$id - 1]['migration_status']);
                self::assertNull($rows[$id]['prototype_file']);
            }
            self::assertSame('example/app/Pipelines/Deploy/Other.php', $rows[15]['prototype_file']);
            $connection->runSql($down, true);
            $connection->runSql($down, true);
            self::assertSame($original, $connection->runSql("SELECT * FROM {$host} ORDER BY oid")->getResultArray());
            $connection->runSql($up, true);
            $connection->runSql("UPDATE {$host} SET deploy_config = '{\"keep_releases\":9}' WHERE oid = UNHEX(LPAD('1',32,'0'))");
            $connection->runSql($down, true);
            self::assertSame('{"keep_releases":9}', $connection->runSql("SELECT deploy_config FROM {$host} WHERE oid = UNHEX(LPAD('1',32,'0'))")->getResultArray()[0]['deploy_config']);
            self::assertSame(17, count($first));
        } finally {
            $connection->runSql("DROP VIEW IF EXISTS {$report}");
            $connection->runSql("DROP TABLE IF EXISTS {$backup}");
            $connection->runSql("DROP TABLE IF EXISTS {$host}");
            $connection->runSql("DROP TABLE IF EXISTS {$project}");
        }
    }

    /**
     * Imports new metadata transactionally and constructs the editor through Core.
     *
     * @return void
     */
    public function testExportedModelBuildsAPrototypeBoundUxonEditor(): void
    {
        $root = dirname(__DIR__, 3);
        $prototype = json_decode(file_get_contents($root
            . '/Model/axenox.Deployer.pipeline_prototype_build/02_OBJECT.json'), true, 512, JSON_THROW_ON_ERROR);
        $variant = json_decode(file_get_contents($root
            . '/Model/axenox.Deployer.build_variant/02_OBJECT.json'), true, 512, JSON_THROW_ON_ERROR);
        $attributes = json_decode(file_get_contents($root
            . '/Model/axenox.Deployer.build_variant/04_ATTRIBUTE.json'), true, 512, JSON_THROW_ON_ERROR);
        $transaction = $this->workbench->data()->startTransaction();
        try {
            $row = $prototype['rows'][0];
            unset($row['_EXPORT_SUMMARY']);
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.OBJECT');
            $sheet->addRows([$row]);
            $sheet->dataSave($transaction);

            $rows = [];
            foreach ($attributes['rows'] as $row) {
                if (in_array($row['ALIAS'], ['prototype_file', 'config_uxon'], true)) {
                    unset($row['_EXPORT_SUMMARY']);
                    $rows[] = $row;
                }
            }
            self::assertCount(2, $rows);
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.ATTRIBUTE');
            $sheet->addRows($rows);
            $sheet->dataSave($transaction);
            $this->workbench->model()->clearCache();

            $page = UiPageFactory::createBlank($this->workbench, '');
            $page->setContents(new UxonObject($variant['rows'][0]['DEFAULT_EDITOR_UXON']));
            $tabs = $page->getWidgetRoot()->getTabs();
            self::assertCount(3, $tabs);
            $selector = $page->getWidget('build_pipeline_selector');
            self::assertInstanceOf(InputComboTable::class, $selector);
            self::assertSame('prototype_file', $selector->getAttributeAlias());
            self::assertFalse($selector->isRequired());
            $relation = $selector->getAttribute()->getRelation();
            self::assertSame('pipeline_prototype_build', $relation->getRightObject()->getAlias());
            self::assertSame('*/*/Pipelines/Build/*.php', $relation->getRightObject()->getDataAddress());
            self::assertFalse($relation->getRightObject()->isWritable());
            self::assertSame('PATHNAME_RELATIVE', $relation->getRightObject()->getUidAttribute()->getAlias());

            $inputs = array_values(array_filter(
                iterator_to_array($tabs[0]->getWidgets()),
                /**
                 * Locates the real UXON input without depending on generated widget IDs.
                 *
                 * @param mixed $widget
                 * @return bool
                 */
                static function ($widget): bool {
                    return $widget instanceof InputUxon;
                }
            ));
            self::assertCount(1, $inputs);
            self::assertSame('config_uxon', $inputs[0]->getAttributeAlias());
            self::assertSame('=build_pipeline_selector', $inputs[0]->getRootPrototype()->toString());
            self::assertSame('composer_json', $tabs[1]->getWidgets()[0]->getPanels()[1]->getWidgets()[0]->getAttributeAlias());
            self::assertSame('composer_auth_json', $tabs[2]->getWidgets()[0]->getAttributeAlias());
        } finally {
            $transaction->rollback();
        }
    }
}
