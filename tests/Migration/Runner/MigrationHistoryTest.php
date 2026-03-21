<?php

use DBDiff\Migration\Runner\MigrationFile;
use DBDiff\Migration\Runner\MigrationHistory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MigrationHistory — the _dbdiff_migrations table manager.
 *
 * Uses an in-memory SQLite database so tests run fast and require no
 * external services.
 */
class MigrationHistoryTest extends TestCase
{
    private Connection $connection;
    private MigrationHistory $history;
    private string $tmpDir;

    protected function setUp(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ], 'history_test');
        $this->connection = $capsule->getConnection('history_test');

        $this->history = new MigrationHistory($this->connection);
        $this->history->ensureTable();

        $this->tmpDir = sys_get_temp_dir() . '/dbdiff_hist_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->tmpDir}/*") ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    // ── ensureTable ──────────────────────────────────────────────────────────

    public function testEnsureTableCreatesTable(): void
    {
        // Table should exist after setUp
        $tables = $this->connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name='_dbdiff_migrations'");
        $this->assertCount(1, $tables);
    }

    public function testEnsureTableIsIdempotent(): void
    {
        // Calling again should not throw
        $this->history->ensureTable();
        $tables = $this->connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name='_dbdiff_migrations'");
        $this->assertCount(1, $tables);
    }

    // ── recordSuccess / getApplied / getAppliedVersions ─────────────────────

    public function testRecordSuccessInsertsRow(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'create users', '20260101120000');
        $this->history->recordSuccess($file, 42);

        $applied = $this->history->getApplied();
        $this->assertCount(1, $applied);
        $this->assertSame('20260101120000', $applied[0]->version);
        $this->assertSame('create_users', $applied[0]->description);
        $this->assertSame(42, (int) $applied[0]->execution_ms);
    }

    public function testGetAppliedVersionsReturnsStrings(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'first', '20260101120000');
        $this->history->recordSuccess($file, 10);

        $versions = $this->history->getAppliedVersions();
        $this->assertSame(['20260101120000'], $versions);
    }

    public function testGetAppliedReturnsOrderedByVersion(): void
    {
        $f2 = MigrationFile::scaffold($this->tmpDir, 'second', '20260102120000');
        $f1 = MigrationFile::scaffold($this->tmpDir, 'first', '20260101120000');
        $this->history->recordSuccess($f2, 10);
        $this->history->recordSuccess($f1, 10);

        $versions = $this->history->getAppliedVersions();
        $this->assertSame(['20260101120000', '20260102120000'], $versions);
    }

    // ── recordFailure / getFailed ───────────────────────────────────────────

    public function testRecordFailureInsertsFailedRow(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'bad', '20260101130000');
        $this->history->recordFailure($file, 5);

        $failed = $this->history->getFailed();
        $this->assertCount(1, $failed);
        $this->assertSame('20260101130000', $failed[0]->version);
    }

    public function testRecordFailureUpsertsExistingEntry(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'retry', '20260101140000');
        $this->history->recordFailure($file, 5);
        $this->history->recordFailure($file, 8);

        // Should still be just one row
        $rows = $this->connection->table('_dbdiff_migrations')
            ->where('version', '20260101140000')
            ->get()->toArray();
        $this->assertCount(1, $rows);
    }

    public function testFailedNotInGetApplied(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'fail', '20260101150000');
        $this->history->recordFailure($file, 5);

        $this->assertSame([], $this->history->getAppliedVersions());
    }

    // ── has / getRecord ─────────────────────────────────────────────────────

    public function testHasReturnsFalseForMissingVersion(): void
    {
        $this->assertFalse($this->history->has('99990101000000'));
    }

    public function testHasReturnsTrueAfterInsert(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'exists', '20260101160000');
        $this->history->recordSuccess($file, 1);
        $this->assertTrue($this->history->has('20260101160000'));
    }

    public function testGetRecordReturnsNullForMissing(): void
    {
        $this->assertNull($this->history->getRecord('99990101000000'));
    }

    public function testGetRecordReturnsObject(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'rec', '20260101170000');
        $this->history->recordSuccess($file, 7);

        $record = $this->history->getRecord('20260101170000');
        $this->assertIsObject($record);
        $this->assertSame('20260101170000', $record->version);
    }

    // ── remove ──────────────────────────────────────────────────────────────

    public function testRemoveDeletesRow(): void
    {
        $file = MigrationFile::scaffold($this->tmpDir, 'del', '20260101180000');
        $this->history->recordSuccess($file, 1);
        $this->assertTrue($this->history->has('20260101180000'));

        $this->history->remove('20260101180000');
        $this->assertFalse($this->history->has('20260101180000'));
    }

    // ── repairFailed ────────────────────────────────────────────────────────

    public function testRepairFailedRemovesFailedEntries(): void
    {
        $f1 = MigrationFile::scaffold($this->tmpDir, 'good', '20260101190000');
        $f2 = MigrationFile::scaffold($this->tmpDir, 'bad1', '20260101200000');
        $f3 = MigrationFile::scaffold($this->tmpDir, 'bad2', '20260101210000');

        $this->history->recordSuccess($f1, 1);
        $this->history->recordFailure($f2, 1);
        $this->history->recordFailure($f3, 1);

        $removed = $this->history->repairFailed();
        $this->assertSame(2, $removed);

        // Good entry should still exist
        $this->assertTrue($this->history->has('20260101190000'));
        // Failed entries should be gone
        $this->assertFalse($this->history->has('20260101200000'));
        $this->assertFalse($this->history->has('20260101210000'));
    }

    public function testRepairFailedReturnsZeroWhenNothingToRepair(): void
    {
        $this->assertSame(0, $this->history->repairFailed());
    }

    // ── recordBaseline ──────────────────────────────────────────────────────

    public function testRecordBaselineInsertsEntry(): void
    {
        $this->history->recordBaseline('20260101000000', 'initial');

        $record = $this->history->getRecord('20260101000000');
        $this->assertIsObject($record);
        $this->assertSame('initial', $record->description);
        $this->assertNull($record->checksum);
    }

    public function testRecordBaselineIsIdempotent(): void
    {
        $this->history->recordBaseline('20260101000000', 'first');
        $this->history->recordBaseline('20260101000000', 'second');

        // Should keep the first description (idempotent)
        $record = $this->history->getRecord('20260101000000');
        $this->assertSame('first', $record->description);
    }

    public function testBaselineAppearsInGetApplied(): void
    {
        $this->history->recordBaseline('20260101000000');
        $versions = $this->history->getAppliedVersions();
        $this->assertContains('20260101000000', $versions);
    }
}
