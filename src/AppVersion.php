<?php

namespace DBDiff;

use Composer\InstalledVersions;

/**
 * The version this build reports, resolved once for every entry point.
 *
 * There are two entry points — `dbdiff` for Composer and `dbdiff.php` for the
 * PHAR and the native binaries — and they each used to name a version
 * themselves. They drifted: the PHAR read the constant the release workflow
 * stamps, while the Composer binary carried a literal `'2.0.0'`, so anyone who
 * installed 3.0.0-rc.12 from Packagist was told they were running 2.0.0.
 *
 * Neither source works alone:
 *
 *   - The stamped constant is written during the build, before Box compiles, so
 *     it is correct in the PHAR and the binaries and is still `dev` in the git
 *     tag that Packagist archives.
 *   - Composer's runtime API knows the installed version exactly, and knows
 *     nothing inside a PHAR, where there is no installed package to ask about.
 *
 * So each is used where it is authoritative, the stamp first because a release
 * artifact should report what it was built as even if it is later installed
 * somewhere odd.
 */
class AppVersion {

    /** Written by the release workflow; `dev` in a working tree. */
    private const UNSTAMPED = 'dev';

    /** This package, as Composer knows it. */
    private const PACKAGE = 'dbdiff/dbdiff';

    public static function current(): string {
        $stamped = defined('DBDiff\VERSION') ? constant('DBDiff\VERSION') : self::UNSTAMPED;
        if (is_string($stamped) && $stamped !== '' && $stamped !== self::UNSTAMPED) {
            return $stamped;
        }

        return self::fromComposer() ?? self::UNSTAMPED;
    }

    /**
     * The installed version, or null when there is nothing to ask.
     *
     * Guarded rather than required: InstalledVersions ships with every Composer
     * 2 install, but a PHAR or a hand-rolled autoloader need not have it, and
     * not knowing the version is never a reason to fail to start. Composer
     * reports tags verbatim, so the `v` this project tags with is trimmed to
     * match what the stamp would have said.
     */
    private static function fromComposer(): ?string {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return self::normalise(InstalledVersions::getPrettyVersion(self::PACKAGE));
        } catch (\OutOfBoundsException $e) {
            return null;   // not installed as a package — a dev checkout
        }
    }

    /**
     * A version Composer reported, or null when it did not really report one.
     *
     * Composer answers `1.0.0+no-version-set` for a root package whose version
     * it cannot determine, which is what a clone of this repository is. That
     * reads as a real 1.0.0 and would be a worse answer than admitting we do not
     * know, so it is treated as not knowing. Tags are reported verbatim, so the
     * `v` this project tags with is trimmed to match what the stamp would say.
     */
    private static function normalise(?string $version): ?string {
        if ($version === null || $version === '' || str_contains($version, 'no-version-set')) {
            return null;
        }

        return ltrim($version, 'v');
    }
}
