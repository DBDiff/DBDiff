<?php namespace DBDiff\DB\Support;

use DBDiff\DB\Adapters\BulkSchemaAdapterInterface;
use Illuminate\Database\Connection;
use WeakMap;

/**
 * The catalog facts a new table's CREATE TABLE is written from, read for every
 * such table of a diff at once.
 *
 * Rendering a table only one side has asked the catalog about it one table at
 * a time: its partitioning, its INHERITS parents, its columns, keys and
 * constraints, its owned sequences and its replica identity. That is a fixed
 * number of round trips per table, so a schema with many tables the other side
 * lacks took minutes over a link with any latency, nearly all of it spent
 * waiting. Read here for all of them up front, the count no longer grows with
 * the number of tables.
 *
 * The facts are held for one diff run only: prefetch() names the tables for a
 * connection, each kind is read for all of them when first needed, and clear()
 * drops them when the run is over. A table that was
 * not prefetched is not answered from here, so anything outside a diff run, or
 * any table the run did not name, reads the catalog exactly as before.
 *
 * Each helper keeps its own query; fetch() is what lets it answer for many
 * tables at once. A table a query returns no row for is still recorded, as
 * having none, so it is not asked about again one at a time.
 */
final class PostgresTableFacts
{
    /** Tables per query: bounds the parameter count and the statement size. */
    public const CHUNK = 1000;

    /** The bulk schema of a table the catalog returned nothing for. */
    public const NO_SCHEMA = ['columns' => [], 'keys' => [], 'constraints' => []];

    /**
     * Per connection: the tables named, each kind's reader and default, and
     * what has been read so far.
     *
     * @var WeakMap<Connection, array{tables: list<string>, named: array<string, int>, readers: array<string, callable>, defaults: array<string, mixed>, read: array<string, array<string, mixed>>}>|null
     */
    private static ?WeakMap $facts = null;

    /**
     * Name the tables whose facts this connection is to answer from here,
     * replacing whatever was held for it. Nothing is read yet: each kind is
     * read for all of them the first time one of them is asked about, in one
     * query per CHUNK tables, so a kind the run never needs costs nothing.
     *
     * @param list<string> $tables
     * @param array<string, callable(Connection, list<string>): array<string, mixed>> $readers
     *        kind => reader returning table => fact for the tables it is given
     * @param array<string, mixed> $defaults kind => the fact of a table the reader returned nothing for
     */
    public static function prefetch(Connection $connection, array $tables, array $readers, array $defaults): void
    {
        self::$facts ??= new WeakMap();
        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            unset(self::$facts[$connection]);
            return;
        }
        self::$facts[$connection] = ['tables' => $tables, 'named' => array_flip($tables), 'readers' => $readers, 'defaults' => $defaults, 'read' => []];
    }

    /**
     * The fact for this table: from what is held when the table was named to
     * prefetch(), and otherwise $read(), the caller's own single-table query,
     * whose answer is not kept.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     */
    public static function get(Connection $connection, string $kind, string $table, callable $read): mixed
    {
        $held = self::$facts[$connection] ?? null;
        if ($held === null || !isset($held['readers'][$kind]) || !isset($held['named'][$table])) {
            return $read();
        }
        if (!isset($held['read'][$kind])) {
            $facts = [];
            foreach (array_chunk($held['tables'], self::CHUNK) as $chunk) {
                $got = $held['readers'][$kind]($connection, $chunk);
                foreach ($chunk as $name) {
                    $facts[$name] = array_key_exists($name, $got) ? $got[$name] : $held['defaults'][$kind];
                }
            }
            $held['read'][$kind] = $facts;
            self::$facts[$connection] = $held;
        }
        return $held['read'][$kind][$table];
    }

    /**
     * Everything getCreateStatement() reads for these tables, the tables of a
     * diff that only this side has.
     *
     * @param list<string> $tables
     */
    public static function prefetchNewTables(BulkSchemaAdapterInterface $adapter, Connection $connection, array $tables): void
    {
        self::prefetch($connection, $tables, [
            'partitionMeta'     => fn(Connection $c, array $t) => PostgresSchemaHelper::partitionMetaFor($c, $t),
            'sharesNotNullName' => fn(Connection $c, array $t) => PostgresTableParts::sharesNotNullNameFor($c, $t),
            'inheritedFrom'     => fn(Connection $c, array $t) => PostgresTableParts::inheritedFromFor($c, $t),
            'attributeMeta'     => fn(Connection $c, array $t) => PostgresSchemaHelper::attributeMeta($c, $t),
            'relationMeta'      => fn(Connection $c, array $t) => PostgresSchemaHelper::relationMeta($c, $t),
            'standaloneColumns' => fn(Connection $c, array $t) => OwnedSequences::standaloneColumnsFor($c, $t),
            'bulkSchema'        => fn(Connection $c, array $t) => $adapter->getBulkTableSchema($c, $t),
            'relatedNames'      => fn(Connection $c, array $t) => PgDumpRenderer::relatedNamesFor($c, $t),
        ], [
            'partitionMeta'     => PostgresSchemaHelper::noPartitionMeta(),
            'sharesNotNullName' => false,
            'inheritedFrom'     => [],
            'attributeMeta'     => [],
            'relationMeta'      => null,
            'standaloneColumns' => [],
            'bulkSchema'        => self::NO_SCHEMA,
            'relatedNames'      => [],
        ]);
    }

    /** A table's attributeMeta(), from what is held when it was prefetched. */
    public static function attributes(Connection $connection, string $table): array
    {
        return self::get($connection, 'attributeMeta', $table,
            fn() => PostgresSchemaHelper::attributeMeta($connection, [$table])[$table] ?? []);
    }

    /** Drop everything held: the run is over, or a test reconnects. */
    public static function clear(): void
    {
        self::$facts = null;
    }
}
