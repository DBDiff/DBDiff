<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use DBDiff\DB\Support\QueryHelper;
use DBDiff\DB\Support\PgDumpRenderer;
use DBDiff\DB\Support\PostgresTableParts;
use DBDiff\DB\Support\PostgresColumnType;
use DBDiff\DB\Support\PostgresSchemaHelper;
use DBDiff\DB\Support\PostgresIdent;
use DBDiff\DB\Support\PostgresColumnDependants;
use DBDiff\DB\Support\SchemaScope;

class PostgresAdapter implements DBAdapterInterface, BulkSchemaAdapterInterface, ColumnDependencyAdapterInterface {

    /**
     * An index as created: a partitioned table's index renders `ON ONLY`, which
     * makes an index that stays invalid and never reaches a partition. Without
     * ONLY, PostgreSQL builds or attaches one on every partition, existing or
     * created later.
     */
    private const INDEX_DEF = "regexp_replace(indexdef, ' ON ONLY ', ' ON ')";

    /**
     * Not a partition's copy of its parent's index — PostgreSQL makes and drops
     * that with the parent's. Listed, it was created again on its own and left
     * unattached, the new partitions got none, and the DOWN dropped it after
     * the parent's index had already taken it.
     */
    private const NOT_A_PARTITIONS_COPY = "NOT EXISTS (
        SELECT 1 FROM pg_inherits inh
          JOIN pg_class ic ON ic.oid = inh.inhrelid
          JOIN pg_namespace ns ON ns.oid = ic.relnamespace
         WHERE ns.nspname = pg_indexes.schemaname AND ic.relname = pg_indexes.indexname)";

    public function buildConnectionConfig(array $server, string $db): array {
        return [
            'driver'   => 'pgsql',
            'host'     => $server['host'] ?? 'localhost',
            'port'     => $server['port'] ?? '5432',
            'database' => $db,
            'username' => $server['user'] ?? '',
            'password' => $server['password'] ?? '',
            'charset'  => 'utf8',
            'schema'   => 'public',
            'sslmode'  => $server['sslmode'] ?? 'prefer',
            'options'  => [self::disablePreparesAttribute() => true],
        ];
    }

    /**
     * PDO's attribute for skipping named server-side prepared statements.
     *
     * With PDO's defaults every catalog query costs three round trips: PREPARE,
     * EXECUTE, then DEALLOCATE. Over a link with any latency that dominates a
     * diff — the queries themselves are cheap and there are dozens of them
     * (issue #220). Disabling named prepares makes each query one round trip
     * while keeping parameters bound server-side, which
     * `PDO::ATTR_EMULATE_PREPARES` would not: that interpolates client-side,
     * and the data diff sends values through these connections too.
     *
     * PHP 8.4 moved the PDO_PGSQL constants onto a `Pdo\Pgsql` class and
     * deprecated the `PDO::PGSQL_*` spellings, so the right one is chosen at
     * runtime — the ternary keeps the deprecated form from being evaluated on a
     * version that would warn about it. Both names carry the same value.
     */
    private static function disablePreparesAttribute(): int {
        return defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            ? constant('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            : \PDO::PGSQL_ATTR_DISABLE_PREPARES;
    }

    public function getTables(Connection $connection): array {
        $result = $connection->select(
            "SELECT c.relname AS tablename
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = " . SchemaScope::literal($connection) . "
               AND c.relkind IN ('r', 'p')
               AND " . PostgresSchemaHelper::notExtensionMember('pg_class', 'c.oid') . "
             ORDER BY c.relname"
        );
        return Arr::pluck($result, 'tablename');
    }

    public function getColumns(Connection $connection, string $table): array {
        $result = $connection->select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = " . SchemaScope::literal($connection) . " AND table_name = ?
             ORDER BY ordinal_position",
            [$table]
        );
        return Arr::pluck($result, 'column_name');
    }

    public function getPrimaryKey(Connection $connection, string $table): array {
        $result = $connection->select(
            "SELECT kcu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.constraint_schema = kcu.constraint_schema
             WHERE tc.constraint_type = 'PRIMARY KEY'
               AND tc.table_schema = " . SchemaScope::literal($connection) . "
               AND tc.table_name = ?
             ORDER BY kcu.ordinal_position",
            [$table]
        );
        return Arr::pluck($result, 'column_name');
    }

    public function getTableSchema(Connection $connection, string $table): array {
        $bulk = $this->getBulkTableSchema($connection, [$table]);
        return $bulk[$table] ?? [
            'engine' => null, 'collation' => null,
            'unlogged' => false, 'reloptions' => null, 'inheritedColumns' => [], 'storage' => [],
            'columns' => [], 'keys' => [], 'constraints' => [],
        ];
    }

    public function getCreateStatement(Connection $connection, string $table, array $withoutConstraints = []): string {
        $partition = PostgresSchemaHelper::partitionMeta($connection, $table);

        if ($partition['is_partition']) {
            return PostgresTableParts::partitionDDL($connection, $table, $partition);
        }

        // pg_dump is the reference implementation and reproduces more of the
        // shared conformance corpus than the renderer below. It is used whenever
        // it is present and new enough for the server, and returns null rather
        // than throwing when it is not, so a machine without it keeps working on
        // the hand-written path.
        //
        // A partitioned parent is the exception. pg_dump renders its primary key
        // as `ALTER TABLE ONLY parent ADD CONSTRAINT ... PRIMARY KEY`, and ONLY
        // means the index reaches the partitions that exist when it runs and no
        // others. That is correct in pg_dump's own output order, where the key
        // precedes every CREATE TABLE ... PARTITION OF, and silently wrong the
        // moment anything reorders the statements — which a consumer applying a
        // fix set grouped by object kind legitimately does, creating all the
        // tables before any constraint. The partitions then never receive the
        // key, and the migration reports success having lost it.
        //
        // The renderer below emits the key inline in CREATE TABLE, where the
        // partitions inherit it however the statements are ordered.
        //
        // So is a table with a NOT NULL name pg_dump would leave implicit and
        // PostgreSQL would not give back (PostgresTableParts::sharesNotNullName).
        if ($partition['partition_by'] === null && !PostgresTableParts::sharesNotNullName($connection, $table)) {
            $viaPgDump = PgDumpRenderer::tableDDL($connection, $table, $withoutConstraints);
            if ($viaPgDump !== null) {
                return $viaPgDump;
            }
        }

        $bulk        = $this->getBulkTableSchema($connection, [$table]);
        $schema      = $bulk[$table] ?? ['columns' => [], 'keys' => [], 'constraints' => []];
        $columns     = $schema['columns'];
        $keys        = $schema['keys'];
        $constraints = $schema['constraints'];

        // The primary key is NOT added separately here: the constraint list
        // already contains it as CONSTRAINT "<name>" PRIMARY KEY (...). Emitting
        // it again produced two PRIMARY KEY clauses in one CREATE TABLE, which
        // PostgreSQL rejects outright:
        //   ERROR: multiple primary keys for table "t" are not allowed
        // Keeping the named form preserves the constraint name.
        // A child under INHERITS is written with its own columns only; the
        // parents supply the rest, and their checks.
        $parents = PostgresTableParts::inheritedFrom($connection, $table);
        if ($parents !== []) {
            $attrs   = PostgresSchemaHelper::attributeMeta($connection, [$table])[$table] ?? [];
            $columns = array_filter($columns, fn($name) => (bool) ($attrs[$name]['local'] ?? true), ARRAY_FILTER_USE_KEY);
        }
        [$inline, $notValid] = PostgresTableParts::splitConstraints($constraints, $withoutConstraints);
        $parts = array_merge(array_values($columns), $inline);

        $unlogged = $partition['unlogged'] ? 'UNLOGGED ' : '';
        $ddl  = "CREATE {$unlogged}TABLE " . SchemaScope::name($connection, $table) . " (\n";
        $ddl .= implode(",\n", array_map(fn($p) => "  $p", $parts));
        $ddl .= "\n)";
        if ($parents !== []) {
            $ddl .= ' INHERITS (' . implode(', ', $parents) . ')';
        }

        // Without this the parent came out as an ordinary table and every
        // partition attached to it had nowhere to go. pg_get_partkeydef()
        // returns only the strategy and key, e.g. "RANGE (order_date)".
        if ($partition['partition_by'] !== null) {
            $ddl .= ' PARTITION BY ' . $partition['partition_by'];
        }

        // fillfactor, autovacuum thresholds, parallel_workers and the rest are
        // part of how the table behaves, not cosmetic.
        $ddl .= PostgresSchemaHelper::withOptions($partition['reloptions']);

        foreach ($notValid as $constraintDef) {
            $ddl .= ";\nALTER TABLE " . SchemaScope::name($connection, $table) . " ADD $constraintDef";
        }

        foreach ($keys as $idxDef) {
            $ddl .= ";\n$idxDef";
        }

        // SET STORAGE only became legal inside CREATE TABLE in PostgreSQL 16,
        // so it trails the statement instead.
        foreach (PostgresSchemaHelper::storageStatements(
            SchemaScope::name($connection, $table),
            PostgresSchemaHelper::attributeMeta($connection, [$table])[$table] ?? []
        ) as $stmt) {
            $ddl .= ";\n$stmt";
        }

        return $ddl;
    }

    public function getDBVariable(Connection $connection, string $variable): ?string {
        return null;
    }

    public function getBinaryColumns(Connection $connection, string $table): array {
        return [];
    }



    public function getForeignKeyMap(Connection $connection): array {
        $result = $connection->select(
            "SELECT tc.table_name, ccu.table_name AS referenced_table
             FROM information_schema.table_constraints tc
             JOIN information_schema.constraint_column_usage ccu
               ON tc.constraint_name = ccu.constraint_name
              AND tc.constraint_schema = ccu.constraint_schema
             WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = " . SchemaScope::literal($connection) . "
               AND ccu.table_schema = tc.table_schema"
        );
        $map = [];
        foreach ($result as $row) {
            $map[$row['table_name']][] = $row['referenced_table'];
        }

        // A partition depends on its parent exactly the way a child table
        // depends on the table it references: CREATE TABLE ... PARTITION OF
        // fails if the parent does not exist yet. Feeding the edge into the
        // same map means the existing topological sort handles the ordering.
        foreach ($connection->select(
            "SELECT c.relname AS child, p.relname AS parent
               FROM pg_inherits i
               JOIN pg_class c ON c.oid = i.inhrelid
               JOIN pg_class p ON p.oid = i.inhparent
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = " . SchemaScope::literal($connection) . "
                AND p.relnamespace = c.relnamespace"
        ) as $row) {
            $map[$row['child']][] = $row['parent'];
        }
        return empty($map) ? $map : array_map(fn($p) => array_values(array_unique($p)), $map);
    }

    /**
     * Ordinary views, keyed by name.
     *
     * reloptions carries security_barrier and security_invoker — and also the
     * WITH CHECK OPTION, which PostgreSQL stores as check_option=cascaded|local
     * rather than anywhere in the view body. Without them a security_invoker
     * view was recreated as a security_definer one, silently changing whose
     * privileges the view runs under.
     *
     * The body still comes from pg_views rather than pg_get_viewdef(oid, true):
     * the two differ in layout, and the expected-SQL fixtures are recorded
     * against this one.
     */
    public function getViews(Connection $connection): array {
        $result = $connection->select(
            "SELECT v.viewname, v.definition,
                    array_to_string(c.reloptions, ', ') AS options
             FROM pg_views v
             JOIN pg_namespace n ON n.nspname = v.schemaname
             JOIN pg_class c ON c.relname = v.viewname
                            AND c.relnamespace = n.oid
                            AND c.relkind = 'v'
             WHERE v.schemaname = " . SchemaScope::literal($connection) . "
               AND " . PostgresSchemaHelper::notExtensionMember('pg_class', 'c.oid') . "
             ORDER BY v.viewname"
        );
        $views = [];
        foreach ($result as $row) {
            $body = rtrim(trim($row['definition']), ';');
            $views[$row['viewname']] = 'CREATE VIEW ' . SchemaScope::name($connection, $row['viewname'])
                . PostgresSchemaHelper::withOptions($row['options'])
                . ' AS ' . $body;
        }
        return $views;
    }

    public function getTriggers(Connection $connection): array {
        // tgparentid <> 0 marks a trigger PostgreSQL created on a partition as
        // a clone of one declared on the partitioned parent. Emitting those
        // produced a migration that creates the parent trigger — which
        // propagates to every partition — and then tries to create the
        // propagated copies as well:
        //   ERROR: trigger "after_delete_trigger" for relation
        //          "customers_americas" already exists
        // Only the parent's own declaration is ours to reproduce.
        $result = $connection->select(
            "SELECT t.tgname AS name, c.relname AS table_name,
                    pg_get_triggerdef(t.oid) AS definition
             FROM pg_trigger t
             JOIN pg_class c ON t.tgrelid = c.oid
             JOIN pg_namespace n ON c.relnamespace = n.oid
             WHERE NOT t.tgisinternal
               AND t.tgparentid = 0
               AND n.nspname = " . SchemaScope::literal($connection) . "
             ORDER BY t.tgname"
        );
        // Keyed by table and name: trigger names are unique per table, not per
        // schema, so two tables may legitimately share one. Keying on the name
        // alone silently dropped all but the last (issue #187). The bare name
        // is carried through so the emitted DDL is unaffected.
        $triggers = [];
        foreach ($result as $row) {
            $triggers[$row['table_name'] . '.' . $row['name']] = [
                'definition' => $row['definition'],
                'table'      => $row['table_name'],
                'name'       => $row['name'],
            ];
        }
        return $triggers;
    }

    /**
     * Routines keyed by signature, not bare name.
     *
     * Postgres allows overloads — several functions sharing a name with
     * different argument types. Keying on proname made them overwrite each
     * other, so N-1 overloads were invisible to the diff and which one
     * survived depended on row order, which differs between databases. Two
     * identical schemas could therefore report drift, and the generated
     * migration dropped every overload to recreate one. See issue #187.
     *
     * regprocedure renders as `cosine_distance(integer,integer)` — unique per
     * overload and stable across databases, unlike oid. Ordering by the same
     * expression keeps output deterministic where proname left ties unordered.
     */
    public function getRoutines(Connection $connection): array {
        $result = $connection->select(
            "SELECT p.oid::regprocedure::text AS name, pg_get_functiondef(p.oid) AS definition
             FROM pg_proc p
             JOIN pg_namespace n ON p.pronamespace = n.oid
             WHERE n.nspname = " . SchemaScope::literal($connection) . "
               AND p.prokind IN ('f', 'p')
               AND " . PostgresSchemaHelper::notExtensionMember('pg_proc', 'p.oid') . "
             ORDER BY p.oid::regprocedure::text"
        );
        $routines = [];
        foreach ($result as $row) {
            $routines[$row['name']] = rtrim(trim($row['definition']), ';');
        }
        return $routines;
    }

    public function getEnums(Connection $connection): array {
        $result = $connection->select(
            "SELECT t.typname AS name,
                    array_to_string(array_agg(e.enumlabel ORDER BY e.enumsortorder), '||') AS labels
             FROM pg_type t
             JOIN pg_enum e ON t.oid = e.enumtypid
             JOIN pg_namespace n ON t.typnamespace = n.oid
             WHERE n.nspname = " . SchemaScope::literal($connection) . "
               AND " . PostgresSchemaHelper::notExtensionMember('pg_type', 't.oid') . "
             GROUP BY t.typname, t.oid
             ORDER BY t.typname"
        );
        $enums = [];
        foreach ($result as $row) {
            $labels = array_map(
                fn($v) => "'" . str_replace("'", "''", $v) . "'",
                explode('||', $row['labels'])
            );
            $enums[$row['name']] = 'CREATE TYPE ' . SchemaScope::name($connection, $row['name']) . ' AS ENUM (' . implode(', ', $labels) . ')';
        }
        return $enums;
    }

    /**
     * What reads one column, so a type change can drop it and put it back.
     * See PostgresColumnDependants (issue #226).
     */
    public function getColumnDependants(Connection $connection, string $table, string $column, bool $regenerate = false): array {
        return PostgresColumnDependants::find($connection, $table, $column, $regenerate);
    }

    public function getSchemaHashMap(Connection $connection, array $tables = []): array
    {
        $rows = $connection->select(
            "WITH col_data AS (
                 SELECT table_name,
                        string_agg(
                            column_name         || '|' || data_type              || '|' ||
                            COALESCE(udt_name,'')                                || '|' ||
                            COALESCE(character_maximum_length::text,'')          || '|' ||
                            COALESCE(numeric_precision::text,'')                 || '|' ||
                            COALESCE(numeric_scale::text,'')                     || '|' ||
                            COALESCE(datetime_precision::text,'')                || '|' ||
                            COALESCE(column_default, '')                         || '|' ||
                            is_nullable                                          || '|' ||
                            COALESCE(is_identity,'NO')                           || '|' ||
                            COALESCE(identity_generation,'')                     || '|' ||
                            COALESCE(identity_start,'')                          || '|' ||
                            COALESCE(identity_increment,'')                      || '|' ||
                            COALESCE(identity_maximum,'')                        || '|' ||
                            COALESCE(identity_minimum,'')                        || '|' ||
                            COALESCE(identity_cycle,'')                          || '|' ||
                            COALESCE(is_generated,'NEVER')                       || '|' ||
                            COALESCE(generation_expression,'')                   || '|' ||
                            COALESCE(domain_name,'')                             || '|' ||
                            -- Three things information_schema cannot express, each
                            -- of which the full comparison does read, so a table
                            -- the pre-scan skipped could differ in them silently
                            -- (issue #189):
                            --
                            --   format_type  the declared type modifier.
                            --                datetime_precision is 6 for both a
                            --                bare timestamptz and a
                            --                timestamptz(6), which the comparison
                            --                now distinguishes (issue #215).
                            --   collation    a column's explicit COLLATE.
                            --
                            -- Storage and compression are compared for a table
                            -- that exists on both sides (issue #225), so they are
                            -- hashed: without them a table differing only there
                            -- was skipped by the pre-scan and never compared.
                            format_type(a.atttypid, a.atttypmod)                 || '|' ||
                            COALESCE(NULLIF(co.collname, 'default'), '')         || '|' ||
                            a.attstorage::text                                   || '|' ||
                            COALESCE(NULLIF(a.attcompression::text, ''), ''),
                            ';' ORDER BY ordinal_position
                        ) AS col_str
                 FROM information_schema.columns isc
                 JOIN pg_class cls
                   ON cls.relname = isc.table_name
                  AND cls.relnamespace = " . SchemaScope::oid($connection) . "
                 JOIN pg_attribute a
                   ON a.attrelid = cls.oid
                  AND a.attname = isc.column_name
                  AND a.attnum > 0 AND NOT a.attisdropped
                 LEFT JOIN pg_collation co ON co.oid = a.attcollation
                 WHERE isc.table_schema = " . SchemaScope::literal($connection) . "
                 GROUP BY table_name
             ),
             idx_data AS (
                 SELECT tablename AS table_name,
                        string_agg(indexname || '|' || " . self::INDEX_DEF . ", ';' ORDER BY indexname) AS idx_str
                 FROM pg_indexes
                 WHERE schemaname = " . SchemaScope::literal($connection) . " AND " . self::NOT_A_PARTITIONS_COPY . "
                 GROUP BY tablename
             ),
             -- Read from pg_constraint, not information_schema.table_constraints,
             -- for two reasons (issue #189).
             --
             -- On PostgreSQL 17 and earlier information_schema reports every
             -- NOT NULL column as a CHECK constraint named
             -- `<namespace oid>_<table oid>_<column number>_not_null`. Table
             -- OIDs differ between databases, so two identical tables hashed
             -- differently and the pre-scan skipped almost nothing when
             -- comparing two databases — which is every case it exists for. It
             -- only appeared to work when both sides were the same database.
             -- Nullability is still covered, by attnotnull in col_data.
             --
             -- And pg_get_constraintdef renders a foreign key's target table and
             -- columns, which the old signature left out: a key repointed at a
             -- different table hashed the same, so the pre-scan skipped a table
             -- that had genuinely changed. The rendering also carries the update
             -- and delete rules, the match option and deferrability, so it
             -- replaces those columns rather than adding to them.
             con_base AS (
                 SELECT c.relname AS table_name,
                        con.conname || '|' || con.contype::text || '|' ||
                        pg_get_constraintdef(con.oid) AS con_sig
                 FROM pg_constraint con
                 JOIN pg_class c     ON con.conrelid = c.oid
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 WHERE n.nspname = " . SchemaScope::literal($connection) . "
                   AND con.contype IN ('p', 'u', 'f')
             ),
             pg_ext_data AS (
                 SELECT c.relname AS table_name,
                        con.conname || '|' || pg_get_constraintdef(con.oid) || '|' ||
                        CASE WHEN con.convalidated THEN 'v' ELSE 'nv' END AS ext_sig
                 FROM pg_constraint con
                 JOIN pg_class c     ON con.conrelid = c.oid
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 WHERE n.nspname = " . SchemaScope::literal($connection) . "
                   AND con.contype IN ('c', 'x', 'n')
             ),
             con_data AS (
                 SELECT table_name,
                        string_agg(con_sig, ';' ORDER BY con_sig) AS con_str
                 FROM con_base GROUP BY table_name
             ),
             ext_data AS (
                 SELECT table_name,
                        string_agg(ext_sig, ';' ORDER BY ext_sig) AS ext_str
                 FROM pg_ext_data GROUP BY table_name
             ),
             -- Durability and storage parameters. Now compared for a table
             -- that exists on both sides, so the pre-scan must not skip a
             -- table whose only difference is one of them (issue #229).
             rel_data AS (
                 SELECT c.relname AS table_name,
                        c.relpersistence::text || '|' ||
                        COALESCE(" . PostgresSchemaHelper::canonicalReloptions('c.reloptions', ',') . ", '') AS rel_sig
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = " . SchemaScope::literal($connection) . " AND c.relkind IN ('r', 'p')
             )
             SELECT c.table_name,
                    md5(
                        COALESCE(c.col_str, '') || '###' ||
                        COALESCE(i.idx_str, '') || '###' ||
                        COALESCE(co.con_str,'') || '###' ||
                        COALESCE(e.ext_str, '') || '###' ||
                        COALESCE(r.rel_sig, '')
                    ) AS schema_hash
             FROM col_data c
             LEFT JOIN idx_data i  ON i.table_name  = c.table_name
             LEFT JOIN con_data co ON co.table_name = c.table_name
             LEFT JOIN ext_data e  ON e.table_name  = c.table_name
             LEFT JOIN rel_data r  ON r.table_name  = c.table_name"
        );

        $hashMap = [];
        foreach ($rows as $row) {
            $hashMap[$row['table_name']] = $row['schema_hash'];
        }
        return QueryHelper::restrictToTables($hashMap, $tables);
    }

    /**
     * Fetch full schema detail for all $tables in 7 fixed queries (regardless
     * of table count), then assemble per-table schema maps identical to those
     * returned by getTableSchema().
     *
     * Query budget per call:
     *   1. information_schema.columns      (all tables, one query)
     *   2. pg_type domains                 (schema-global, one query)
     *   3. pg_constraint NOT NULL (PG18+)  (all tables, one query)
     *   4. pg_constraint constraint-index names to skip
     *   5. pg_indexes                      (all tables, one query)
     *   6. information_schema FK/UNIQUE/PK (all tables, one query)
     *   7. pg_constraint CHECK/EXCLUDE     (all tables, one query)
     */
    public function getBulkTableSchema(Connection $connection, array $tables): array
    {
        if (empty($tables)) {
            return [];
        }

        $ph = QueryHelper::placeholders($tables);

        $colRows = $connection->select(
            "SELECT table_name, column_name, data_type, character_maximum_length, is_nullable,
                    column_default, numeric_precision, numeric_scale, udt_name, udt_schema,
                    -- Sequence ownership, resolved inline rather than by a second
                    -- round trip: the bulk fetch is required to stay at a constant
                    -- number of queries no matter how many tables are involved.
                    pg_get_serial_sequence(format('%I.%I', table_schema, table_name), column_name) AS owned_sequence,
                    datetime_precision, is_identity, identity_generation,
                    -- An identity column carries a sequence, and that sequence's
                    -- options are part of the column definition: recreating it
                    -- without them silently resets the counter.
                    identity_start, identity_increment, identity_maximum,
                    identity_minimum, identity_cycle,
                    is_generated, generation_expression, domain_name, domain_schema
             FROM information_schema.columns
             WHERE table_schema = " . SchemaScope::literal($connection) . " AND table_name IN ($ph)
             ORDER BY table_name, ordinal_position",
            $tables
        );

        $attrByCol = PostgresSchemaHelper::attributeMeta($connection, $tables);

        $domainNotNull = PostgresSchemaHelper::domainNotNullMap($connection);

        // Named NOT NULL constraints (PG18+ contype='n'). Collected as a flat
        // list for the constraint DDL, plus $nnColsByTable for suppressing the
        // matching inline column NOT NULL. This single query replaces the
        // duplicate per-table queries that fetchColumns() and fetchConstraints()
        // previously issued separately.
        $nnRows = $connection->select(
            "SELECT rel.relname AS table_name, con.conname, con.convalidated, att.attname AS column_name,
                    EXISTS (SELECT 1 FROM pg_constraint o
                             WHERE o.connamespace = con.connamespace AND o.conname = con.conname
                               AND o.oid <> con.oid) AS shared_name
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = con.conkey[1]
             WHERE nsp.nspname = " . SchemaScope::literal($connection) . " AND rel.relname IN ($ph) AND con.contype = 'n'",
            $tables
        );
        $namedNotNull  = [];
        $nnColsByTable = [];
        foreach ($nnRows as $r) {
            $default = $r['table_name'] . '_' . $r['column_name'] . '_not_null';
            // A default name another constraint of the schema also has is named
            // outright: left to PostgreSQL, it picks the next free one instead
            // (`t_id_not_null1`) — a table made with LIKE ... INCLUDING ALL
            // copies its source's names.
            if ($r['conname'] !== $default || !$r['convalidated'] || $r['shared_name']) {
                $namedNotNull[]                                    = $r;
                $nnColsByTable[$r['table_name']][$r['column_name']] = true;
            }
        }

        $skipRows = $connection->select(
            "SELECT rel.relname AS table_name, con.conname
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             WHERE nsp.nspname = " . SchemaScope::literal($connection) . " AND rel.relname IN ($ph)
               AND con.contype IN ('p', 'u', 'x')",
            $tables
        );
        $skipByTable = [];
        foreach ($skipRows as $r) {
            $skipByTable[$r['table_name']][$r['conname']] = true;
        }

        $idxRows = $connection->select(
            "SELECT tablename AS table_name, indexname, " . self::INDEX_DEF . " AS indexdef
             FROM pg_indexes
             WHERE schemaname = " . SchemaScope::literal($connection) . " AND tablename IN ($ph)
               AND " . self::NOT_A_PARTITIONS_COPY . "
             ORDER BY tablename, indexname",
            $tables
        );

        $conRows = PostgresTableParts::keyConstraintRows($connection, $tables);

        $checkRows = $connection->select(
            "SELECT rel.relname AS table_name, con.conname AS constraint_name,
                    pg_get_constraintdef(con.oid) AS definition
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             WHERE nsp.nspname = " . SchemaScope::literal($connection) . " AND rel.relname IN ($ph)
               AND con.contype IN ('c', 'x')
               -- A child's copy of its parent's check comes with INHERITS.
               AND con.conislocal
             ORDER BY rel.relname, con.conname",
            $tables
        );

        $columns     = $this->assembleColumns($colRows, $domainNotNull, $nnColsByTable, $attrByCol);
        $keys        = $this->assembleIndexes($idxRows, $skipByTable);
        $constraints = $this->assembleConstraints($conRows, $checkRows, $namedNotNull);

        // Durability and storage parameters, per table (issue #229).
        $relMeta = PostgresSchemaHelper::relationMeta($connection, $tables);

        $result = [];
        foreach ($tables as $t) {
            $result[$t] = [
                'engine'      => null,
                'collation'   => null,
                'storage'     => PostgresSchemaHelper::columnStorage($attrByCol[$t] ?? []),
                'inheritedColumns' => array_keys(array_filter(
                    $attrByCol[$t] ?? [],
                    fn(array $attr) => !empty($attr['inherited'])
                )),
                'unlogged'    => $relMeta[$t]['unlogged']   ?? false,
                'reloptions'  => $relMeta[$t]['reloptions'] ?? null,
                'columns'     => $columns[$t]     ?? [],
                'keys'        => $keys[$t]        ?? [],
                'constraints' => $constraints[$t] ?? [],
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build per-table column DDL maps from pre-fetched information_schema rows.
     * Returns [tableName => [columnName => ddlFragment]].
     */
    private function assembleColumns(array $colRows, array $domainNotNull, array $nnColsByTable, array $attrByCol = []): array
    {
        $result = [];
        foreach ($colRows as $row) {
            $tbl  = $row['table_name'];
            $name = $row['column_name'];
            if (!isset($result[$tbl])) {
                $result[$tbl] = [];
            }

            // Merged before the type is built, not after: buildColumnType needs
            // atttypmod from here to tell a declared precision from an absent
            // one, which information_schema cannot express (issue #215).
            $row += $attrByCol[$tbl][$name] ?? [];

            $type         = PostgresColumnType::render($row);
            $domIsNotNull = $row['domain_name'] && ($domainNotNull[$row['domain_name']] ?? false);
            $notNull      = ($row['is_nullable'] === 'NO' && !$domIsNotNull
                             && !isset($nnColsByTable[$tbl][$name])) ? ' NOT NULL' : '';

            $result[$tbl][$name] = PostgresSchemaHelper::columnDefinition($row, $type, $notNull);
        }
        return $result;
    }

    /**
     * Build per-table index DDL maps, excluding constraint-backed indexes.
     * Returns [tableName => [indexName => indexdef]].
     */
    private function assembleIndexes(array $idxRows, array $skipByTable): array
    {
        $result = [];
        foreach ($idxRows as $row) {
            $tbl = $row['table_name'];
            if (!isset($result[$tbl])) {
                $result[$tbl] = [];
            }
            if (isset($skipByTable[$tbl][$row['indexname']])) {
                continue;
            }
            $result[$tbl][$row['indexname']] = $row['indexdef'];
        }
        return $result;
    }

    /**
     * Build per-table constraint DDL maps from pre-fetched rows.
     * Covers FK/UNIQUE/PK (from information_schema), CHECK/EXCLUDE (from
     * pg_constraint), and PG18+ named NOT NULL constraints.
     * Returns [tableName => [constraintName => ddlFragment]].
     */
    private function assembleConstraints(array $conRows, array $checkRows, array $namedNotNull): array
    {
        // Group FK/UNIQUE/PK rows by table *and* constraint (multi-column
        // support). Both names go into one flat key joined by NUL, which no
        // Postgres identifier can contain, so the grouping stays unambiguous
        // without a second level of nesting. Each row keeps its own
        // table_name/constraint_name, which is what rebuilds the map below.
        $groups = [];
        foreach ($conRows as $row) {
            $key = $row['table_name'] . "\0" . $row['constraint_name'];
            if (!isset($groups[$key])) {
                $groups[$key] = $row;
                $groups[$key]['columns'] = [];
            }
            // Keyed by column name: a constraint never repeats a column, so this
            // collapses the row fan-out produced by joining key_column_usage and
            // constraint_column_usage together (an N-column FK referencing an
            // N-column key yields N×N rows). Insertion order follows
            // kcu.ordinal_position from the ORDER BY.
            if ($row['column_name']) {
                $groups[$key]['columns'][$row['column_name']] = true;
            }
        }

        $result = [];
        foreach ($groups as $c) {
            $name         = $c['constraint_name'];
            $c['columns'] = array_keys($c['columns']);
            // constraintDefinition returns null for constraint types it does
            // not render; those are dropped rather than diffed as nulls.
            $def = PostgresSchemaHelper::constraintDefinition($name, $c);
            if ($def !== null) {
                $result[$c['table_name']][$name] = $def;
            }
        }

        foreach ($checkRows as $row) {
            $result[$row['table_name']][$row['constraint_name']] =
                'CONSTRAINT ' . PostgresIdent::quote($row['constraint_name']) . ' ' . $row['definition'];
        }

        foreach ($namedNotNull as $nn) {
            $notValid = $nn['convalidated'] ? '' : ' NOT VALID';
            $result[$nn['table_name']][$nn['conname']] =
                'CONSTRAINT ' . PostgresIdent::quote($nn['conname']) . ' NOT NULL ' . PostgresIdent::quote($nn['column_name']) . $notValid;
        }

        return $result;
    }
}
