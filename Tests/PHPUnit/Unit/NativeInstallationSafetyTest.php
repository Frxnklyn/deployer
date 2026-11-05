<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Common\Pipelines\NativeBuildFiles;
use axenox\Deployer\Tests\PHPUnit\Fixtures\NativeTestWorkspace;
use axenox\Deployer\Tests\PHPUnit\Integration\RealNativeInstallationTest;
use PHPUnit\Framework\TestCase;

/**
 * Guards the real-install entry point without running it or opening a database.
 *
 * @author Brooklyn Fränzschky
 */
class NativeInstallationSafetyTest extends TestCase
{
    /**
     * @return void
     */
    public function testDefaultSuiteExcludesRealInstallation(): void
    {
        $configuration = simplexml_load_file(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'phpunit.xml.dist');
        self::assertSame('Tests/PHPUnit/Integration/RealNativeInstallationTest.php',
            (string) $configuration->testsuites->testsuite[1]->exclude);
    }

    /**
     * Calls only the pure name classifier, never the real installation test.
     *
     * @return void
     */
    public function testRealMirrorDoesNotMistakePlatformRequirementsForPackages(): void
    {
        $classifier = new \ReflectionMethod(RealNativeInstallationTest::class, 'isPlatformRequirement');
        foreach (['php', 'php-64bit', 'php-ipv6', 'php-zts', 'hhvm', 'ext-mysqli', 'lib-zlib',
            'composer', 'composer-runtime-api', 'composer-plugin-api'] as $name) {
            self::assertTrue($classifier->invoke(null, $name), $name);
        }
        foreach (['exface/core', 'axenox/packagemanager', 'composer/composer'] as $name) {
            self::assertFalse($classifier->invoke(null, $name), $name);
        }
    }

    /**
     * @return void
     */
    public function testWorkspaceIsOutsideWorktreeAndRemoved(): void
    {
        $root = NativeTestWorkspace::create('safety');
        try {
            self::assertDirectoryExists($root);
            self::assertSame(realpath(dirname(__DIR__, 5)), dirname($root));
            self::assertStringStartsNotWith(strtolower(realpath(dirname(__DIR__, 3)) . DIRECTORY_SEPARATOR),
                strtolower($root));
        } finally {
            NativeBuildFiles::remove($root);
        }
        self::assertDirectoryDoesNotExist($root);
        $this->expectException(\RuntimeException::class);
        NativeTestWorkspace::create('safety', dirname(__DIR__, 3));
    }

    /**
     * Checks every missing prerequisite before any filesystem/database side effect.
     *
     * @return void
     */
    public function testRealInstallationRequiresExplicitFlagsAndExternalWorkspace(): void
    {
        $names = ['EXFACE_DEPLOYER_REAL_INSTALL_TEST', 'EXFACE_DEPLOYER_REAL_INSTALL_WORKSPACE',
            'EXFACE_DEPLOYER_TEST_MYSQL_HOST', 'EXFACE_DEPLOYER_TEST_MYSQL_USER', 'EXFACE_DEPLOYER_TEST_MYSQL_PORT'];
        $old = array_combine($names, array_map('getenv', $names));
        try {
            foreach ([
                ['', dirname(__DIR__, 5), '127.0.0.1', 'test-admin', '33306'],
                ['1', '', '127.0.0.1', 'test-admin', '33306'],
                ['1', dirname(__DIR__, 3), '127.0.0.1', 'test-admin', '33306'],
                ['1', dirname(__DIR__, 6), '127.0.0.1', 'test-admin', '33306'],
                ['1', dirname(__DIR__, 7), 'remote-host', 'test-admin', '33306'],
                ['1', dirname(__DIR__, 7), '127.0.0.1', '', '33306'],
                ['1', dirname(__DIR__, 7), '127.0.0.1', 'test-admin', '']
            ] as $values) {
                foreach (array_combine($names, $values) as $name => $value) {
                    putenv($name . '=' . $value);
                }
                try {
                    NativeTestWorkspace::realInstallBase();
                    self::fail('Missing opt-in prerequisites must fail before creating a workspace.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('explicit opt-in', $error->getMessage());
                }
            }
        } finally {
            foreach ($old as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
