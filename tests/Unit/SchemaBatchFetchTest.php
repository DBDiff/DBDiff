<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBDiff\DB\Adapters\MySQLAdapter;
use DBDiff\DB\Adapters\PostgresAdapter;
use DBDiff\DB\DBManager;
use DBDiff\DB\Schema\DBSchema;
use DBDiff\DB\Schema\TableSchema;
use DBDiff\Params\ParamsFactory;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two schema-diff performance passes:
 *
 *  - The pre-scan hash map (#179/#180), which skips tables whose schema hash
 *    matches on both sides.
 *  - The batch schema fetch (#165), which loads every remaining table in a
 *    fixed number of queries instead of one round-trip set per table.
 *
 * Both are pure wiring in DBSchema::getDiff(), so they are tested against a
 * mocked DBManager rather than a live database. The assertions that matter
 * are the negative ones: skipped tables must never be fetched, and batched
 * tables must never fall back to per-table queries.
 */
class SchemaBatchFetchTest extends TestCase
{
    protected function setUp(): void
    {
        ParamsFactory::set((object) []);
        // Logger writes straight to stdout; keep the test output readable.
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_end_clean();
        ParamsFactory::reset();
    }

    /** An empty schema in the shape the adapters return. */
    private static function emptySchema(): array
    {
        return [
            'engine' => null, 'collation' => null,
            'columns' => [], 'keys' => [], 'constraints' => [],
        ];
    }

    /**
     * Build a DBManager mock wired for a pgsql schema diff.
     *
     * @param array $sourceHashes table => hash on the source side
     * @param array $targetHashes table => hash on the target side
     */
    private function mockManager(array $sourceHashes, array $targetHashes, $adapter)
    {
        $tables = array_keys($sourceHashes);

        $manager = $this->createMock(DBManager::class);
        $manager->method('getDriver')->willReturn('pgsql');
        $manager->method('getTables')->willReturn($tables);
        $manager->method('getSchemaHashMap')->willReturnCallback(
            fn(string $conn) => $conn === 'source' ? $sourceHashes : $targetHashes
        );
        $manager->method('getAdapter')->willReturn($adapter);
        $manager->method('getDB')->willReturn($this->createMock(Connection::class));
        $manager->method('getViews')->willReturn([]);
        $manager->method('getTriggers')->willReturn([]);
        $manager->method('getRoutines')->willReturn([]);
        $manager->method('getEnums')->willReturn([]);
        $manager->method('getForeignKeyMap')->willReturn([]);

        return $manager;
    }

    // ── Pre-scan: identical tables are skipped ────────────────────────────

    public function testIdenticalTablesAreNeverFetched(): void
    {
        $adapter = $this->createMock(PostgresAdapter::class);
        // Every table hashes the same on both sides, so nothing needs a diff.
        $adapter->expects($this->never())->method('getBulkTableSchema');

        $hashes  = ['users' => 'h1', 'orders' => 'h2', 'logs' => 'h3'];
        $manager = $this->mockManager($hashes, $hashes, $adapter);
        $manager->expects($this->never())->method('getTableSchema');

        $this->assertSame([], (new DBSchema($manager))->getDiff());
    }

    public function testOnlyChangedTablesAreBatchFetched(): void
    {
        $source = ['users' => 'h1', 'orders' => 'h2', 'logs' => 'h3'];
        $target = ['users' => 'h1', 'orders' => 'CHANGED', 'logs' => 'h3'];

        $adapter = $this->createMock(PostgresAdapter::class);
        $adapter->expects($this->exactly(2))
            ->method('getBulkTableSchema')
            // The unchanged tables must not be in the batch.
            ->with($this->anything(), $this->identicalTo(['orders']))
            ->willReturn(['orders' => self::emptySchema()]);

        $manager = $this->mockManager($source, $target, $adapter);
        // Pre-fetched schemas are used, so no per-table round-trips happen.
        $manager->expects($this->never())->method('getTableSchema');

        (new DBSchema($manager))->getDiff();
    }

    public function testTableMissingFromOneHashMapIsTreatedAsChanged(): void
    {
        // A table absent from either hash map cannot be proven identical, so
        // it must be diffed rather than silently skipped.
        $source = ['users' => 'h1', 'orders' => 'h2'];
        $target = ['users' => 'h1'];

        $adapter = $this->createMock(PostgresAdapter::class);
        $adapter->expects($this->exactly(2))
            ->method('getBulkTableSchema')
            ->with($this->anything(), $this->identicalTo(['orders']))
            ->willReturn(['orders' => self::emptySchema()]);

        (new DBSchema($this->mockManager($source, $target, $adapter)))->getDiff();
    }

    public function testEmptyHashMapsDiffEveryTable(): void
    {
        // An adapter that returns no hashes (SQLite) must not cause tables to
        // be skipped — the pre-scan is an optimisation, never a filter.
        $adapter = $this->createMock(PostgresAdapter::class);
        $adapter->expects($this->exactly(2))
            ->method('getBulkTableSchema')
            ->with($this->anything(), $this->identicalTo(['users', 'orders']))
            ->willReturn([]);

        $manager = $this->createMock(DBManager::class);
        $manager->method('getDriver')->willReturn('pgsql');
        $manager->method('getTables')->willReturn(['users', 'orders']);
        $manager->method('getSchemaHashMap')->willReturn([]);
        $manager->method('getAdapter')->willReturn($adapter);
        $manager->method('getDB')->willReturn($this->createMock(Connection::class));
        $manager->method('getViews')->willReturn([]);
        $manager->method('getTriggers')->willReturn([]);
        $manager->method('getRoutines')->willReturn([]);
        $manager->method('getEnums')->willReturn([]);
        $manager->method('getForeignKeyMap')->willReturn([]);
        // Bulk returned nothing for these tables, so each falls back individually.
        $manager->expects($this->exactly(4))
            ->method('getTableSchema')
            ->willReturn(self::emptySchema());

        (new DBSchema($manager))->getDiff();
    }

    // ── Fallback for adapters without bulk support ────────────────────────

    public function testNonBulkAdapterFallsBackToPerTableFetch(): void
    {
        // MySQL already resolves a table in ~2 queries, so it does not
        // implement BulkSchemaAdapterInterface and must keep working.
        $adapter = $this->createMock(MySQLAdapter::class);
        $this->assertNotInstanceOf(
            \DBDiff\DB\Adapters\BulkSchemaAdapterInterface::class,
            $adapter
        );

        $source  = ['users' => 'h1'];
        $target  = ['users' => 'CHANGED'];
        $manager = $this->mockManager($source, $target, $adapter);
        // One call per side for the single changed table.
        $manager->expects($this->exactly(2))
            ->method('getTableSchema')
            ->willReturn(self::emptySchema());

        (new DBSchema($manager))->getDiff();
    }

    // ── The batched schema is the one actually diffed ─────────────────────

    public function testBatchedSchemaDrivesTheDiffResult(): void
    {
        $source = ['users' => 'h1'];
        $target = ['users' => 'CHANGED'];

        $sourceSchema = array_merge(self::emptySchema(), [
            'columns' => ['id' => '"id" integer', 'email' => '"email" text'],
        ]);
        $targetSchema = array_merge(self::emptySchema(), [
            'columns' => ['id' => '"id" integer'],
        ]);

        $adapter = $this->createMock(PostgresAdapter::class);
        $adapter->method('getBulkTableSchema')->willReturnOnConsecutiveCalls(
            ['users' => $sourceSchema],
            ['users' => $targetSchema]
        );

        $manager = $this->mockManager($source, $target, $adapter);
        $manager->expects($this->never())->method('getTableSchema');

        $diffs = (new DBSchema($manager))->getDiff();

        // The column present only on the source side becomes an ADD COLUMN.
        $this->assertCount(1, $diffs);
        $this->assertInstanceOf(\DBDiff\Diff\AlterTableAddColumn::class, $diffs[0]);
    }

    // ── TableSchema pre-fetched schema passthrough ────────────────────────

    public function testTableSchemaUsesPreFetchedSchemasWithoutQuerying(): void
    {
        $manager = $this->createMock(DBManager::class);
        $manager->method('getDriver')->willReturn('pgsql');
        $manager->expects($this->never())->method('getTableSchema');

        $source = array_merge(self::emptySchema(), ['columns' => ['a' => '"a" text']]);
        $target = self::emptySchema();

        $diffs = (new TableSchema($manager))->getDiff('t', $source, $target);
        $this->assertCount(1, $diffs);
    }

    public function testTableSchemaFallsBackToQueryingWhenNoSchemaProvided(): void
    {
        $manager = $this->createMock(DBManager::class);
        $manager->method('getDriver')->willReturn('pgsql');
        $manager->expects($this->exactly(2))
            ->method('getTableSchema')
            ->willReturn(self::emptySchema());

        $this->assertSame([], (new TableSchema($manager))->getDiff('t'));
    }

    public function testTableSchemaFallsBackForASingleMissingSide(): void
    {
        // A table present in the batch for one side but not the other must
        // still resolve, fetching only the side that is missing.
        $manager = $this->createMock(DBManager::class);
        $manager->method('getDriver')->willReturn('pgsql');
        $manager->expects($this->once())
            ->method('getTableSchema')
            ->willReturn(self::emptySchema());

        $diffs = (new TableSchema($manager))->getDiff('t', self::emptySchema(), null);
        $this->assertSame([], $diffs);
    }
}
