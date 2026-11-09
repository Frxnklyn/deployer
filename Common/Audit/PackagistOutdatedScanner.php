<?php
namespace axenox\Deployer\Common\Audit;

/**
 * Reuses PackageManager's Packagist outdated scan while adding only the shared Windows
 * build-server HTTP trust fallback; registry selection and scan behavior remain owned by
 * PackageManager.
 */
class PackagistOutdatedScanner extends \axenox\PackageManager\Common\Audit\Scanner\PackagistOutdatedScanner
{
    use WindowsHttpTrustTrait;
}
