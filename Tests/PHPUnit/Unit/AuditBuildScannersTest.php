<?php

namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\AuditBuild;
use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\NpmOutdatedScanner;
use axenox\PackageManager\Common\Audit\Scanner\PackagistOutdatedScanner;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\ActionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Checks scanner selection without database access or live scanner services.
 */
class AuditBuildScannersTest extends TestCase
{
    /**
    * Unconfigured actions must leave scanner selection to PackageManager, including future additions.
     * 
     * @return void
     */
    public function testRegularAuditLeavesScannerSelectionToPackageManager() : void
    {
        $workbench = new Workbench();
        $workbench->getApp('axenox.Deployer')->getConfig()->setOption('AUDIT_BUILD.SCANNERS', new UxonObject([
            'regular' => ['class' => PackagistOutdatedScanner::class]
        ]));
        $config = $workbench->getApp('axenox.PackageManager')->getConfig();
        $config->setOption('AUDIT.SCANNERS', new UxonObject([
            'package_default' => ['class' => PackagistOutdatedScanner::class]
        ]));
        /** @var AuditBuild $action */
        $action = ActionFactory::createFromString($workbench, AuditBuild::class);

        self::assertNull($this->getScannersUxon($action));
        $config->setOption('AUDIT.SCANNERS', new UxonObject([
            'package_default' => ['class' => PackagistOutdatedScanner::class],
            'new_scanner' => ['class' => NpmOutdatedScanner::class]
        ]));
        self::assertNull($this->getScannersUxon($action));
        /** @var Audit $audit */
        $audit = ActionFactory::createFromString($workbench, Audit::class);
        $method = new \ReflectionMethod(Audit::class, 'getScanners');
        $scanners = $method->invoke($audit);
        self::assertSame(['package_default', 'new_scanner'], array_keys($scanners));
        self::assertInstanceOf(PackagistOutdatedScanner::class, $scanners['package_default']);
        self::assertInstanceOf(NpmOutdatedScanner::class, $scanners['new_scanner']);
    }

    /**
     * Post-build overrides must replace regular scanners and retain local scanner options.
     * 
     * @return void
     */
    public function testPostBuildMapReplacesRegularMapAndLoadsScannerOptions() : void
    {
        $workbench = new Workbench();
        $config = $workbench->getApp('axenox.Deployer')->getConfig();
        $config->setOption('AUDIT_BUILD.SCANNERS', new UxonObject([
            'regular' => ['class' => PackagistOutdatedScanner::class]
        ]));
        $expected = ['post_build' => ['class' => NpmOutdatedScanner::class, 'timeout_seconds' => 17]];
        $config->setOption('BUILD.AUDIT.SCANNERS', new UxonObject($expected));
        /** @var AuditBuild $action */
        $action = ActionFactory::createFromString($workbench, AuditBuild::class);
        $source = $config->getOption('BUILD.AUDIT.SCANNERS');
        $action->setScanners($source);
        $source->unsetProperty('post_build');

        $selected = $this->getScannersUxon($action);
        self::assertSame($expected, $selected->toArray());
        /** @var Audit $audit */
        $audit = ActionFactory::createFromString($workbench, Audit::class);
        $audit->setScanners($selected);
        $method = new \ReflectionMethod(Audit::class, 'getScanners');
        $scanners = $method->invoke($audit);
        self::assertSame(['post_build'], array_keys($scanners));
        self::assertInstanceOf(NpmOutdatedScanner::class, $scanners['post_build']);
        self::assertSame(17, $scanners['post_build']->exportUxonObject()->getProperty('timeout_seconds'));
    }

    /**
     * Explicitly disabling scanners must not reactivate either app's defaults.
     * 
     * @return void
     */
    public function testEmptyOverrideDisablesScanners() : void
    {
        $workbench = new Workbench();
        $workbench->getApp('axenox.Deployer')->getConfig()->setOption('AUDIT_BUILD.SCANNERS', new UxonObject([
            'regular' => ['class' => PackagistOutdatedScanner::class]
        ]));
        /** @var AuditBuild $action */
        $action = ActionFactory::createFromString($workbench, AuditBuild::class);
        $action->setScanners([]);
        self::assertSame([], $this->getScannersUxon($action)->toArray());
    }

    /**
     * Exercises local selection without invoking authorization or advisory persistence.
     * 
     * @param AuditBuild $action
    * @return UxonObject|null
     */
    private function getScannersUxon(AuditBuild $action) : ?UxonObject
    {
        $method = new \ReflectionMethod(AuditBuild::class, 'getScannersUxon');
        return $method->invoke($action);
    }
}