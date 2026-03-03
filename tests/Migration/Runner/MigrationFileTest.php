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
}
