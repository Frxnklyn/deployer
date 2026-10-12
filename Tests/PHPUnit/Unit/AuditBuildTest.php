<?php

namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\AuditBuild;
use axenox\PackageManager\Actions\Audit;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Checks advisory mapping and action defaults without a persisted metamodel.
 */
class AuditBuildTest extends TestCase
{
    /**
     * The prototype accepts multiple builds and can run without a trigger widget.
     * 
     * @return void
     */
    public function testActionDefaultsAllowOneOrMoreBuilds() : void
    {
        $action = ActionFactory::createFromString(new Workbench(), AuditBuild::class);
        self::assertSame(1, $action->getInputRowsMin());
        self::assertNull($action->getInputRowsMax());
        self::assertFalse($action->isTriggerWidgetRequired());
    }

    /**
     * All advisory attributes receive evidence without copying a scanner ID as UID.
     * 
     * @return void
     */
    public function testMappingMatchesTheAdvisoryModel() : void
    {
        $finding = array_combine(Audit::COLUMNS, Audit::COLUMNS);
        $row = $this->mapFinding($finding);
        $model = json_decode(file_get_contents(dirname(__DIR__, 3)
            . '/Model/axenox.Deployer.advisory/04_ATTRIBUTE.json'), true, 512, JSON_THROW_ON_ERROR);
        $attributes = array_column($model['rows'], 'ALIAS');
        $columns = array_keys($row);
        sort($attributes);
        sort($columns);
        self::assertSame($attributes, $columns);
        self::assertSame([
            'level' => 'LEVEL',
            'name' => 'NAME',
            'type' => 'TYPE',
            'package' => 'PACKAGE',
            'details_url' => 'DETAILS_URL',
            'description' => 'DESCRIPTION',
            'remediation' => 'REMEDIATION',
            'versions_affected' => 'VERSIONS_AFFECTED',
            'version_fixed' => 'VERSION_FIXED',
            'public_id' => 'PUBLIC_ID'
        ], $row);
        self::assertArrayNotHasKey('uid', $row);
        self::assertArrayNotHasKey('id', $row);
        self::assertArrayNotHasKey('version_installed', $row);
        self::assertArrayNotHasKey('source', $row);
    }

    /**
     * Lifecycle findings may have no public identifier or version evidence.
     * 
     * @return void
     */
    public function testMappingPreservesEmptyOptionalEvidence() : void
    {
        $finding = array_fill_keys(Audit::COLUMNS, '');
        $finding['TYPE'] = 'EOL';
        $finding['PACKAGE'] = 'vendor/abandoned';
        $row = $this->mapFinding($finding);
        self::assertSame('EOL', $row['type']);
        self::assertSame('vendor/abandoned', $row['package']);
        self::assertSame('', $row['public_id']);
        self::assertSame('', $row['version_fixed']);
    }

    /**
     * Maps findings locally; this check does not exercise authorization or persistence.
     * 
     * @param array<string, string> $finding
     * @return array<string, string>
     */
    private function mapFinding(array $finding) : array
    {
        $action = ActionFactory::createFromString(new Workbench(), AuditBuild::class);
        $method = new \ReflectionMethod(AuditBuild::class, 'mapAdvisoryRow');
        $method->setAccessible(true);
        return $method->invoke($action, $finding);
    }

    /**
     * One shared advisory retains each build's installed version, including unknown versions.
     * 
     * @return void
     */
    public function testInstalledVersionBelongsToTheBuildLink() : void
    {
        $action = ActionFactory::createFromString(new Workbench(), AuditBuild::class);
        $method = new \ReflectionMethod(AuditBuild::class, 'mapBuildAdvisoryRow');
        $method->setAccessible(true);
        $model = json_decode(file_get_contents(dirname(__DIR__, 3)
            . '/Model/axenox.Deployer.build_advisory/04_ATTRIBUTE.json'), true, 512, JSON_THROW_ON_ERROR);
        $attributes = array_column($model['rows'], 'DATA_ADDRESS', 'ALIAS');
        self::assertSame('version', $attributes['version']);
        foreach (['v1.2.3', '2.0.0', ''] as $version) {
            self::assertSame([
                'build' => 'build-uid',
                'advisory' => 'advisory-uid',
                'version' => $version
            ], $method->invoke($action, 'build-uid', 'advisory-uid', ['VERSION_INSTALLED' => $version]));
        }
    }
}