<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Parts of a PostgreSQL table its CREATE TABLE does not carry on its own:
 * what a partition declares itself, the foreign keys among tables made
 * together, and constraint names PostgreSQL would not give back.
 */
final class PostgresTableParts
{
    /**
     * What a partition declares itself rather than inherits from its parent:
     * its own constraints, as ALTER TABLE ... ADD CONSTRAINT, and its own
     * indexes. An index attached to one of the parent's, or one backing a
     * constraint, comes with that.
     *
     * @return list<string>
     */
    public static function partitionOwnDDL(Connection $connection, string $table): array {
        $name = SchemaScope::name($connection, $table);
        $rows = $connection->select(
            "WITH p AS (SELECT c.oid FROM pg_class c WHERE c.relnamespace = " . SchemaScope::oid($connection) . " AND c.relname = ?)
             SELECT 1 AS ord, k.conname AS name, quote_ident(k.conname) || ' ' || pg_get_constraintdef(k.oid) AS ddl
               FROM pg_constraint k JOIN p ON k.conrelid = p.oid
              WHERE k.conislocal AND k.coninhcount = 0 AND k.contype IN ('c', 'f', 'u', 'p', 'x')
             UNION ALL
             SELECT 2, i.relname, pg_get_indexdef(x.indexrelid)
               FROM pg_index x JOIN p ON x.indrelid = p.oid JOIN pg_class i ON i.oid = x.indexrelid
              WHERE NOT EXISTS (SELECT 1 FROM pg_inherits h WHERE h.inhrelid = x.indexrelid)
                AND NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conindid = x.indexrelid AND k.conrelid = p.oid)
             ORDER BY 1, 2",
            [$table]
        );
        return array_map(
            fn($row) => (int) $row['ord'] === 1 ? "ALTER TABLE $name ADD CONSTRAINT {$row['ddl']}" : $row['ddl'],
            $rows
        );
    }

    /**
     * The foreign keys among these tables of the schema: each one's table,
     * name, the table it references and its definition.
     *
     * @param  list<string> $tables
     * @return list<array{table: string, name: string, references: string, definition: string}>
     */
    public static function foreignKeysAmong(Connection $connection, array $tables): array {
        if ($tables === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($tables), '?'));
        $rows = $connection->select(
            "SELECT c.relname AS table, k.conname AS name, r.relname AS references,
                    'CONSTRAINT ' || quote_ident(k.conname) || ' ' || pg_get_constraintdef(k.oid) AS definition
               FROM pg_constraint k
               JOIN pg_class c ON c.oid = k.conrelid
               JOIN pg_class r ON r.oid = k.confrelid
              WHERE k.contype = 'f' AND c.relnamespace = " . SchemaScope::oid($connection) . "
                AND r.relnamespace = c.relnamespace AND c.relname IN ($in) AND r.relname IN ($in)
              ORDER BY 1, 2",
            array_merge($tables, $tables)
        );
        return array_map(fn($row) => (array) $row, $rows);
    }

    /**
     * Whether the table has a NOT NULL constraint under its default name that
     * another constraint of the schema shares — on PostgreSQL 18, a table made
     * with LIKE ... INCLUDING ALL copies its source's. Left implicit, as pg_dump
     * writes it, PostgreSQL picks the next free name instead (`t_id_not_null1`).
     */
    public static function sharesNotNullName(Connection $connection, string $table): bool
    {
        $row = $connection->selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM pg_constraint k
                  JOIN pg_class c ON c.oid = k.conrelid
                 WHERE c.relnamespace = " . SchemaScope::oid($connection) . " AND c.relname = ? AND k.contype = 'n'
                   AND EXISTS (SELECT 1 FROM pg_constraint o
                                WHERE o.connamespace = k.connamespace AND o.conname = k.conname AND o.oid <> k.oid)
             ) AS shared",
            [$table]
        );
        return (bool) ((array) $row)['shared'];
    }
}
