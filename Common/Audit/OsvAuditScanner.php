<?php
namespace axenox\Deployer\Common\Audit;

/**
 * Reuses PackageManager's OSV audit behavior while adding only the shared Windows
 * build-server HTTP trust fallback; advisory matching and findings remain owned by
 * PackageManager.
 */
class OsvAuditScanner extends \axenox\PackageManager\Common\Audit\Scanner\OsvAuditScanner
{
    use WindowsHttpTrustTrait;
}
