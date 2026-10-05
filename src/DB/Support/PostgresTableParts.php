<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Parts of a PostgreSQL table its CREATE TABLE does not carry on its own:
 * a partition and what it declares itself, constraints added after the
 * table, the foreign keys among tables made together, and constraint names
 * PostgreSQL would not give back.
 */
final class PostgresTableParts
{
    /**
     * A partition, declared against its parent, which supplies the columns,
     * constraints and indexes. Rebuilding it as a standalone CREATE TABLE
     * produced a detached ordinary table: rows still inserted, but the
     * partitioning was silently gone.
     *
     * What is the partition's own is added to it: a partition partitioned in
     * turn ("h_eu is not partitioned" otherwise, for its partitions), and a
     * constraint or index declared on it rather than inherited.
     */
    public static function partitionDDL(Connection $connection, string $table, array $partition): string {
        $ddl = 'CREATE TABLE ' . SchemaScope::name($connection, $table)
            . ' PARTITION OF ' . SchemaScope::name($connection, $partition['parent']) . " {$partition['bound']}"
            . ($partition['partition_by'] !== null ? ' PARTITION BY ' . $partition['partition_by'] : '');
        foreach (self::partitionOwnDDL($connection, $table) as $statement) {
            $ddl .= ";\n$statement";
        }
        return $ddl;
    }

    /**
     * The constraints written inside CREATE TABLE, and those added after it:
     * one added NOT VALID, which inside CREATE TABLE PostgreSQL validates
     * regardless, so the copy held a validated constraint the source does not.
     * Those in `$without` are left out altogether.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function splitConstraints(array $constraints, array $without): array {
        $inline = [];
        $after  = [];
        foreach (array_diff_key($constraints, array_flip($without)) as $definition) {
            if (preg_match('/\sNOT VALID$/', $definition)) {
                $after[] = $definition;
            } else {
                $inline[] = $definition;
            }
        }
        return [$inline, $after];
    }

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
     * The tables a table inherits from under INHERITS, in order, named as SQL
     * (qualified outside `public`). Empty for a partition, whose parent is
     * declared with PARTITION OF instead.
     *
     * @return list<string>
     */
    public static function inheritedFrom(Connection $connection, string $table): array
    {
        $rows = $connection->select(
            "SELECT CASE WHEN n.nspname = 'public' THEN quote_ident(p.relname)
                         ELSE quote_ident(n.nspname) || '.' || quote_ident(p.relname) END AS parent
               FROM pg_inherits i
               JOIN pg_class c ON c.oid = i.inhrelid
               JOIN pg_class p ON p.oid = i.inhparent
               JOIN pg_namespace n ON n.oid = p.relnamespace
              WHERE c.relnamespace = " . SchemaScope::oid($connection) . " AND c.relname = ? AND NOT c.relispartition
              ORDER BY i.inhseqno",
            [$table]
        );
        return array_map(fn($row) => ((array) $row)['parent'], $rows);
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
