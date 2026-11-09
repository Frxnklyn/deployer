<?php
namespace axenox\Deployer\Common\Audit;

/**
 * Reuses PackageManager's Composer/npm audit behavior while adding only the shared
 * Windows build-server HTTP trust fallback; scanner inputs and findings remain owned by
 * PackageManager.
 */
class ComposerNpmAuditScanner extends \axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner
{
    use WindowsHttpTrustTrait;
}
