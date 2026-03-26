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

    // ── envDbUrl() ───────────────────────────────────────────────────────────

    public function testEnvDbUrlReturnsSupabaseDbUrlFirst(): void
    {
        putenv('SUPABASE_DB_URL=postgresql://test@localhost/db');
        putenv('DATABASE_URL=postgresql://other@localhost/db');

        $url = SupabaseProjectDetector::envDbUrl();
        $this->assertSame('postgresql://test@localhost/db', $url);

        putenv('SUPABASE_DB_URL');
        putenv('DATABASE_URL');
    }

    public function testEnvDbUrlFallsToDatabaseUrl(): void
    {
        putenv('SUPABASE_DB_URL');
        putenv('DATABASE_URL=postgresql://fallback@localhost/db');
        putenv('DIRECT_URL');

        $url = SupabaseProjectDetector::envDbUrl();
        $this->assertSame('postgresql://fallback@localhost/db', $url);

        putenv('DATABASE_URL');
    }

    public function testEnvDbUrlFallsToDirectUrl(): void
    {
        putenv('SUPABASE_DB_URL');
        putenv('DATABASE_URL');
        putenv('DIRECT_URL=postgresql://direct@localhost/db');

        $url = SupabaseProjectDetector::envDbUrl();
        $this->assertSame('postgresql://direct@localhost/db', $url);

        putenv('DIRECT_URL');
    }

    public function testEnvDbUrlReturnsNullWhenNoneSet(): void
    {
        putenv('SUPABASE_DB_URL');
        putenv('DATABASE_URL');
        putenv('DIRECT_URL');

        $this->assertNull(SupabaseProjectDetector::envDbUrl());
    }

    // ── extractTomlSection() ─────────────────────────────────────────────────

    public function testExtractTomlSectionReturnsNullWhenSectionAbsent(): void
    {
        $contents = "[api]\nport = 54321\n";
        $this->assertNull(SupabaseProjectDetector::extractTomlSection($contents, 'db'));
    }

    public function testExtractTomlSectionReturnsSectionBody(): void
    {
        $contents = "[db]\nport = 54322\n";
        $body = SupabaseProjectDetector::extractTomlSection($contents, 'db');
        $this->assertNotNull($body);
        $this->assertStringContainsString('port = 54322', $body);
    }

    public function testExtractTomlSectionStopsAtNextSection(): void
    {
        $contents = "[db]\nport = 54322\n\n[auth]\nenabled = true\n";
        $body = SupabaseProjectDetector::extractTomlSection($contents, 'db');
        $this->assertStringContainsString('port = 54322', $body);
        $this->assertStringNotContainsString('enabled', $body);
    }

    public function testExtractTomlSectionReadsToEndWhenLastSection(): void
    {
        $contents = "[api]\nfoo = 1\n\n[db]\nport = 54322\npassword = \"secret\"\n";
        $body = SupabaseProjectDetector::extractTomlSection($contents, 'db');
        $this->assertStringContainsString('port = 54322', $body);
        $this->assertStringContainsString('password = "secret"', $body);
    }

    // ── parseTomlDbSection() ─────────────────────────────────────────────────

    public function testParseTomlDbSectionUsesDefaultsForEmptySection(): void
    {
        $result = SupabaseProjectDetector::parseTomlDbSection('# just a comment');
        $this->assertSame(54322, $result['port']);
        $this->assertSame('postgres', $result['pass']);
    }

    public function testParseTomlDbSectionParsesPort(): void
    {
        $result = SupabaseProjectDetector::parseTomlDbSection("port = 55000\n");
        $this->assertSame(55000, $result['port']);
    }

    public function testParseTomlDbSectionParsesQuotedPass(): void
    {
        $result = SupabaseProjectDetector::parseTomlDbSection("password = \"mysecret\"\n");
        $this->assertSame('mysecret', $result['pass']);
    }

    public function testParseTomlDbSectionParsesUnquotedPass(): void
    {
        $result = SupabaseProjectDetector::parseTomlDbSection("password = noquotes\n");
        $this->assertSame('noquotes', $result['pass']);
    }

    // ── configTomlDbUrl() ────────────────────────────────────────────────────

    public function testConfigTomlDbUrlReturnsNullWhenNoConfigToml(): void
    {
        $dir = $this->makeTempDir();
        $this->assertNull(SupabaseProjectDetector::configTomlDbUrl($dir));
    }

    public function testConfigTomlDbUrlParsesDbSection(): void
    {
        $dir = $this->makeTempDir();
        $supaDir = $dir . '/supabase';
        mkdir($supaDir, 0755, true);
        file_put_contents($supaDir . '/config.toml', <<<'TOML'
[api]
port = 54321

[db]
port = 54322
password = "my_secret_password"
TOML);

        $url = SupabaseProjectDetector::configTomlDbUrl($dir);
        $this->assertSame('postgresql://postgres:my_secret_password@127.0.0.1:54322/postgres', $url);
    }

    public function testConfigTomlDbUrlUsesDefaultsWhenDbSectionMinimal(): void
    {
        $dir = $this->makeTempDir();
        $supaDir = $dir . '/supabase';
        mkdir($supaDir, 0755, true);
        file_put_contents($supaDir . '/config.toml', <<<'TOML'
[db]
# minimal — uses defaults
TOML);

        $url = SupabaseProjectDetector::configTomlDbUrl($dir);
        $this->assertSame('postgresql://postgres:postgres@127.0.0.1:54322/postgres', $url);
    }

    public function testConfigTomlDbUrlReturnsNullWithoutDbSection(): void
    {
        $dir = $this->makeTempDir();
        $supaDir = $dir . '/supabase';
        mkdir($supaDir, 0755, true);
        file_put_contents($supaDir . '/config.toml', "[api]\nport = 54321\n");

        $this->assertNull(SupabaseProjectDetector::configTomlDbUrl($dir));
    }

    public function testConfigTomlDbUrlEncodesSpecialCharsInPassword(): void
    {
        $dir = $this->makeTempDir();
        $supaDir = $dir . '/supabase';
        mkdir($supaDir, 0755, true);
        file_put_contents($supaDir . '/config.toml', <<<'TOML'
[db]
port = 54322
password = "p@ss#word"
TOML);

        $url = SupabaseProjectDetector::configTomlDbUrl($dir);
        $this->assertStringContainsString('p%40ss%23word', $url);
    }

    // ── linkedProjectRef() ───────────────────────────────────────────────────

    public function testLinkedProjectRefReturnsNullWhenNoFile(): void
    {
        $dir = $this->makeTempDir();
        $this->assertNull(SupabaseProjectDetector::linkedProjectRef($dir));
    }

    public function testLinkedProjectRefReadsFromSupabaseTempDir(): void
    {
        $dir = $this->makeTempDir();
        $refDir = $dir . '/.supabase/temp';
        mkdir($refDir, 0755, true);
        file_put_contents($refDir . '/project-ref', "abcdefghijklmnopqrst\n");

        $ref = SupabaseProjectDetector::linkedProjectRef($dir);
        $this->assertSame('abcdefghijklmnopqrst', $ref);
    }

    public function testLinkedProjectRefTrimsWhitespace(): void
    {
        $dir = $this->makeTempDir();
        $refDir = $dir . '/.supabase/temp';
        mkdir($refDir, 0755, true);
        file_put_contents($refDir . '/project-ref', "  myref  \n");

        $ref = SupabaseProjectDetector::linkedProjectRef($dir);
        $this->assertSame('myref', $ref);
    }

    public function testLinkedProjectRefReturnsNullForEmptyFile(): void
    {
        $dir = $this->makeTempDir();
        $refDir = $dir . '/.supabase/temp';
        mkdir($refDir, 0755, true);
        file_put_contents($refDir . '/project-ref', '');

        $this->assertNull(SupabaseProjectDetector::linkedProjectRef($dir));
    }

    // ── remoteDbUrl() ────────────────────────────────────────────────────────

    public function testRemoteDbUrlBuildsDirectUrlWithoutRegion(): void
    {
        $url = SupabaseProjectDetector::remoteDbUrl('abcref', 'mypassword');
        $this->assertSame(
            'postgresql://postgres:mypassword@db.abcref.supabase.co:5432/postgres',
            $url
        );
    }

    public function testRemoteDbUrlBuildsPoolerUrlWithRegion(): void
    {
        $url = SupabaseProjectDetector::remoteDbUrl('abcref', 'mypassword', 'us-east-1');
        $this->assertSame(
            'postgresql://postgres.abcref:mypassword@aws-0-us-east-1.pooler.supabase.com:5432/postgres',
            $url
        );
    }

    public function testRemoteDbUrlUsesEnvPasswordFallback(): void
    {
        putenv('SUPABASE_DB_PASSWORD=envpass');

        $url = SupabaseProjectDetector::remoteDbUrl('ref123');
        $this->assertStringContainsString('envpass', $url);

        putenv('SUPABASE_DB_PASSWORD');
    }

    public function testRemoteDbUrlUsesPlaceholderWhenNoPassword(): void
    {
        putenv('SUPABASE_DB_PASSWORD');

        $url = SupabaseProjectDetector::remoteDbUrl('ref123');
        $this->assertStringContainsString('%5BYOUR-PASSWORD%5D', $url);
    }

    // ── resolveDbUrl() ───────────────────────────────────────────────────────

    public function testResolveDbUrlPrefersEnvVar(): void
    {
        putenv('SUPABASE_DB_URL=postgresql://env@localhost/db');

        $url = SupabaseProjectDetector::resolveDbUrl();
        $this->assertSame('postgresql://env@localhost/db', $url);

        putenv('SUPABASE_DB_URL');
    }

    public function testResolveDbUrlFallsToConfigToml(): void
    {
        putenv('SUPABASE_DB_URL');
        putenv('DATABASE_URL');
        putenv('DIRECT_URL');

        $dir = $this->makeTempDir();
        $supaDir = $dir . '/supabase';
        mkdir($supaDir, 0755, true);
        file_put_contents($supaDir . '/config.toml', "[db]\nport = 54322\npassword = \"test\"\n");

        // Note: resolveDbUrl with explicit projectRoot will try local CLI first
        // (which returns null in test env), then fall to config.toml
        $url = SupabaseProjectDetector::resolveDbUrl($dir);
        $this->assertSame('postgresql://postgres:test@127.0.0.1:54322/postgres', $url);
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
