<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\AssetPackagistCache;
use PHPUnit\Framework\TestCase;

/**
 * Pure configuration tests; no Composer or registry requests.
 */
class AssetPackagistCacheTest extends TestCase
{
    /**
     * Preserves all original configuration and limits cache priority to known assets.
     *
     * @return void
     */
    public function testCacheRepositoriesPrecedeOriginalListWithoutDuplicateAssetNames(): void
    {
        $input = '{"require":{"example/app":"^1"},"repositories":[{"type":"composer","url":"https://example.invalid"}]}';
        $lock = '{"packages":[{"name":"npm-asset/scope--package"},{"name":"example/app"},{"name":"npm-asset/scope--package"}],'
            . '"packages-dev":[{"name":"bower-asset/jquery"}]}';
        $result = json_decode(AssetPackagistCache::apply($input, $lock), true);
        self::assertSame(['example/app' => '^1'], $result['require']);
        self::assertCount(3, $result['repositories']);
        self::assertSame(['npm-asset/scope--package'], $result['repositories'][0]['only']);
        self::assertSame('https://cdn.asset-packagist.org/p/npm-asset/scope--package/latest.json', $result['repositories'][0]['url']);
        self::assertSame(['bower-asset/jquery'], $result['repositories'][1]['only']);
        self::assertSame('https://example.invalid', $result['repositories'][2]['url']);
    }

    /**
     * Named repositories and disabled defaults must not become list entries.
     *
     * @return void
     */
    public function testNamedRepositoriesRetainNamesAndDisabledDefaultsOnKeyCollision(): void
    {
        $result = json_decode(AssetPackagistCache::apply(
            '{"repositories":{"deployer-asset-cache-0":false,"packagist.org":false,"private":{"type":"composer","url":"https://example.invalid"}}}',
            '{"packages":[{"name":"npm-asset/jquery"}]}'
        ), true);
        self::assertSame('deployer-asset-cache-0-cache', array_key_first($result['repositories']));
        self::assertFalse($result['repositories']['deployer-asset-cache-0']);
        self::assertFalse($result['repositories']['packagist.org']);
        self::assertSame('https://example.invalid', $result['repositories']['private']['url']);
    }

    /**
     * Missing or malformed input must fail explicitly, never select other history.
     *
     * @return void
     */
    public function testInvalidOrAbsentHistoryFailsWithoutLeakingInput(): void
    {
        foreach ([null, '', 'secret-invalid-json', '{}', '[]',
            '{"packages":[{"name":"npm-asset/../../escape"}]}',
            '{"packages":[],"packages-dev":{}}',
            '{"packages":[{}]}'] as $lock) {
            try {
                AssetPackagistCache::apply('{}', $lock);
                self::fail('Invalid history accepted.');
            } catch (\RuntimeException|\InvalidArgumentException $error) {
                self::assertStringNotContainsString('secret-invalid-json', $error->getMessage());
            }
        }
    }

    /**
     * A valid successful lock without assets still preserves configured repositories.
     *
     * @return void
     */
    public function testValidHistoryWithoutAssetsDoesNotInventCacheSources(): void
    {
        self::assertSame(['require' => ['example/app' => '^1'], 'repositories' => []],
            json_decode(AssetPackagistCache::apply('{"require":{"example/app":"^1"}}',
                '{"packages":[{"name":"example/app"}]}'), true));
    }
}
