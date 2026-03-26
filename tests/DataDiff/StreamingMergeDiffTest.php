<?php

/**
 * Unit tests for StreamingMergeDiff.
 *
 * Uses SQLite in-memory databases so no external services are required.
 * These tests verify the streaming sorted-merge algorithm that powers
 * PostgreSQL same-server diffs and cross-server diffs for all drivers.
 */

use DBDiff\DB\Data\StreamingMergeDiff;
use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Diff\DeleteData;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;

class StreamingMergeDiffTest extends PHPUnit\Framework\TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension not loaded.');
        }
        $this->capsule = new Capsule;
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(\PDO::FETCH_ASSOC);
        });
        $this->capsule->setEventDispatcher($dispatcher);
    }

    // ── Helper: create an in-memory SQLite connection ─────────────────────

    private function createConnection(string $name, string $ddl, array $inserts = []): Connection
    {
        $this->capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ], $name);

        $conn = $this->capsule->getConnection($name);
        $conn->unprepared($ddl);
        foreach ($inserts as $sql) {
            $conn->unprepared($sql);
        }
        return $conn;
    }

    // ── Test: Identical tables produce no diff ────────────────────────────

    public function testIdenticalTablesProduceNoDiff(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, value TEXT)';
        $inserts = [
            "INSERT INTO t (id, name, value) VALUES (1, 'a', 'x')",
            "INSERT INTO t (id, name, value) VALUES (2, 'b', 'y')",
            "INSERT INTO t (id, name, value) VALUES (3, 'c', 'z')",
        ];

        $src = $this->createConnection('identical_src', $ddl, $inserts);
        $tgt = $this->createConnection('identical_tgt', $ddl, $inserts);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'name', 'value'], ['id', 'name', 'value']);

        $this->assertEmpty($diffs, 'Identical tables should produce zero diffs');
    }

    // ── Test: Detect inserts (rows in source, not in target) ──────────────

    public function testDetectsInserts(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)';
        $src = $this->createConnection('ins_src', $ddl, [
            "INSERT INTO t VALUES (1, 'a')",
            "INSERT INTO t VALUES (2, 'b')",
            "INSERT INTO t VALUES (3, 'c')",
        ]);
        $tgt = $this->createConnection('ins_tgt', $ddl, [
            "INSERT INTO t VALUES (1, 'a')",
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'name'], ['id', 'name']);

        $inserts = array_filter($diffs, fn($d) => $d instanceof InsertData);
        $this->assertCount(2, $inserts, 'Should detect 2 inserts (ids 2 and 3)');

        $insertKeys = array_map(fn($d) => $d->diff['keys']['id'], $inserts);
        sort($insertKeys);
        $this->assertEquals(['2', '3'], $insertKeys);
    }

    // ── Test: Detect deletes (rows in target, not in source) ──────────────

    public function testDetectsDeletes(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)';
        $src = $this->createConnection('del_src', $ddl, [
            "INSERT INTO t VALUES (1, 'a')",
        ]);
        $tgt = $this->createConnection('del_tgt', $ddl, [
            "INSERT INTO t VALUES (1, 'a')",
            "INSERT INTO t VALUES (2, 'b')",
            "INSERT INTO t VALUES (4, 'd')",
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'name'], ['id', 'name']);

        $deletes = array_filter($diffs, fn($d) => $d instanceof DeleteData);
        $this->assertCount(2, $deletes, 'Should detect 2 deletes (ids 2 and 4)');

        $deleteKeys = array_map(fn($d) => $d->diff['keys']['id'], $deletes);
        sort($deleteKeys);
        $this->assertEquals(['2', '4'], $deleteKeys);
    }

    // ── Test: Detect updates (same PK, different values) ──────────────────

    public function testDetectsUpdates(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, status TEXT)';
        $src = $this->createConnection('upd_src', $ddl, [
            "INSERT INTO t VALUES (1, 'a', 'active')",
            "INSERT INTO t VALUES (2, 'b', 'active')",
            "INSERT INTO t VALUES (3, 'c', 'inactive')",
        ]);
        $tgt = $this->createConnection('upd_tgt', $ddl, [
            "INSERT INTO t VALUES (1, 'a', 'active')",     // same
            "INSERT INTO t VALUES (2, 'b', 'pending')",    // status changed
            "INSERT INTO t VALUES (3, 'c_mod', 'active')", // name + status changed
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'name', 'status'], ['id', 'name', 'status']);

        $updates = array_filter($diffs, fn($d) => $d instanceof UpdateData);
        $this->assertCount(2, $updates, 'Should detect 2 updates (ids 2 and 3)');
    }

    // ── Test: Mixed inserts + deletes + updates ───────────────────────────

    public function testMixedDiffs(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)';
        $src = $this->createConnection('mix_src', $ddl, [
            "INSERT INTO t VALUES (1, 'original')",
            "INSERT INTO t VALUES (2, 'changed')",   // will be updated
            "INSERT INTO t VALUES (4, 'new_in_src')", // insert
        ]);
        $tgt = $this->createConnection('mix_tgt', $ddl, [
            "INSERT INTO t VALUES (1, 'original')",
            "INSERT INTO t VALUES (2, 'old_value')", // different value
            "INSERT INTO t VALUES (3, 'only_in_tgt')", // delete
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'val'], ['id', 'val']);

        $inserts = array_values(array_filter($diffs, fn($d) => $d instanceof InsertData));
        $deletes = array_values(array_filter($diffs, fn($d) => $d instanceof DeleteData));
        $updates = array_values(array_filter($diffs, fn($d) => $d instanceof UpdateData));

        $this->assertCount(1, $inserts, 'One insert (id=4)');
        $this->assertCount(1, $deletes, 'One delete (id=3)');
        $this->assertCount(1, $updates, 'One update (id=2)');

        $this->assertEquals('4', $inserts[0]->diff['keys']['id']);
        $this->assertEquals('3', $deletes[0]->diff['keys']['id']);
        $this->assertEquals('2', $updates[0]->diff['keys']['id']);
    }

    // ── Test: Composite primary key ───────────────────────────────────────

    public function testCompositePrimaryKey(): void
    {
        $ddl = 'CREATE TABLE t (a INTEGER, b INTEGER, val TEXT, PRIMARY KEY (a, b))';
        $src = $this->createConnection('cpk_src', $ddl, [
            "INSERT INTO t VALUES (1, 1, 'x')",
            "INSERT INTO t VALUES (1, 2, 'y_new')",  // updated
            "INSERT INTO t VALUES (2, 1, 'inserted')", // insert
        ]);
        $tgt = $this->createConnection('cpk_tgt', $ddl, [
            "INSERT INTO t VALUES (1, 1, 'x')",       // same
            "INSERT INTO t VALUES (1, 2, 'y_old')",   // update
            "INSERT INTO t VALUES (3, 1, 'deleted')",  // delete
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['a', 'b'], ['a', 'b', 'val'], ['a', 'b', 'val']);

        $inserts = array_filter($diffs, fn($d) => $d instanceof InsertData);
        $deletes = array_filter($diffs, fn($d) => $d instanceof DeleteData);
        $updates = array_filter($diffs, fn($d) => $d instanceof UpdateData);

        $this->assertCount(1, $inserts, 'One insert (a=2, b=1)');
        $this->assertCount(1, $deletes, 'One delete (a=3, b=1)');
        $this->assertCount(1, $updates, 'One update (a=1, b=2)');
    }

    // ── Test: Empty tables produce no diff ────────────────────────────────

    public function testEmptyTablesProduceNoDiff(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)';
        $src = $this->createConnection('empty_src', $ddl);
        $tgt = $this->createConnection('empty_tgt', $ddl);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'val'], ['id', 'val']);

        $this->assertEmpty($diffs);
    }

    // ── Test: Source empty, target has rows → all deletes ──────────────────

    public function testSourceEmptyTargetPopulated(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)';
        $src = $this->createConnection('srcempty_src', $ddl);
        $tgt = $this->createConnection('srcempty_tgt', $ddl, [
            "INSERT INTO t VALUES (1, 'a')",
            "INSERT INTO t VALUES (2, 'b')",
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'val'], ['id', 'val']);

        $deletes = array_filter($diffs, fn($d) => $d instanceof DeleteData);
        $this->assertCount(2, $deletes, 'All target rows should be deletes');
    }

    // ── Test: Target empty, source has rows → all inserts ─────────────────

    public function testTargetEmptySourcePopulated(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)';
        $src = $this->createConnection('tgtempty_src', $ddl, [
            "INSERT INTO t VALUES (1, 'a')",
            "INSERT INTO t VALUES (2, 'b')",
        ]);
        $tgt = $this->createConnection('tgtempty_tgt', $ddl);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'val'], ['id', 'val']);

        $inserts = array_filter($diffs, fn($d) => $d instanceof InsertData);
        $this->assertCount(2, $inserts, 'All source rows should be inserts');
    }

    // ── Test: fieldsToIgnore excludes columns from comparison ──────────────

    public function testFieldsToIgnore(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, ignored TEXT)';
        $src = $this->createConnection('ign_src', $ddl, [
            "INSERT INTO t VALUES (1, 'same', 'val_a')",
        ]);
        $tgt = $this->createConnection('ign_tgt', $ddl, [
            "INSERT INTO t VALUES (1, 'same', 'val_b')", // only ignored field differs
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');

        // Without ignore: should detect an update
        $diffs1 = $merge->getDiff('t', ['id'], ['id', 'name', 'ignored'], ['id', 'name', 'ignored']);
        $this->assertCount(1, $diffs1, 'Without fieldsToIgnore, update should be detected');

        // Re-create connections (PDO state)
        $src2 = $this->createConnection('ign_src2', $ddl, [
            "INSERT INTO t VALUES (1, 'same', 'val_a')",
        ]);
        $tgt2 = $this->createConnection('ign_tgt2', $ddl, [
            "INSERT INTO t VALUES (1, 'same', 'val_b')",
        ]);

        $merge2 = new StreamingMergeDiff($src2, $tgt2, 'sqlite');
        $diffs2 = $merge2->getDiff('t', ['id'], ['id', 'name', 'ignored'], ['id', 'name', 'ignored'], ['ignored']);
        $this->assertEmpty($diffs2, 'With fieldsToIgnore, only ignored field differs → no diff');
    }

    // ── Test: Different source/target columns (schema differs) ────────────

    public function testDifferentColumnSets(): void
    {
        $srcDdl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, old_col TEXT)';
        $tgtDdl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, new_col TEXT)';

        $src = $this->createConnection('cols_src', $srcDdl, [
            "INSERT INTO t VALUES (1, 'same', 'src_only')",
        ]);
        $tgt = $this->createConnection('cols_tgt', $tgtDdl, [
            "INSERT INTO t VALUES (1, 'same', 'tgt_only')",
        ]);

        // Common columns: id, name (old_col and new_col are not common)
        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff(
            't', ['id'],
            ['id', 'name', 'old_col'],
            ['id', 'name', 'new_col']
        );

        // Only common columns (id, name) are compared; both are same → no diff
        $this->assertEmpty($diffs, 'Only common columns compared; name is same → no diff');
    }

    // ── Test: NULL values handled correctly ────────────────────────────────

    public function testNullValues(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)';
        $src = $this->createConnection('null_src', $ddl, [
            "INSERT INTO t VALUES (1, NULL)",
            "INSERT INTO t VALUES (2, 'a')",
        ]);
        $tgt = $this->createConnection('null_tgt', $ddl, [
            "INSERT INTO t VALUES (1, NULL)",  // both NULL → same
            "INSERT INTO t VALUES (2, NULL)",  // 'a' vs NULL → update
        ]);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'val'], ['id', 'val']);

        $updates = array_filter($diffs, fn($d) => $d instanceof UpdateData);
        $this->assertCount(1, $updates, 'One update for id=2 (non-NULL vs NULL)');
    }

    // ── Test: Large dataset correctness ───────────────────────────────────

    public function testLargeDatasetCorrectness(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)';
        $rowCount = 2000; // Larger than StreamingMergeDiff::BATCH_SIZE

        $srcInserts = [];
        $tgtInserts = [];
        $expectedInserts = 0;
        $expectedDeletes = 0;
        $expectedUpdates = 0;

        for ($i = 1; $i <= $rowCount; $i++) {
            if ($i % 100 === 0) {
                // Every 100th row: only in source → insert
                $srcInserts[] = "INSERT INTO t VALUES ($i, 'src_only_$i')";
                $expectedInserts++;
            } elseif ($i % 150 === 0) {
                // Every 150th row: only in target → delete
                $tgtInserts[] = "INSERT INTO t VALUES ($i, 'tgt_only_$i')";
                $expectedDeletes++;
            } elseif ($i % 50 === 0) {
                // Every 50th row: different values → update
                $srcInserts[] = "INSERT INTO t VALUES ($i, 'new_$i')";
                $tgtInserts[] = "INSERT INTO t VALUES ($i, 'old_$i')";
                $expectedUpdates++;
            } else {
                // Same in both
                $srcInserts[] = "INSERT INTO t VALUES ($i, 'same_$i')";
                $tgtInserts[] = "INSERT INTO t VALUES ($i, 'same_$i')";
            }
        }

        $src = $this->createConnection('large_src', $ddl, $srcInserts);
        $tgt = $this->createConnection('large_tgt', $ddl, $tgtInserts);

        $merge = new StreamingMergeDiff($src, $tgt, 'sqlite');
        $diffs = $merge->getDiff('t', ['id'], ['id', 'val'], ['id', 'val']);

        $inserts = array_filter($diffs, fn($d) => $d instanceof InsertData);
        $deletes = array_filter($diffs, fn($d) => $d instanceof DeleteData);
        $updates = array_filter($diffs, fn($d) => $d instanceof UpdateData);

        $this->assertCount($expectedInserts, $inserts, "Expected $expectedInserts inserts");
        $this->assertCount($expectedDeletes, $deletes, "Expected $expectedDeletes deletes");
        $this->assertCount($expectedUpdates, $updates, "Expected $expectedUpdates updates");
    }

    // ── Test: comparePK utility ───────────────────────────────────────────

    public function testComparePKNumeric(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('pk_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('pk_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'sqlite'
        );

        $this->assertLessThan(0, $merge->comparePK(['id' => '1'], ['id' => '2'], ['id']));
        $this->assertGreaterThan(0, $merge->comparePK(['id' => '10'], ['id' => '2'], ['id']));
        $this->assertEquals(0, $merge->comparePK(['id' => '5'], ['id' => '5'], ['id']));
    }

    public function testComparePKComposite(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('cpk2_src', 'CREATE TABLE t (a INTEGER, b INTEGER, PRIMARY KEY (a, b))'),
            $this->createConnection('cpk2_tgt', 'CREATE TABLE t (a INTEGER, b INTEGER, PRIMARY KEY (a, b))'),
            'sqlite'
        );

        $this->assertLessThan(0, $merge->comparePK(['a' => '1', 'b' => '1'], ['a' => '1', 'b' => '2'], ['a', 'b']));
        $this->assertGreaterThan(0, $merge->comparePK(['a' => '2', 'b' => '1'], ['a' => '1', 'b' => '9'], ['a', 'b']));
        $this->assertEquals(0, $merge->comparePK(['a' => '1', 'b' => '1'], ['a' => '1', 'b' => '1'], ['a', 'b']));
    }

    // ── Test: buildHashExpression generates valid SQL ──────────────────────

    public function testBuildHashExpressionSQLite(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('hash_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('hash_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'sqlite'
        );

        $expr = $merge->buildHashExpression(['col1', 'col2']);
        $this->assertStringContainsString('hex(', $expr);
        $this->assertStringContainsString('COALESCE', $expr);
        $this->assertStringContainsString("X'1f'", $expr);
    }

    public function testBuildHashExpressionPostgres(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('hashpg_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('hashpg_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'pgsql'
        );

        $expr = $merge->buildHashExpression(['col1', 'col2']);
        $this->assertStringContainsString('md5(', $expr);
        $this->assertStringContainsString('COALESCE', $expr);
    }

    public function testBuildHashExpressionMySQL(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('hashmy_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('hashmy_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'mysql'
        );

        $expr = $merge->buildHashExpression(['col1', 'col2']);
        $this->assertStringContainsString('SHA2(', $expr);
        $this->assertStringContainsString('CONCAT(', $expr);
        // NULL-safety: each column must be wrapped in COALESCE so a single NULL
        // column does not collapse the entire hash expression to NULL and cause
        // changed rows to be silently skipped.
        $this->assertStringContainsString('COALESCE(', $expr);
        // Column separator: CHAR(31) (unit separator, 0x1f) prevents cross-column
        // hash collisions where different value distributions produce the same string.
        $this->assertStringContainsString('CHAR(31)', $expr);
    }

    public function testBuildHashExpressionEmpty(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('hashempty_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('hashempty_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'sqlite'
        );

        $this->assertEquals("'empty'", $merge->buildHashExpression([]));
    }

    // ── Test: buildPKString ───────────────────────────────────────────────

    public function testBuildPKString(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('pks_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('pks_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'sqlite'
        );

        $this->assertEquals('42', $merge->buildPKString(['id' => 42], ['id']));
        $this->assertEquals("1\x002", $merge->buildPKString(['a' => 1, 'b' => 2], ['a', 'b']));
    }

    // ── Test: quoteIdentifier ─────────────────────────────────────────────

    public function testQuoteIdentifierSQLite(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('qi_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('qi_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'sqlite'
        );
        $this->assertEquals('"my_table"', $merge->quoteIdentifier('my_table'));
    }

    public function testQuoteIdentifierMySQL(): void
    {
        $merge = new StreamingMergeDiff(
            $this->createConnection('qimy_src', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            $this->createConnection('qimy_tgt', 'CREATE TABLE t (id INTEGER PRIMARY KEY)'),
            'mysql'
        );
        $this->assertEquals('`my_table`', $merge->quoteIdentifier('my_table'));
    }
}
