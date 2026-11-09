<?php
namespace axenox\Deployer\Common\Audit;

use exface\Core\CommonLogic\UxonObject;

/**
 * Maps only PackageManager's known HTTP-backed built-in audit scanners to Deployer wrappers
 * that add Windows native certificate-store trust for build-server requests. Custom scanner
 * selectors and all configured options remain untouched; this helper does not alter global
 * PHP certificate settings or persist the adapted configuration.
 */
final class BuildAuditScanners
{
    /**
     * Returns an adapted copy of the configured scanner list for build execution.
     *
     * @param UxonObject $scanners UXON scanner configuration imported for the audit run.
     * @return UxonObject Copy with only recognized built-in class selectors replaced.
     */
    public static function withBuildServerTrust(UxonObject $scanners): UxonObject
    {
        $data = $scanners->toArray();
        foreach ($data as &$scanner) {
            if (! is_array($scanner) || ! is_string($scanner['class'] ?? null)) {
                continue;
            }
            foreach (['ComposerNpmAuditScanner', 'OsvAuditScanner', 'NpmOutdatedScanner', 'PackagistOutdatedScanner'] as $name) {
                if (strcasecmp(ltrim($scanner['class'], '\\'), 'axenox\\PackageManager\\Common\\Audit\\Scanner\\' . $name) === 0) {
                    $scanner['class'] = '\\' . __NAMESPACE__ . '\\' . $name;
                    break;
                }
            }
        }
        unset($scanner);
        return new UxonObject($data);
    }
}
