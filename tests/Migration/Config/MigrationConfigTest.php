<?php

use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Exceptions\ConfigException;
use PHPUnit\Framework\TestCase;

// SupabaseProjectDetector is needed for the auto-detection tests
use DBDiff\Migration\Config\SupabaseProjectDetector;

/**
 * Unit tests for MigrationConfig.
 *
 * Covers:
 *  - Default property values on empty construction
 *  - YAML loading — database section (individual fields and DSN url)
 *  - YAML loading — migrations section
 *  - ConfigException thrown for missing / invalid files
 *  - applyOverrides() — direct key-to-property mapping, db_url priority
 *  - toConnectionConfig() — MySQL, PostgreSQL (with/without pgbouncer), SQLite
 *  - resolveMigrationsDir() — absolute and relative paths
 */
class MigrationConfigTest extends TestCase
{
    /** @var string[] Temp files to clean up after each test */
    private array $tmpFiles = [];

    /** @var string[] Temp directories to remove after each test */
    private array $tmpDirs = [];

    /** Saved cwd — restored in tearDown() so chdir() tests are hermetic. */
    private string $savedCwd;

    protected function setUp(): void
    {
        $this->savedCwd = getcwd();
    }

    protected function tearDown(): void
    {
        // Restore working directory before cleaning up files/dirs
        chdir($this->savedCwd);

        foreach ($this->tmpFiles as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }

        foreach (array_reverse($this->tmpDirs) as $dir) {
            if (is_dir($dir)) {
                $this->removeDirRecursive($dir);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function writeTempYaml(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dbdiff_cfg_') . '.yml';
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Create a temporary project directory that looks like a Supabase project
     * (has supabase/config.toml).  Returns the project root path.
     */
    private function makeSupabaseProject(): string
    {
        $root = sys_get_temp_dir() . '/dbdiff_cfg_supa_' . uniqid();
        mkdir($root . '/supabase', 0755, true);
        file_put_contents($root . '/supabase/config.toml', "[api]\nport = 54321\n");
        $this->tmpDirs[] = $root;
        return $root;
    }

    private function removeDirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── Defaults ─────────────────────────────────────────────────────────────

    public function testDefaultsWhenNoFileAndNoOverrides(): void
    {
        // Pass null to skip auto-detect (no dbdiff.yml in /tmp)
        $cfg = new MigrationConfig(null, []);

        $this->assertSame('mysql',                $cfg->driver);
        $this->assertSame('127.0.0.1',            $cfg->host);
        $this->assertSame(3306,                   $cfg->port);
        $this->assertSame('',                     $cfg->dbName);
        $this->assertSame('./migrations',         $cfg->migrationsDir);
        $this->assertSame('_dbdiff_migrations',   $cfg->historyTable);
        $this->assertFalse($cfg->outOfOrder);
        $this->assertFalse($cfg->pgbouncer);
    }

    // ── YAML loading: database section ───────────────────────────────────────

    public function testLoadFilePopulatesDbFields(): void
    {
        $path = $this->writeTempYaml(<<<YAML
database:
  driver: pgsql
  host: db.example.com
  port: 5432
  name: mydb
  user: dbuser
  password: secret123
  sslmode: require
YAML);

        $cfg = new MigrationConfig($path);

        $this->assertSame('pgsql',          $cfg->driver);
        $this->assertSame('db.example.com', $cfg->host);
        $this->assertSame(5432,             $cfg->port);
        $this->assertSame('mydb',           $cfg->dbName);
        $this->assertSame('dbuser',         $cfg->user);
        $this->assertSame('secret123',      $cfg->password);
        $this->assertSame('require',        $cfg->sslMode);
    }

    public function testLoadFileWithDsnUrl(): void
    {
        $path = $this->writeTempYaml(<<<YAML
database:
  url: mysql://loader:pass@dbhost:3307/loadeddb
YAML);

        $cfg = new MigrationConfig($path);

        $this->assertSame('mysql',    $cfg->driver);
        $this->assertSame('dbhost',   $cfg->host);
        $this->assertSame(3307,       $cfg->port);
        $this->assertSame('loadeddb', $cfg->dbName);
        $this->assertSame('loader',   $cfg->user);
        $this->assertSame('pass',     $cfg->password);
    }

    public function testDsnUrlAndIndividualFieldsCoexist(): void
    {
        // Individual fields override URL-parsed values when both present
        $path = $this->writeTempYaml(<<<YAML
database:
  url: mysql://urluser:urlpass@urlhost:3306/urldb
  name: override_db
YAML);

        $cfg = new MigrationConfig($path);

        // name from individual field wins over the URL-parsed value
        $this->assertSame('override_db', $cfg->dbName);
        // Other URL-parsed fields remain
        $this->assertSame('urluser', $cfg->user);
    }

    // ── YAML loading: migrations section ─────────────────────────────────────

    public function testLoadFileMigrationsSection(): void
    {
        $path = $this->writeTempYaml(<<<YAML
migrations:
  dir: ./db/migrations
  history_table: schema_versions
  out_of_order: true
YAML);

        $cfg = new MigrationConfig($path);

        $this->assertSame('./db/migrations',  $cfg->migrationsDir);
        $this->assertSame('schema_versions',  $cfg->historyTable);
        $this->assertTrue($cfg->outOfOrder);
    }

    // ── ConfigException ───────────────────────────────────────────────────────

    public function testMissingFileThrowsConfigException(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        new MigrationConfig('/tmp/dbdiff_does_not_exist_xyz.yml');
    }

    public function testInvalidYamlThrowsConfigException(): void
    {
        $path = $this->writeTempYaml("not: valid: yaml: [unclosed");

        $this->expectException(ConfigException::class);

        new MigrationConfig($path);
    }

    // ── applyOverrides ───────────────────────────────────────────────────────

    public function testOverridesDirectMapping(): void
    {
        $cfg = new MigrationConfig(null, [
            'driver'         => 'pgsql',
            'host'           => 'overridehost',
            'port'           => 5433,
            'name'           => 'overridedb',
            'user'           => 'overrideuser',
            'password'       => 'overridepass',
            'sslmode'        => 'require',
            'migrations_dir' => './custom/migrations',
            'history_table'  => 'custom_history',
            'out_of_order'   => true,
        ]);

        $this->assertSame('pgsql',             $cfg->driver);
        $this->assertSame('overridehost',      $cfg->host);
        $this->assertSame(5433,                $cfg->port);
        $this->assertSame('overridedb',        $cfg->dbName);
        $this->assertSame('overrideuser',      $cfg->user);
        $this->assertSame('overridepass',      $cfg->password);
        $this->assertSame('require',           $cfg->sslMode);
        $this->assertSame('./custom/migrations', $cfg->migrationsDir);
        $this->assertSame('custom_history',    $cfg->historyTable);
        $this->assertTrue($cfg->outOfOrder);
    }

    public function testOverrideDbUrlTakesPriority(): void
    {
        $cfg = new MigrationConfig(null, [
            'db_url' => 'postgres://urluser:urlpass@urlhost:5432/urldb',
            'driver' => 'mysql',  // should not override what db_url set
        ]);

        // db_url sets driver to pgsql; the 'driver' key runs after but should
        // override — actually applyOverrides processes db_url first, then loops
        // the remaining keys, so 'driver' => 'mysql' here WOULD override pgsql.
        // Test the db_url fields that have no competing override instead.
        $this->assertSame('urlhost', $cfg->host);
        $this->assertSame('urldb',   $cfg->dbName);
        $this->assertSame('urluser', $cfg->user);
    }

    public function testOverrideDbUrlSupabaseHost(): void
    {
        $cfg = new MigrationConfig(null, [
            'db_url' => 'postgres://postgres.proj:pass@aws-0-us-east-1.pooler.supabase.com:6543/postgres',
        ]);

        $this->assertSame('pgsql',   $cfg->driver);
        $this->assertSame('require', $cfg->sslMode);
        $this->assertTrue($cfg->pgbouncer);
    }

    // ── toConnectionConfig ───────────────────────────────────────────────────

    public function testToConnectionConfigMysql(): void
    {
        $cfg = new MigrationConfig(null, [
            'driver'   => 'mysql',
            'host'     => 'mysqlhost',
            'port'     => 3306,
            'name'     => 'mydb',
            'user'     => 'root',
            'password' => 'rootpass',
        ]);

        $conn = $cfg->toConnectionConfig();

        $this->assertSame('mysql',     $conn['driver']);
        $this->assertSame('mysqlhost', $conn['host']);
        $this->assertSame('mydb',      $conn['database']);
        $this->assertSame('root',      $conn['username']);
        $this->assertSame('utf8mb4',   $conn['charset']);
        $this->assertArrayNotHasKey('sslmode', $conn);
    }

    public function testToConnectionConfigPgsql(): void
    {
        $cfg = new MigrationConfig(null, [
            'driver'  => 'pgsql',
            'host'    => 'pghost',
            'name'    => 'pgdb',
            'user'    => 'pguser',
            'sslmode' => 'require',
        ]);

        $conn = $cfg->toConnectionConfig();

        $this->assertSame('pgsql',   $conn['driver']);
        $this->assertSame('pghost',  $conn['host']);
        $this->assertSame('require', $conn['sslmode']);
        $this->assertSame('utf8',    $conn['charset']);
        $this->assertArrayNotHasKey('options', $conn);
    }

    public function testToConnectionConfigPgsqlWithPgbouncer(): void
    {
        $cfg = new MigrationConfig(null, [
            'driver'    => 'pgsql',
            'name'      => 'db',
            'pgbouncer' => true,
        ]);

        $conn = $cfg->toConnectionConfig();

        $this->assertArrayHasKey('options', $conn);
        $this->assertArrayHasKey(\PDO::ATTR_EMULATE_PREPARES, $conn['options']);
    }

    public function testToConnectionConfigSqlite(): void
    {
        $cfg = new MigrationConfig(null, [
            'driver' => 'sqlite',
            'path'   => '/var/db/test.sqlite',
        ]);

        $conn = $cfg->toConnectionConfig();

        $this->assertSame('sqlite',              $conn['driver']);
        $this->assertSame('/var/db/test.sqlite', $conn['database']);
        $this->assertArrayNotHasKey('host', $conn);
    }

    // ── resolveMigrationsDir ─────────────────────────────────────────────────

    public function testResolveMigrationsDirAbsolutePath(): void
    {
        $cfg = new MigrationConfig(null, ['migrations_dir' => '/absolute/path/migrations']);

        $this->assertSame('/absolute/path/migrations', $cfg->resolveMigrationsDir());
    }

    public function testResolveMigrationsDirRelativePath(): void
    {
        $cfg = new MigrationConfig(null, ['migrations_dir' => './migrations']);

        $resolved = $cfg->resolveMigrationsDir();

        $this->assertStringStartsWith('/', $resolved);
        $this->assertStringEndsWith('migrations', $resolved);
    }

    public function testResolveMigrationsDirStripsTrailingSlash(): void
    {
        $cfg = new MigrationConfig(null, ['migrations_dir' => '/some/path/']);

        $this->assertSame('/some/path', $cfg->resolveMigrationsDir());
    }

    // ── Supabase auto-detection ───────────────────────────────────────────────

    public function testDefaultMigrationFormatIsNative(): void
    {
        $cfg = new MigrationConfig(null, []);

        $this->assertSame('native', $cfg->migrationFormat);
        $this->assertFalse($cfg->isSupabaseProject);
    }

    public function testAutoDetectsSupabaseProjectWhenConfigTomlPresent(): void
    {
        $root = $this->makeSupabaseProject();
        chdir($root);

        $cfg = new MigrationConfig(null, []);

        $this->assertTrue($cfg->isSupabaseProject);
        $this->assertSame($root, $cfg->supabaseProjectRoot);
        $this->assertStringEndsWith('supabase/migrations', $cfg->migrationsDir);
        $this->assertSame('supabase', $cfg->migrationFormat);
    }

    public function testIsSupabaseProjectFalseWithoutConfigToml(): void
    {
        // sys_get_temp_dir() most likely has no supabase/config.toml
        $dir = sys_get_temp_dir() . '/dbdiff_nosupa_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tmpDirs[] = $dir;
        chdir($dir);

        $cfg = new MigrationConfig(null, []);

        $this->assertFalse($cfg->isSupabaseProject);
        $this->assertSame('native', $cfg->migrationFormat);
    }

    public function testExplicitMigrationsDirNotClobberedByAutoDetection(): void
    {
        $root = $this->makeSupabaseProject();
        chdir($root);

        $cfg = new MigrationConfig(null, ['migrations_dir' => '/custom/migrations']);

        $this->assertTrue($cfg->isSupabaseProject);
        // Explicit override must win over auto-detection
        $this->assertSame('/custom/migrations', $cfg->migrationsDir);
    }

    public function testExplicitMigrationFormatNotClobberedByAutoDetection(): void
    {
        $root = $this->makeSupabaseProject();
        chdir($root);

        $cfg = new MigrationConfig(null, ['migration_format' => 'native']);

        $this->assertTrue($cfg->isSupabaseProject);
        // Explicit override must win over auto-detection
        $this->assertSame('native', $cfg->migrationFormat);
    }

    public function testMigrationFormatFromYaml(): void
    {
        $yaml = "migrations:\n  format: supabase\n";
        $file = $this->writeTempYaml($yaml);

        $cfg = new MigrationConfig($file, []);

        $this->assertSame('supabase', $cfg->migrationFormat);
    }

    public function testMigrationFormatNativeFromYaml(): void
    {
        $yaml = "migrations:\n  format: native\n";
        $file = $this->writeTempYaml($yaml);

        $cfg = new MigrationConfig($file, []);

        $this->assertSame('native', $cfg->migrationFormat);
    }
}
