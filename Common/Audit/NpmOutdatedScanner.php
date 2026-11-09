<?php
namespace axenox\Deployer\Common\Audit;

/**
 * Reuses PackageManager's npm outdated-registry scan while adding only the shared
 * Windows build-server HTTP trust fallback; registry selection and scan behavior remain
 * owned by PackageManager.
 */
class NpmOutdatedScanner extends \axenox\PackageManager\Common\Audit\Scanner\NpmOutdatedScanner
{
    use WindowsHttpTrustTrait;
}
