<?php

use DBDiff\Migration\Runner\MigrationFile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MigrationFile.
 *
 * Covers scaffold(), scanDir(), getChecksum(), hasDown(), getBaseName(),
 * getUpSql(), getDownSql() — all using a temporary directory so no
 * database connection is required.
 */
class MigrationFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbdiff_mf_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up all files in the temp directory and remove it
        foreach (glob("{$this->tmpDir}/*") ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    // ── scaffold() ────────────────────────────────────────────────────────────

    public function testScaffoldCreatesUpFile(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'create users', '20260101120000');
        $this->assertFileExists($mf->upPath);
    }

    public function testScaffoldCreatesDownFile(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'create users', '20260101120000');
        $this->assertFileExists($mf->downPath);
    }

    public function testScaffoldSlugsDescription(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'Create Users Table!', '20260101120000');
        $this->assertSame('create_users_table', $mf->description);
        $this->assertStringContainsString('create_users_table', $mf->upPath);
        $this->assertStringContainsString('create_users_table', $mf->downPath);
    }

    public function testScaffoldSetsVersion(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'test', '20260101120000');
        $this->assertSame('20260101120000', $mf->version);
    }

    public function testScaffoldFilenamingConvention(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'add_index', '20260303120000');
        $this->assertSame('20260303120000_add_index', $mf->getBaseName());
        $upFile = basename($mf->upPath);
        $this->assertSame('20260303120000_add_index.up.sql', $upFile);
    }

    public function testScaffoldThrowsWhenFilesAlreadyExist(): void
    {
        MigrationFile::scaffold($this->tmpDir, 'dup', '20260101120000');
        $this->expectException(\RuntimeException::class);
        MigrationFile::scaffold($this->tmpDir, 'dup', '20260101120000');
    }

    public function testScaffoldCreatesDirectoryIfMissing(): void
    {
        $newDir = $this->tmpDir . '/subdir_' . uniqid();
        $this->assertDirectoryDoesNotExist($newDir);

        $mf = MigrationFile::scaffold($newDir, 'test', '20260101120000');

        $this->assertDirectoryExists($newDir);
        $this->assertFileExists($mf->upPath);

        // Clean up
        unlink($mf->upPath);
        unlink($mf->downPath);
        rmdir($newDir);
    }

    public function testScaffoldUpFileContainsDirectionComment(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'test', '20260101120000');
        $content = file_get_contents($mf->upPath);
        $this->assertStringContainsString('UP', $content);
    }

    public function testScaffoldDownFileContainsDirectionComment(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'test', '20260101120001');
        $content = file_get_contents($mf->downPath);
        $this->assertStringContainsString('DOWN', $content);
    }

    // ── getChecksum() ─────────────────────────────────────────────────────────

    public function testGetChecksumIsSha256Hex(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'chk', '20260101120000');
        $checksum = $mf->getChecksum();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $checksum);
    }

    public function testGetChecksumIsIdempotent(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'chk2', '20260101120001');
        $this->assertSame($mf->getChecksum(), $mf->getChecksum());
    }

    public function testGetChecksumChangesWhenContentChanges(): void
    {
        $mf    = MigrationFile::scaffold($this->tmpDir, 'chk3', '20260101120002');
        $before = $mf->getChecksum();
        file_put_contents($mf->upPath, 'SELECT 1; -- changed');
        $after = $mf->getChecksum();
        $this->assertNotSame($before, $after);
    }

    // ── hasDown() / getDownSql() ──────────────────────────────────────────────

    public function testHasDownTrueAfterScaffold(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'hasdown', '20260101120000');
        $this->assertTrue($mf->hasDown());
    }

    public function testHasDownFalseWhenDownFileDeleted(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'nodown', '20260101120003');
        unlink($mf->downPath);
        $this->assertFalse($mf->hasDown());
    }

    public function testGetDownSqlReturnsNullWhenMissing(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'nulldown', '20260101120004');
        unlink($mf->downPath);
        $this->assertNull($mf->getDownSql());
    }

    public function testGetDownSqlReturnsContentWhenPresent(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'withdown', '20260101120005');
        file_put_contents($mf->downPath, 'DROP TABLE t;');
        $this->assertSame('DROP TABLE t;', $mf->getDownSql());
    }

    // ── getBaseName() ─────────────────────────────────────────────────────────

    public function testGetBaseName(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'create users', '20260101120000');
        $this->assertSame('20260101120000_create_users', $mf->getBaseName());
    }

    // ── scanDir() ─────────────────────────────────────────────────────────────

    public function testScanDirReturnsEmptyForEmptyDir(): void
    {
        $this->assertSame([], MigrationFile::scanDir($this->tmpDir));
    }

    public function testScanDirReturnsEmptyForNonExistentDir(): void
    {
        $this->assertSame([], MigrationFile::scanDir('/tmp/dbdiff_nonexistent_xyz_' . uniqid()));
    }

    public function testScanDirFindsScaffoldedMigrations(): void
    {
        MigrationFile::scaffold($this->tmpDir, 'first', '20260101120000');
        $found = MigrationFile::scanDir($this->tmpDir);
        $this->assertCount(1, $found);
        $this->assertInstanceOf(MigrationFile::class, $found[0]);
    }

    public function testScanDirReturnsMigrationsInAscendingVersionOrder(): void
    {
        MigrationFile::scaffold($this->tmpDir, 'second', '20260102120000');
        MigrationFile::scaffold($this->tmpDir, 'first', '20260101120000');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertCount(2, $found);
        $this->assertSame('20260101120000', $found[0]->version);
        $this->assertSame('20260102120000', $found[1]->version);
    }

    public function testScanDirSkipsFilesNotMatchingNamingConvention(): void
    {
        file_put_contents("{$this->tmpDir}/README.md", '# hi');
        file_put_contents("{$this->tmpDir}/not_a_migration.sql", 'SELECT 1;');
        file_put_contents("{$this->tmpDir}/2026_bad_suffix.up.sql", 'SELECT 1;');

        $found = MigrationFile::scanDir($this->tmpDir);
        $this->assertCount(0, $found);
    }

    public function testScanDirDownPathSetEvenIfFileAbsent(): void
    {
        $mf = MigrationFile::scaffold($this->tmpDir, 'nodown2', '20260101120000');
        unlink($mf->downPath);

        $found = MigrationFile::scanDir($this->tmpDir);
        $this->assertCount(1, $found);
        // downPath is set to the expected path even if the file doesn't exist
        $this->assertStringContainsString('nodown2' . MigrationFile::DOWN_SUFFIX, $found[0]->downPath);
        $this->assertFalse($found[0]->hasDown());
    }

    // ── scanDir() — Supabase plain .sql format ────────────────────────────────

    /** A plain .sql file with the 14-digit prefix is recognised as a Supabase migration. */
    public function testScanDirFindsSupabasePlainSqlFile(): void
    {
        file_put_contents("{$this->tmpDir}/20260218210000_add_build_log.sql", 'CREATE TABLE build_log ();');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertCount(1, $found);
        $this->assertSame('20260218210000', $found[0]->version);
        $this->assertSame('add_build_log',   $found[0]->description);
    }

    /** Supabase-format migrations are UP-only: hasDown() must return false. */
    public function testScanDirSupabaseMigrationHasNoDown(): void
    {
        file_put_contents("{$this->tmpDir}/20260218210000_add_build_log.sql", 'CREATE TABLE build_log ();');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertFalse($found[0]->hasDown());
        $this->assertNull($found[0]->getDownSql());
    }

    /** Supabase UP SQL is readable via getUpSql(). */
    public function testScanDirSupabaseMigrationUpSqlIsReadable(): void
    {
        $sql = 'CREATE TABLE build_log (id bigint primary key);';
        file_put_contents("{$this->tmpDir}/20260303120000_create_log.sql", $sql);

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertSame($sql, $found[0]->getUpSql());
    }

    /** Multiple Supabase files are returned sorted ascending by version. */
    public function testScanDirSupabaseMultipleFilesAreOrdered(): void
    {
        file_put_contents("{$this->tmpDir}/20260303120002_third.sql", '-- c');
        file_put_contents("{$this->tmpDir}/20260303120000_first.sql", '-- a');
        file_put_contents("{$this->tmpDir}/20260303120001_second.sql", '-- b');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertCount(3, $found);
        $this->assertSame('20260303120000', $found[0]->version);
        $this->assertSame('20260303120001', $found[1]->version);
        $this->assertSame('20260303120002', $found[2]->version);
    }

    /** Mixed directory: both native and Supabase formats coexist. */
    public function testScanDirMixedFormatsBothFound(): void
    {
        // DBDiff native
        MigrationFile::scaffold($this->tmpDir, 'native', '20260101120000');
        // Supabase plain
        file_put_contents("{$this->tmpDir}/20260102120000_supabase.sql", 'SELECT 1;');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertCount(2, $found);
        $this->assertSame('20260101120000', $found[0]->version);
        $this->assertSame('20260102120000', $found[1]->version);
    }

    /**
     * When both a .up.sql AND a plain .sql exist for the same version,
     * the DBDiff native (.up.sql) must take precedence and only one
     * MigrationFile should be returned.
     */
    public function testScanDirNativeUpSqlTakesPrecedenceOverPlainSql(): void
    {
        $version = '20260303120000';
        MigrationFile::scaffold($this->tmpDir, 'overlap', $version);
        // Also create a plain .sql for the same version
        file_put_contents("{$this->tmpDir}/{$version}_overlap.sql", 'SELECT 2; -- supabase copy');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertCount(1, $found);
        $this->assertStringEndsWith(MigrationFile::UP_SUFFIX, $found[0]->upPath);
    }

    /** Plain .sql files whose names don't match {14digits}_{desc} are ignored. */
    public function testScanDirIgnoresPlainSqlWithoutVersionPrefix(): void
    {
        file_put_contents("{$this->tmpDir}/helpers.sql", '-- shared helpers');
        file_put_contents("{$this->tmpDir}/2026_short_version.sql", '-- bad version');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertCount(0, $found);
    }

    // ── scaffoldSupabase() ────────────────────────────────────────────────────

    public function testScaffoldSupabaseCreatesSingleSqlFile(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'create users', '20260101120000');

        $this->assertFileExists($mf->upPath);
        $this->assertStringEndsWith('.sql', $mf->upPath);
        $this->assertStringNotContainsString('.up.sql', $mf->upPath);
    }

    public function testScaffoldSupabaseHasNoDown(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'create users', '20260101120000');

        $this->assertFalse($mf->hasDown());
        $this->assertNull($mf->getDownSql());
    }

    public function testScaffoldSupabaseDownPathDoesNotExist(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'create users', '20260101120000');

        $this->assertFileDoesNotExist($mf->downPath);
    }

    public function testScaffoldSupabaseFilenameConvention(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'create users', '20260101120000');

        $this->assertSame('20260101120000_create_users.sql', basename($mf->upPath));
    }

    public function testScaffoldSupabaseSlugsDescription(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'Add Build Log Table', '20260101120000');

        $this->assertStringContainsString('add_build_log_table', basename($mf->upPath));
    }

    public function testScaffoldSupabaseCreatesDirectoryIfMissing(): void
    {
        $newDir = $this->tmpDir . '/sub/dir';
        $mf     = MigrationFile::scaffoldSupabase($newDir, 'init', '20260101120000');

        $this->assertFileExists($mf->upPath);
        // cleanup
        unlink($mf->upPath);
        rmdir($newDir);
        rmdir(dirname($newDir));
    }

    public function testScaffoldSupabaseThrowsWhenFileAlreadyExists(): void
    {
        MigrationFile::scaffoldSupabase($this->tmpDir, 'init', '20260101120000');

        $this->expectException(\DBDiff\Migration\Exceptions\MigrationException::class);
        MigrationFile::scaffoldSupabase($this->tmpDir, 'init', '20260101120000');
    }

    public function testScaffoldSupabaseVersionStoredOnObject(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'create users', '20260101120000');

        $this->assertSame('20260101120000', $mf->version);
    }

    public function testScaffoldSupabaseDescriptionStoredOnObject(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'create users', '20260101120000');

        $this->assertSame('create_users', $mf->description);
    }

    public function testScaffoldSupabaseFileIsPickedUpByScanDir(): void
    {
        MigrationFile::scaffoldSupabase($this->tmpDir, 'create users', '20260101120000');

        $found = MigrationFile::scanDir($this->tmpDir);

        $this->assertCount(1, $found);
        $this->assertSame('20260101120000', $found[0]->version);
    }

    // ── lintSupabaseTransaction() ───────────────────────────────────────────

    public function testLintDetectsBeginAndCommit(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'has txn', '20260101120000');
        file_put_contents($mf->upPath, "BEGIN;\nCREATE TABLE t (id INT);\nCOMMIT;");

        $warnings = $mf->lintSupabaseTransaction();
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('BEGIN', $warnings[0]);
        $this->assertStringContainsString('COMMIT', $warnings[1]);
    }

    public function testLintDetectsRollback(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'has rollback', '20260101120000');
        file_put_contents($mf->upPath, "CREATE TABLE t (id INT);\nROLLBACK;");

        $warnings = $mf->lintSupabaseTransaction();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('ROLLBACK', $warnings[0]);
    }

    public function testLintDetectsStartTransaction(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'start txn', '20260101120000');
        file_put_contents($mf->upPath, "START TRANSACTION;\nCREATE TABLE t (id INT);");

        $warnings = $mf->lintSupabaseTransaction();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('START TRANSACTION', $warnings[0]);
    }

    public function testLintReturnsEmptyForCleanSql(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'clean', '20260101120000');
        file_put_contents($mf->upPath, 'CREATE TABLE clean (id INT);');

        $this->assertSame([], $mf->lintSupabaseTransaction());
    }

    public function testLintIgnoresTransactionKeywordsInComments(): void
    {
        $mf = MigrationFile::scaffoldSupabase($this->tmpDir, 'commented', '20260101120000');
        file_put_contents($mf->upPath, "-- BEGIN migration changes\n/* COMMIT later */\nCREATE TABLE t (id INT);");

        $this->assertSame([], $mf->lintSupabaseTransaction());
    }

    public function testLintReturnsEmptyForMissingFile(): void
    {
        $mf = new MigrationFile('20260101120000', 'gone', '/nonexistent/file.sql', '/nonexistent/down.sql');
        $this->assertSame([], $mf->lintSupabaseTransaction());
    }
}
