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
        $named = self::partitionNotNullNames($connection, $table);
        $ddl = 'CREATE TABLE ' . SchemaScope::name($connection, $table)
            . ' PARTITION OF ' . self::parentName($connection, $partition)
            . ($named !== [] ? ' (' . implode(', ', $named) . ')' : '')
            . " {$partition['bound']}"
            . ($partition['partition_by'] !== null ? ' PARTITION BY ' . $partition['partition_by'] : '');
        $statements = array_merge(
            self::partitionOwnDDL($connection, $table),
            PostgresPartitionIndexes::newPartitionStatements($connection, $table)
        );
        foreach ($statements as $statement) {
            $ddl .= ";\n$statement";
        }
        return $ddl;
    }

    /**
     * NOT NULL constraints a partition names other than PARTITION OF would.
     *
     * Since PostgreSQL 18 a NOT NULL is a named constraint. A partition made
     * with PARTITION OF takes its parent's names, while one created on its own
     * and then attached keeps its own (payment_p2022_01_customer_id_not_null),
     * and PostgreSQL will not rename an inherited constraint afterwards. Named
     * in PARTITION OF's column list, they come out as the source has them.
     * Before 18 there are none, so nothing is added.
     *
     * @return list<string> column constraint clauses: `col CONSTRAINT name NOT NULL`
     */
    private static function partitionNotNullNames(Connection $connection, string $table): array {
        $rows = $connection->select(
            "WITH p AS (SELECT c.oid FROM pg_class c WHERE c.relnamespace = " . SchemaScope::oid($connection) . " AND c.relname = ?)
             SELECT quote_ident(a.attname) || ' CONSTRAINT ' || quote_ident(k.conname) || ' NOT NULL' AS clause
               FROM pg_constraint k
               JOIN p ON k.conrelid = p.oid
               JOIN pg_attribute a ON a.attrelid = k.conrelid AND a.attnum = k.conkey[1]
               LEFT JOIN pg_inherits h ON h.inhrelid = p.oid
               LEFT JOIN pg_attribute pa ON pa.attrelid = h.inhparent AND pa.attname = a.attname AND NOT pa.attisdropped
               LEFT JOIN pg_constraint pk ON pk.conrelid = h.inhparent AND pk.contype = 'n' AND pk.conkey[1] = pa.attnum
              WHERE k.contype = 'n' AND k.conname IS DISTINCT FROM pk.conname
              ORDER BY a.attnum",
            [$table]
        );
        return array_map(fn($row) => (string) ((array) $row)['clause'], $rows);
    }

    /**
     * The parent, named in its own schema. A partition need not live beside
     * its parent — GitLab keeps hash partitions of `public` tables in a
     * schema of their own — and naming the parent in the partition's schema
     * asked for a relation that does not exist.
     */
    private static function parentName(Connection $connection, array $partition): string {
        $schema = $partition['parent_schema'] ?? null;
        if ($schema === null) {
            return SchemaScope::name($connection, $partition['parent']);
        }
        $quoted = PostgresIdent::quote($partition['parent']);
        return $schema === 'public' ? $quoted : PostgresIdent::quote($schema) . '.' . $quoted;
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
     * The primary key, unique and foreign key constraints of these tables,
     * one row per constraint column, for assembleConstraints(), read straight
     * from pg_catalog.
     *
     * The information_schema equivalent (table_constraints joined to
     * key_column_usage, referential_constraints and constraint_column_usage)
     * is ~900x slower: those views wrap the catalogs in per-row privilege
     * checks, which stops the planner pushing `relname IN (...)` down, so
     * they are largely materialised before the filter applies. On a
     * 1000-table database that single join was 99% of the whole batch
     * fetch — 8.4s against 9ms here. See issue #184.
     *
     * The CASE arms reproduce exactly what the information_schema views
     * emit, so the assembled DDL is unchanged. Note Postgres maps simple
     * match ('s') to 'NONE', not 'SIMPLE'.
     *
     * @param list<string> $tables
     * @return array<int, array<string, mixed>>
     */
    public static function keyConstraintRows(Connection $connection, array $tables): array
    {
        $ph = QueryHelper::placeholders($tables);
        return $connection->select(
            "SELECT rel.relname AS table_name,
                    con.conname AS constraint_name,
                    CASE con.contype
                         WHEN 'f' THEN 'FOREIGN KEY'
                         WHEN 'u' THEN 'UNIQUE'
                         ELSE 'PRIMARY KEY'
                    END AS constraint_type,
                    CASE WHEN con.condeferrable THEN 'YES' ELSE 'NO' END AS is_deferrable,
                    CASE WHEN con.condeferred   THEN 'YES' ELSE 'NO' END AS initially_deferred,
                    att.attname  AS column_name,
                    cols.ord     AS ordinal_position,
                    frel.relname AS foreign_table,
                    fnsp.nspname AS foreign_schema,
                    -- Every referenced column, in key order: reading only
                    -- confkey[1] rendered a two-column key as REFERENCES p (a).
                    (SELECT json_agg(fa.attname ORDER BY fk.ord)
                       FROM unnest(con.confkey) WITH ORDINALITY AS fk(attnum, ord)
                       JOIN pg_attribute fa ON fa.attrelid = con.confrelid AND fa.attnum = fk.attnum
                    ) AS foreign_columns,
                    CASE con.confupdtype WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL'
                                         WHEN 'd' THEN 'SET DEFAULT' WHEN 'r' THEN 'RESTRICT'
                                         WHEN 'a' THEN 'NO ACTION' END AS update_rule,
                    CASE con.confdeltype WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL'
                                         WHEN 'd' THEN 'SET DEFAULT' WHEN 'r' THEN 'RESTRICT'
                                         WHEN 'a' THEN 'NO ACTION' END AS delete_rule,
                    CASE con.confmatchtype WHEN 'f' THEN 'FULL' WHEN 'p' THEN 'PARTIAL'
                                           WHEN 's' THEN 'NONE' END AS match_option,
                    con.convalidated,
                    -- PostgreSQL 15+, read through to_jsonb so 14, which has
                    -- neither column, reads null: a UNIQUE NULLS NOT DISTINCT,
                    -- and the columns ON DELETE SET NULL (b) / SET DEFAULT sets.
                    (SELECT (to_jsonb(i) ->> 'indnullsnotdistinct')::boolean
                       FROM pg_index i WHERE i.indexrelid = con.conindid) AS nulls_not_distinct,
                    (SELECT json_agg(sa.attname ORDER BY s.ord)
                       FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(to_jsonb(con) -> 'confdelsetcols') = 'array'
                                                           THEN to_jsonb(con) -> 'confdelsetcols' ELSE '[]' END)
                            WITH ORDINALITY AS s(attnum, ord)
                       JOIN pg_attribute sa ON sa.attrelid = con.conrelid AND sa.attnum = s.attnum::int
                    ) AS delete_set_columns
             FROM pg_constraint con
             JOIN pg_class rel     ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             LEFT JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS cols(attnum, ord) ON TRUE
             LEFT JOIN pg_attribute att  ON att.attrelid  = con.conrelid
                                        AND att.attnum    = cols.attnum
             LEFT JOIN pg_class frel     ON con.confrelid = frel.oid
             LEFT JOIN pg_namespace fnsp ON fnsp.oid = frel.relnamespace
             WHERE nsp.nspname = " . SchemaScope::literal($connection) . " AND rel.relname IN ($ph)
               AND con.contype IN ('f', 'u', 'p')
               -- Not the copies PostgreSQL makes of a foreign key onto a
               -- partitioned table, one per partition: they come with it, and
               -- rendered too, the table failed with \"constraint already exists\".
               AND con.conparentid = 0
             ORDER BY rel.relname, con.conname, cols.ord",
            $tables
        );
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
