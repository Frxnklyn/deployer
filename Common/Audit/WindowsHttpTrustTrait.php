<?php
namespace axenox\Deployer\Common\Audit;

use GuzzleHttp\Client;

/**
 * Extends a PackageManager scanner's HTTP-client creation with Windows native CA-store
 * support only when no explicit trust source is configured. Scanner- and administrator-
 * supplied client settings remain authoritative; this trait never changes global PHP
 * settings or disables TLS verification.
 */
trait WindowsHttpTrustTrait
{
    /**
     * Creates the parent scanner's client, enabling Windows native CA lookup only as a
     * fallback when the client still uses default verification and no CA override exists.
     *
     * @return Client The scanner client with its original settings preserved.
     */
    protected function createHttpClient(): Client
    {
        $client = parent::createHttpClient();
        if (PHP_OS_FAMILY !== 'Windows' || ! extension_loaded('curl') || ! defined('CURLSSLOPT_NATIVE_CA')
            || ini_get('curl.cainfo') || ini_get('openssl.cafile') || ini_get('openssl.capath')
            || getenv('SSL_CERT_FILE') || getenv('SSL_CERT_DIR') || $client->getConfig('verify') !== true) {
            return $client;
        }
        $options = $client->getConfig();
        $options['curl'][CURLOPT_SSL_OPTIONS] = ($options['curl'][CURLOPT_SSL_OPTIONS] ?? 0) | CURLSSLOPT_NATIVE_CA;
        return new Client($options);
    }
}
