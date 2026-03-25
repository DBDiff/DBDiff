<?php

use DBDiff\Migration\Config\SupabaseProjectDetector;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SupabaseProjectDetector.
 *
 * All tests use temporary directories so no real supabase/config.toml is
 * required and the tests are fully hermetic.
 */
class SupabaseProjectDetectorTest extends TestCase
{
    /** @var string[] Directories to clean up after each test */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tmpDirs) as $dir) {
            if (is_dir($dir)) {
                $this->removeDir($dir);
            }
        }
    }

    // ── find() ────────────────────────────────────────────────────────────────

    public function testFindReturnsNullWhenNoConfigTomlPresent(): void
    {
        $dir = $this->makeTempDir();
        $this->assertNull(SupabaseProjectDetector::find($dir));
    }

    public function testFindReturnsProjectRootWhenConfigTomlInCurrentDir(): void
    {
        $dir = $this->makeTempDir();
        $this->makeSupabaseConfig($dir);

        $this->assertSame($dir, SupabaseProjectDetector::find($dir));
    }

    public function testFindWalksUpOneLevel(): void
    {
        $root  = $this->makeTempDir();
        $child = $root . '/myapp';
        mkdir($child);
        $this->makeSub($root, $child);

        $this->assertSame($root, SupabaseProjectDetector::find($child));
    }

    public function testFindWalksUpMultipleLevels(): void
    {
        $root      = $this->makeTempDir();
        $deep      = $root . '/a/b/c';
        mkdir($deep, 0755, true);
        $this->makeSub($root, $deep);

        $this->assertSame($root, SupabaseProjectDetector::find($deep));
    }

    public function testFindReturnsImmediateAncestorNotDistantOne(): void
    {
        // Two nested supabase projects — should find the closer one.
        $outer = $this->makeTempDir();
        $inner = $outer . '/sub';
        mkdir($inner);
        $this->makeSupabaseConfig($outer);
        $this->makeSupabaseConfig($inner);

        $this->assertSame($inner, SupabaseProjectDetector::find($inner));
    }

    public function testFindReturnsNullForRandomTempDir(): void
    {
        // sys_get_temp_dir() should not contain a supabase/config.toml
        // (if somehow it does, the test is skipped)
        $tmp = sys_get_temp_dir();
        if (file_exists($tmp . '/supabase/config.toml')) {
            $this->markTestSkipped('Unexpected supabase/config.toml in sys_get_temp_dir()');
        }
        $this->assertNull(SupabaseProjectDetector::find($tmp));
    }

    // ── migrationsDir() ───────────────────────────────────────────────────────

    public function testMigrationsDirAppendsSubabaseMigrationsSubpath(): void
    {
        $this->assertSame(
            '/my/project/supabase/migrations',
            SupabaseProjectDetector::migrationsDir('/my/project')
        );
    }

    public function testMigrationsDirWorksWithTrailingSlashStripped(): void
    {
        // migrationsDir itself doesn't strip slashes — project root is already clean
        $this->assertSame(
            '/project/supabase/migrations',
            SupabaseProjectDetector::migrationsDir('/project')
        );
    }

    // ── localDbUrl() — safe no-op when supabase CLI absent ───────────────────

    public function testLocalDbUrlReturnsNullWhenCliAbsent(): void
    {
        // Run from a temp dir that has no supabase/config.toml so that even
        // if the supabase CLI is installed it won't succeed.
        $dir = $this->makeTempDir();
        $result = SupabaseProjectDetector::localDbUrl($dir);
        // May be null (CLI not installed) or null (local stack not running) — never throws.
        $this->assertNull($result);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/dbdiff_spd_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /** Create supabase/config.toml inside $projectRoot. */
    private function makeSupabaseConfig(string $projectRoot): void
    {
        $supaDir = $projectRoot . '/supabase';
        if (!is_dir($supaDir)) {
            mkdir($supaDir, 0755, true);
        }
        file_put_contents($supaDir . '/config.toml', "[api]\nport = 54321\n");
    }

    /** Apply supabase config to $root but track $child for cleanup only. */
    private function makeSub(string $root, string $child): void
    {
        $this->makeSupabaseConfig($root);
        // Register root for cleanup (child is already under root so rmdir covers it)
        if (!in_array($root, $this->tmpDirs, true)) {
            $this->tmpDirs[] = $root;
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
