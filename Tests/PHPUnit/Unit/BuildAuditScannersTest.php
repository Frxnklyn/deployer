<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Audit\BuildAuditScanners;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use PHPUnit\Framework\TestCase;

/**
 * Covers Deployer-owned trust adapters against the unmodified PackageManager API.
 */
class BuildAuditScannersTest extends TestCase
{
    /**
     * Scanner configuration and custom selectors are preserved and clients retain TLS verification.
     *
     * @return void
     */
    public function testAdaptersPreserveConfigurationAndVerifiedClients(): void
    {
        $input = new UxonObject([
            'npm' => ['class' => '\\axenox\\PackageManager\\Common\\Audit\\Scanner\\ComposerNpmAuditScanner', 'option' => 17],
            'custom' => ['class' => '\\example\\CustomScanner'],
        ]);
        $before = $input->toArray();
        $adapted = BuildAuditScanners::withBuildServerTrust($input)->toArray();
        self::assertSame($before, $input->toArray());
        self::assertSame(17, $adapted['npm']['option']);
        self::assertSame($before['custom'], $adapted['custom']);
        self::assertSame([], BuildAuditScanners::withBuildServerTrust(new UxonObject())->toArray());
        foreach (['ComposerNpmAuditScanner', 'OsvAuditScanner', 'NpmOutdatedScanner', 'PackagistOutdatedScanner'] as $name) {
            $class = '\\axenox\\Deployer\\Common\\Audit\\' . $name;
            $mapping = BuildAuditScanners::withBuildServerTrust(new UxonObject([
                'scanner' => ['class' => strtolower('axenox\\PackageManager\\Common\\Audit\\Scanner\\' . $name)]
            ]))->toArray();
            self::assertSame($class, $mapping['scanner']['class']);
            $scanner = new $class(new Workbench());
            $client = (new \ReflectionMethod($scanner, 'createHttpClient'))->invoke($scanner);
            self::assertTrue($client->getConfig('verify'));
            if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
                && ! ini_get('curl.cainfo') && ! ini_get('openssl.cafile') && ! ini_get('openssl.capath')
                && ! getenv('SSL_CERT_FILE') && ! getenv('SSL_CERT_DIR')) {
                self::assertNotSame(0, $client->getConfig('curl')[CURLOPT_SSL_OPTIONS] & CURLSSLOPT_NATIVE_CA);
            }
        }

    }

    /**
     * Explicit administrator CA settings take precedence over native Windows trust.
     *
     * @return void
     */
    public function testExplicitCaConfigurationPreservesParentClientOptions(): void
    {
        $previous = getenv('SSL_CERT_FILE');
        self::assertTrue(putenv('SSL_CERT_FILE=' . __FILE__));
        try {
            foreach (['ComposerNpmAuditScanner', 'OsvAuditScanner', 'NpmOutdatedScanner', 'PackagistOutdatedScanner'] as $name) {
                $parentClass = '\\axenox\\PackageManager\\Common\\Audit\\Scanner\\' . $name;
                $adapterClass = '\\axenox\\Deployer\\Common\\Audit\\' . $name;
                $parent = new $parentClass(new Workbench());
                $adapter = new $adapterClass(new Workbench());
                $parentClient = (new \ReflectionMethod($parent, 'createHttpClient'))->invoke($parent);
                $adapterClient = (new \ReflectionMethod($adapter, 'createHttpClient'))->invoke($adapter);
                $parentOptions = $parentClient->getConfig();
                $adapterOptions = $adapterClient->getConfig();
                unset($parentOptions['handler'], $adapterOptions['handler']);
                self::assertSame($parentOptions, $adapterOptions);
            }
        } finally {
            putenv($previous === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE=' . $previous);
        }
    }
}
