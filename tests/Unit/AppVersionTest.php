<?php

namespace Tests\Unit;

use DBDiff\AppVersion;
use PHPUnit\Framework\TestCase;

/**
 * What `--version` reports, and the drift that made it wrong.
 *
 * There are two entry points — `dbdiff` for Composer, `dbdiff.php` for the PHAR
 * and the native binaries — and each used to name a version itself. They
 * drifted: the PHAR read the stamped constant while the Composer binary carried
 * a literal `'2.0.0'`, so anyone installing 3.0.0-rc.12 from Packagist was told
 * they were running 2.0.0 — a version that really exists, which is what made it
 * convincing.
 *
 * The structural test is the one that keeps this fixed. Asserting the resolved
 * string would not have caught the original bug, because the bug was a second
 * copy of the answer living somewhere else.
 */
class AppVersionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Both entry points, as shipped. */
    public static function entryPointProvider(): array
    {
        return [
            'Composer bin'         => ['dbdiff'],
            'PHAR / native binary' => ['dbdiff.php'],
        ];
    }

    /**
     * @dataProvider entryPointProvider
     */
    public function testEntryPointAsksForTheVersionRatherThanNamingIt(string $entryPoint): void
    {
        $source = file_get_contents(self::ROOT . '/' . $entryPoint);
        $this->assertNotFalse($source, "could not read $entryPoint");

        $this->assertMatchesRegularExpression(
            '/new Application\(\s*[\'"]DBDiff[\'"]\s*,\s*\\\\?DBDiff\\\\AppVersion::current\(\)/',
            $source,
            "$entryPoint should take its version from AppVersion"
        );
    }

    /**
     * @dataProvider entryPointProvider
     */
    public function testEntryPointCarriesNoVersionLiteral(string $entryPoint): void
    {
        $source = file_get_contents(self::ROOT . '/' . $entryPoint);

        // The exact shape of the original fault: a semver literal passed to the
        // console application.
        $this->assertDoesNotMatchRegularExpression(
            '/new Application\([^)]*[\'"]\d+\.\d+\.\d+/',
            $source,
            "$entryPoint hardcodes a version, which is how the two drifted apart"
        );
    }

    public function testBothEntryPointsResolveTheVersionIdentically(): void
    {
        $extract = static function (string $file): string {
            $source = file_get_contents(self::ROOT . '/' . $file);
            preg_match('/new Application\((.*?)\);/s', $source, $m);
            return preg_replace('/\s+/', '', $m[1] ?? '');
        };

        $this->assertSame(
            $extract('dbdiff'),
            $extract('dbdiff.php'),
            'the two entry points must construct the application the same way'
        );
    }

    public function testCurrentAlwaysAnswersSomething(): void
    {
        // Never empty: a console application with no version renders oddly, and
        // not knowing the version is not a reason to fail to start.
        $this->assertNotSame('', AppVersion::current());
    }

    public function testCurrentPrefersTheStampedConstant(): void
    {
        // The constant is `dev` in a working tree, so this asserts the contract
        // at the level the test environment can observe: an unstamped build does
        // not silently report a stamped-looking version.
        $stamped = \DBDiff\VERSION;
        if ($stamped !== 'dev' && $stamped !== '') {
            $this->assertSame($stamped, AppVersion::current());
            return;
        }

        $this->assertNotSame('2.0.0', AppVersion::current(), 'the old hardcoded literal');
    }

    /** @dataProvider normaliseProvider */
    public function testNormalise(?string $reported, ?string $expected): void
    {
        $method = new \ReflectionMethod(AppVersion::class, 'normalise');
        $method->setAccessible(true);
        $this->assertSame($expected, $method->invoke(null, $reported));
    }

    public static function normaliseProvider(): array
    {
        return [
            // Composer reports tags verbatim; this project tags with a v.
            'strips the tag prefix'      => ['v3.0.0-rc.12', '3.0.0-rc.12'],
            'leaves a bare version'      => ['3.0.0', '3.0.0'],
            'keeps a branch alias'       => ['dev-master', 'dev-master'],
            // Composer's placeholder for a root package it cannot version. It
            // reads as a real 1.0.0, so it must not be reported as one.
            'rejects the placeholder'    => ['1.0.0+no-version-set', null],
            'rejects nothing at all'     => [null, null],
            'rejects an empty string'    => ['', null],
        ];
    }
}
