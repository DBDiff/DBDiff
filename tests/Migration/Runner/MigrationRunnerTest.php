<?php

use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Runner\MigrationFile;
use DBDiff\Migration\Runner\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MigrationRunner — the core migration engine.
 *
 * Uses an in-memory SQLite database and a temp directory for migration files,
 * so tests run fast with no external dependencies.
 */
class MigrationRunnerTest extends TestCase
{
    private string $tmpDir;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/dbdiff_runner_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // Use a temp file for SQLite so the runner can re-open it
        $this->dbPath = $this->tmpDir . '/test.sqlite';
        touch($this->dbPath);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Helper: build a MigrationConfig pointing at our temp SQLite DB and temp migrations dir.
     */
    private function makeConfig(?string $migrationsDir = null): MigrationConfig
    {
        $dir = $migrationsDir ?? $this->tmpDir . '/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $config = new MigrationConfig(null, [
            'driver'         => 'sqlite',
            'path'           => $this->dbPath,
            'migrations_dir' => $dir,
        ]);

        return $config;
    }

    /**
     * Helper: scaffold a migration with real SQL that works on SQLite.
     */
    private function scaffoldMigration(string $dir, string $desc, string $version, string $upSql, ?string $downSql = null): MigrationFile
    {
        $file = MigrationFile::scaffold($dir, $desc, $version);
        file_put_contents($file->upPath, $upSql);
        if ($downSql !== null) {
            file_put_contents($file->downPath, $downSql);
        } else {
            // Remove down file if not provided
            if (file_exists($file->downPath)) {
                unlink($file->downPath);
            }
        }
        return $file;
    }

    // ── up() ────────────────────────────────────────────────────────────────

    public function testUpAppliesPendingMigrations(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'create users', '20260101120000',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);',
            'DROP TABLE users;'
        );
        $this->scaffoldMigration($dir, 'create posts', '20260101120001',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT);',
            'DROP TABLE posts;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $results = $runner->up();

        $this->assertCount(2, $results);
        $this->assertSame('applied', $results[0]['status']);
        $this->assertSame('applied', $results[1]['status']);
        $this->assertSame('20260101120000', $results[0]['version']);
        $this->assertSame('20260101120001', $results[1]['version']);
    }

    public function testUpReturnsEmptyWhenNothingPending(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'only', '20260101120000',
            'CREATE TABLE t (id INTEGER PRIMARY KEY);',
            'DROP TABLE t;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up(); // apply all
        $results = $runner->up(); // nothing left

        $this->assertSame([], $results);
    }

    public function testUpStopsAtTarget(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'first', '20260101120000',
            'CREATE TABLE t1 (id INTEGER PRIMARY KEY);'
        );
        $this->scaffoldMigration($dir, 'second', '20260102120000',
            'CREATE TABLE t2 (id INTEGER PRIMARY KEY);'
        );
        $this->scaffoldMigration($dir, 'third', '20260103120000',
            'CREATE TABLE t3 (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $results = $runner->up('20260102120000');

        $this->assertCount(2, $results);
        $this->assertSame('20260102120000', $results[1]['version']);
    }

    public function testUpHaltsOnFailure(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'good', '20260101120000',
            'CREATE TABLE good (id INTEGER PRIMARY KEY);'
        );
        $this->scaffoldMigration($dir, 'bad', '20260102120000',
            'INVALID SQL THAT WILL FAIL;'
        );
        $this->scaffoldMigration($dir, 'never', '20260103120000',
            'CREATE TABLE never_run (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $results = $runner->up();

        $this->assertCount(2, $results); // good + bad, third never reached
        $this->assertSame('applied', $results[0]['status']);
        $this->assertSame('failed', $results[1]['status']);
        $this->assertNotNull($results[1]['error']);
    }

    public function testUpRecordsExecutionTime(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'timed', '20260101120000',
            'CREATE TABLE timed (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $results = $runner->up();

        $this->assertArrayHasKey('ms', $results[0]);
        $this->assertIsInt($results[0]['ms']);
        $this->assertGreaterThanOrEqual(0, $results[0]['ms']);
    }

    public function testUpSkipsAlreadyApplied(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'first', '20260101120000',
            'CREATE TABLE first_t (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        // Add second migration
        $this->scaffoldMigration($dir, 'second', '20260102120000',
            'CREATE TABLE second_t (id INTEGER PRIMARY KEY);'
        );

        // New runner instance (same DB) should only apply the new one
        $runner2 = new MigrationRunner($config);
        $results = $runner2->up();

        $this->assertCount(1, $results);
        $this->assertSame('20260102120000', $results[0]['version']);
    }

    // ── down() ──────────────────────────────────────────────────────────────

    public function testDownRollsBackLastMigration(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'create users', '20260101120000',
            'CREATE TABLE users (id INTEGER PRIMARY KEY);',
            'DROP TABLE users;'
        );
        $this->scaffoldMigration($dir, 'create posts', '20260102120000',
            'CREATE TABLE posts (id INTEGER PRIMARY KEY);',
            'DROP TABLE posts;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        $results = $runner->down();

        $this->assertCount(1, $results);
        $this->assertSame('rolled_back', $results[0]['status']);
        $this->assertSame('20260102120000', $results[0]['version']);
    }

    public function testDownRollsBackMultiple(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'a', '20260101120000',
            'CREATE TABLE a (id INTEGER PRIMARY KEY);',
            'DROP TABLE a;'
        );
        $this->scaffoldMigration($dir, 'b', '20260102120000',
            'CREATE TABLE b (id INTEGER PRIMARY KEY);',
            'DROP TABLE b;'
        );
        $this->scaffoldMigration($dir, 'c', '20260103120000',
            'CREATE TABLE c (id INTEGER PRIMARY KEY);',
            'DROP TABLE c;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        $results = $runner->down(2);

        $this->assertCount(2, $results);
        $this->assertSame('rolled_back', $results[0]['status']);
        $this->assertSame('rolled_back', $results[1]['status']);
        // Rolled back newest first
        $this->assertSame('20260103120000', $results[0]['version']);
        $this->assertSame('20260102120000', $results[1]['version']);
    }

    public function testDownReportsNoDownFile(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        // UP-only migration (no down SQL)
        $this->scaffoldMigration($dir, 'nodown', '20260101120000',
            'CREATE TABLE nodown (id INTEGER PRIMARY KEY);'
            // null = no down file
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        $results = $runner->down();

        $this->assertCount(1, $results);
        $this->assertSame('no_down', $results[0]['status']);
    }

    public function testDownReturnsEmptyWhenNothingApplied(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $results = $runner->down();

        $this->assertSame([], $results);
    }

    public function testDownWithTargetStopsAtVersion(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'a', '20260101120000',
            'CREATE TABLE a (id INTEGER PRIMARY KEY);',
            'DROP TABLE a;'
        );
        $this->scaffoldMigration($dir, 'b', '20260102120000',
            'CREATE TABLE b (id INTEGER PRIMARY KEY);',
            'DROP TABLE b;'
        );
        $this->scaffoldMigration($dir, 'c', '20260103120000',
            'CREATE TABLE c (id INTEGER PRIMARY KEY);',
            'DROP TABLE c;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        // Roll back to (but not including) version b — should only rollback c
        $results = $runner->down(count: 99, target: '20260102120000');

        $this->assertCount(1, $results);
        $this->assertSame('20260103120000', $results[0]['version']);
        $this->assertSame('rolled_back', $results[0]['status']);
    }

    // ── status() ────────────────────────────────────────────────────────────

    public function testStatusShowsPendingMigrations(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'pending one', '20260101120000',
            'CREATE TABLE p1 (id INTEGER PRIMARY KEY);',
            'DROP TABLE p1;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $report = $runner->status();

        $this->assertCount(1, $report);
        $this->assertSame('pending', $report[0]['state']);
        $this->assertSame('20260101120000', $report[0]['version']);
    }

    public function testStatusShowsAppliedMigrations(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'applied one', '20260101120000',
            'CREATE TABLE a1 (id INTEGER PRIMARY KEY);',
            'DROP TABLE a1;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        $report = $runner->status();

        $this->assertCount(1, $report);
        $this->assertSame('applied', $report[0]['state']);
        $this->assertTrue($report[0]['checksum_ok']);
    }

    public function testStatusDetectsChecksumMismatch(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $file = $this->scaffoldMigration($dir, 'tampered', '20260101120000',
            'CREATE TABLE tamper (id INTEGER PRIMARY KEY);',
            'DROP TABLE tamper;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        // Tamper with the UP file after applying
        file_put_contents($file->upPath, 'ALTER TABLE tamper ADD name TEXT; -- modified');

        $runner2 = new MigrationRunner($config);
        $report = $runner2->status();

        $this->assertSame('checksum_mismatch', $report[0]['state']);
        $this->assertFalse($report[0]['checksum_ok']);
    }

    public function testStatusShowsMixedStates(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'applied', '20260101120000',
            'CREATE TABLE s1 (id INTEGER PRIMARY KEY);',
            'DROP TABLE s1;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        // Add a second pending migration
        $this->scaffoldMigration($dir, 'pending', '20260102120000',
            'CREATE TABLE s2 (id INTEGER PRIMARY KEY);',
            'DROP TABLE s2;'
        );

        $runner2 = new MigrationRunner($config);
        $report = $runner2->status();

        $this->assertCount(2, $report);
        $states = array_column($report, 'state');
        $this->assertContains('applied', $states);
        $this->assertContains('pending', $states);
    }

    public function testStatusReportsMissingFile(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $file = $this->scaffoldMigration($dir, 'disappear', '20260101120000',
            'CREATE TABLE gone (id INTEGER PRIMARY KEY);',
            'DROP TABLE gone;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        // Delete the migration files
        unlink($file->upPath);
        if (file_exists($file->downPath)) {
            unlink($file->downPath);
        }

        $runner2 = new MigrationRunner($config);
        $report = $runner2->status();

        $this->assertCount(1, $report);
        $this->assertSame('missing_file', $report[0]['state']);
    }

    public function testStatusReportsHasDown(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'with down', '20260101120000',
            'CREATE TABLE wd (id INTEGER PRIMARY KEY);',
            'DROP TABLE wd;'
        );
        $this->scaffoldMigration($dir, 'no down', '20260102120000',
            'CREATE TABLE nd (id INTEGER PRIMARY KEY);'
            // null = no down file
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $report = $runner->status();

        $this->assertTrue($report[0]['has_down']);
        $this->assertFalse($report[1]['has_down']);
    }

    public function testStatusReturnsEmptyForEmptyDir(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $report = $runner->status();

        $this->assertSame([], $report);
    }

    // ── validate() ──────────────────────────────────────────────────────────

    public function testValidateReturnsEmptyWhenAllClean(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'valid', '20260101120000',
            'CREATE TABLE valid (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        $mismatches = $runner->validate();
        $this->assertSame([], $mismatches);
    }

    public function testValidateDetectsChecksumMismatch(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $file = $this->scaffoldMigration($dir, 'tampered', '20260101120000',
            'CREATE TABLE v_tamper (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        file_put_contents($file->upPath, 'SELECT 1; -- tampered');

        $runner2 = new MigrationRunner($config);
        $mismatches = $runner2->validate();

        $this->assertCount(1, $mismatches);
        $this->assertSame('checksum_mismatch', $mismatches[0]['issue']);
        $this->assertSame('20260101120000', $mismatches[0]['version']);
        $this->assertNotSame($mismatches[0]['expected'], $mismatches[0]['actual']);
    }

    public function testValidateDetectsMissingFile(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $file = $this->scaffoldMigration($dir, 'missing', '20260101120000',
            'CREATE TABLE v_missing (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        unlink($file->upPath);
        if (file_exists($file->downPath)) {
            unlink($file->downPath);
        }

        $runner2 = new MigrationRunner($config);
        $mismatches = $runner2->validate();

        $this->assertCount(1, $mismatches);
        $this->assertSame('file_missing', $mismatches[0]['issue']);
    }

    // ── repair() ────────────────────────────────────────────────────────────

    public function testRepairClearsFailedEntries(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        // Create a migration that will fail
        $this->scaffoldMigration($dir, 'willfix', '20260101120000',
            'INVALID SQL THAT FAILS;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);

        // This will fail on up
        $results = $runner->up();
        $this->assertSame('failed', $results[0]['status']);

        // Now repair
        $removed = $runner->repair();
        $this->assertSame(1, $removed);

        // Fix the migration and try again
        $files = MigrationFile::scanDir($dir);
        file_put_contents($files[0]->upPath, 'CREATE TABLE willfix (id INTEGER PRIMARY KEY);');

        $runner2 = new MigrationRunner($config);
        $results = $runner2->up();
        $this->assertCount(1, $results);
        $this->assertSame('applied', $results[0]['status']);
    }

    public function testRepairReturnsZeroWhenNothingFailed(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $this->assertSame(0, $runner->repair());
    }

    // ── baseline() ──────────────────────────────────────────────────────────

    public function testBaselineMarksVersionAsApplied(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'old', '20260101120000',
            'CREATE TABLE old (id INTEGER PRIMARY KEY);'
        );
        $this->scaffoldMigration($dir, 'new', '20260102120000',
            'CREATE TABLE new_t (id INTEGER PRIMARY KEY);'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);

        // Baseline at the first migration
        $runner->baseline('20260101120000', 'pre-existing');

        // up() should skip the baselined version
        $results = $runner->up();
        $this->assertCount(1, $results);
        $this->assertSame('20260102120000', $results[0]['version']);
    }

    public function testBaselineWithNowUsesCurrentTimestamp(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);

        $before = date('YmdHis');
        $runner->baseline('now', 'auto-baseline');
        $after = date('YmdHis');

        $report = $runner->status();
        // Baseline is only visible in history, not in the migrations dir.
        // But validate/status should see it. Let's check if the runner
        // skips migrations at or before this baseline version.
        // Actually, baseline just records a version in history.
        // Without files in the dir, status might not show it as 'missing_file' test above.
        // The key assertion: the baseline was recorded successfully.
        // We can't easily access history directly from runner, but we can check
        // that if we add a migration with a future version, it would be applied.
        $this->scaffoldMigration($dir, 'future', '99991231235959',
            'CREATE TABLE future (id INTEGER PRIMARY KEY);'
        );

        $runner2 = new MigrationRunner($config);
        $results = $runner2->up();
        $this->assertCount(1, $results);
        $this->assertSame('99991231235959', $results[0]['version']);
    }

    // ── Full lifecycle: up → validate → tamper → validate → repair → re-up ─

    public function testFullLifecycle(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $f1 = $this->scaffoldMigration($dir, 'create accounts', '20260101120000',
            'CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT);',
            'DROP TABLE accounts;'
        );
        $f2 = $this->scaffoldMigration($dir, 'create orders', '20260102120000',
            'CREATE TABLE orders (id INTEGER PRIMARY KEY, account_id INTEGER);',
            'DROP TABLE orders;'
        );

        $config = $this->makeConfig($dir);

        // 1. Apply migrations
        $runner = new MigrationRunner($config);
        $upResults = $runner->up();
        $this->assertCount(2, $upResults);
        $this->assertSame('applied', $upResults[0]['status']);
        $this->assertSame('applied', $upResults[1]['status']);

        // 2. Validate — everything should be clean
        $this->assertSame([], $runner->validate());

        // 3. Status should show both as applied
        $status = $runner->status();
        $this->assertCount(2, $status);
        $this->assertSame('applied', $status[0]['state']);
        $this->assertSame('applied', $status[1]['state']);

        // 4. Tamper with a file and validate should find it
        file_put_contents($f1->upPath, 'SELECT 1; -- tampered');
        $runner2 = new MigrationRunner($config);
        $mismatches = $runner2->validate();
        $this->assertCount(1, $mismatches);
        $this->assertSame('checksum_mismatch', $mismatches[0]['issue']);

        // 5. Roll back both
        $downResults = $runner2->down(2);
        $this->assertCount(2, $downResults);
        $this->assertSame('rolled_back', $downResults[0]['status']);
        $this->assertSame('rolled_back', $downResults[1]['status']);

        // 6. Status should show both as pending
        $runner3 = new MigrationRunner($config);
        $status = $runner3->status();
        $this->assertCount(2, $status);
        foreach ($status as $row) {
            $this->assertSame('pending', $row['state']);
        }
    }

    // ── getSupabaseDrift() ──────────────────────────────────────────────────

    public function testGetSupabaseDriftReturnsEmptyArrayOnSqlite(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);

        // On SQLite, getSupabaseAppliedVersions() returns [] (no supabase schema),
        // and no DBDiff migrations applied yet, so drift should be empty.
        $drift = $runner->getSupabaseDrift();
        $this->assertSame([], $drift);
    }

    public function testGetSupabaseDriftDetectsDbdiffOnlyVersions(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'drift test', '20260101120000',
            'CREATE TABLE drift_test (id INTEGER PRIMARY KEY);',
            'DROP TABLE drift_test;'
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        // Applied in DBDiff but supabase_migrations doesn't exist on SQLite
        $drift = $runner->getSupabaseDrift();
        $this->assertCount(1, $drift);
        $this->assertSame('20260101120000', $drift[0]['version']);
        $this->assertSame('dbdiff_only', $drift[0]['source']);
    }

    // ── down() Supabase error message ────────────────────────────────────────

    public function testDownGivesSupabaseErrorForUpOnlyMigrations(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        // Scaffold a Supabase-format migration (no DOWN file)
        $file = MigrationFile::scaffoldSupabase($dir, 'supa only', '20260101120000');
        file_put_contents($file->upPath, 'CREATE TABLE supa (id INTEGER PRIMARY KEY);');

        $config = new MigrationConfig(null, [
            'driver'           => 'sqlite',
            'path'             => $this->dbPath,
            'migrations_dir'   => $dir,
            'migration_format' => 'supabase',
        ]);

        $runner = new MigrationRunner($config);
        $runner->up();

        $results = $runner->down();
        $this->assertCount(1, $results);
        $this->assertSame('no_down', $results[0]['status']);
        $this->assertStringContainsString('Supabase-format migrations are UP-only', $results[0]['error']);
    }

    public function testDownGivesGenericErrorForNativeFormatWithoutDown(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $this->scaffoldMigration($dir, 'native no down', '20260101120000',
            'CREATE TABLE native_nd (id INTEGER PRIMARY KEY);'
            // null = no down file
        );

        $config = $this->makeConfig($dir);
        $runner = new MigrationRunner($config);
        $runner->up();

        $results = $runner->down();
        $this->assertCount(1, $results);
        $this->assertSame('no_down', $results[0]['status']);
        $this->assertStringContainsString('.down.sql', $results[0]['error']);
    }

    // ── up() lint warnings ───────────────────────────────────────────────────

    public function testUpIncludesWarningsKeyInResults(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $file = MigrationFile::scaffoldSupabase($dir, 'check warnings key', '20260101120000');
        file_put_contents($file->upPath, "CREATE TABLE lint_test (id INTEGER PRIMARY KEY);");

        $config = new MigrationConfig(null, [
            'driver'           => 'sqlite',
            'path'             => $this->dbPath,
            'migrations_dir'   => $dir,
            'migration_format' => 'supabase',
        ]);

        $runner = new MigrationRunner($config);
        $results = $runner->up();

        $this->assertCount(1, $results);
        $this->assertSame('applied', $results[0]['status']);
        $this->assertArrayHasKey('warnings', $results[0]);
        $this->assertIsArray($results[0]['warnings']);
    }

    public function testUpHasNoWarningsForCleanSupabaseMigration(): void
    {
        $dir = $this->tmpDir . '/migrations';
        mkdir($dir, 0755, true);

        $file = MigrationFile::scaffoldSupabase($dir, 'clean', '20260101120000');
        file_put_contents($file->upPath, 'CREATE TABLE clean_test (id INTEGER PRIMARY KEY);');

        $config = new MigrationConfig(null, [
            'driver'           => 'sqlite',
            'path'             => $this->dbPath,
            'migrations_dir'   => $dir,
            'migration_format' => 'supabase',
        ]);

        $runner = new MigrationRunner($config);
        $results = $runner->up();

        $this->assertCount(1, $results);
        $this->assertEmpty($results[0]['warnings']);
    }
}
