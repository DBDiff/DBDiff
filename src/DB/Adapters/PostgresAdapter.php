<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use DBDiff\DB\Support\QueryHelper;


class PostgresAdapter implements DBAdapterInterface, BulkSchemaAdapterInterface {

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
        ];
    }

    public function getTables(Connection $connection): array {
        $result = $connection->select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
        );
        return Arr::pluck($result, 'tablename');
    }

    public function getColumns(Connection $connection, string $table): array {
        $result = $connection->select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?
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
               AND tc.table_schema = 'public'
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
            'columns' => [], 'keys' => [], 'constraints' => [],
        ];
    }

    public function getCreateStatement(Connection $connection, string $table): string {
        $bulk        = $this->getBulkTableSchema($connection, [$table]);
        $schema      = $bulk[$table] ?? ['columns' => [], 'keys' => [], 'constraints' => []];
        $columns     = $schema['columns'];
        $keys        = $schema['keys'];
        $constraints = $schema['constraints'];

        $pk = $this->getPrimaryKey($connection, $table);

        $parts = array_values($columns);
        if (!empty($pk)) {
            $pkCols = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk));
            $parts[] = "PRIMARY KEY ($pkCols)";
        }
        foreach ($constraints as $constraintDef) {
            $parts[] = $constraintDef;
        }

        $ddl  = "CREATE TABLE \"$table\" (\n";
        $ddl .= implode(",\n", array_map(fn($p) => "  $p", $parts));
        $ddl .= "\n)";

        foreach ($keys as $idxDef) {
            $ddl .= ";\n$idxDef";
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
             WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'"
        );
        $map = [];
        foreach ($result as $row) {
            $map[$row['table_name']][] = $row['referenced_table'];
        }
        return empty($map) ? $map : array_map(fn($p) => array_values(array_unique($p)), $map);
    }

    public function getViews(Connection $connection): array {
        $result = $connection->select(
            "SELECT viewname, definition FROM pg_views WHERE schemaname = 'public' ORDER BY viewname"
        );
        $views = [];
        foreach ($result as $row) {
            $body = rtrim(trim($row['definition']), ';');
            $views[$row['viewname']] = 'CREATE VIEW "' . $row['viewname'] . '" AS ' . $body;
        }
        return $views;
    }

    public function getTriggers(Connection $connection): array {
        $result = $connection->select(
            "SELECT t.tgname AS name, c.relname AS table_name,
                    pg_get_triggerdef(t.oid) AS definition
             FROM pg_trigger t
             JOIN pg_class c ON t.tgrelid = c.oid
             JOIN pg_namespace n ON c.relnamespace = n.oid
             WHERE NOT t.tgisinternal
               AND n.nspname = 'public'
             ORDER BY t.tgname"
        );
        $triggers = [];
        foreach ($result as $row) {
            $triggers[$row['name']] = [
                'definition' => $row['definition'],
                'table'      => $row['table_name'],
            ];
        }
        return $triggers;
    }

    public function getRoutines(Connection $connection): array {
        $result = $connection->select(
            "SELECT p.proname AS name, pg_get_functiondef(p.oid) AS definition
             FROM pg_proc p
             JOIN pg_namespace n ON p.pronamespace = n.oid
             WHERE n.nspname = 'public'
               AND p.prokind IN ('f', 'p')
             ORDER BY p.proname"
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
             WHERE n.nspname = 'public'
             GROUP BY t.typname, t.oid
             ORDER BY t.typname"
        );
        $enums = [];
        foreach ($result as $row) {
            $labels = array_map(
                fn($v) => "'" . str_replace("'", "''", $v) . "'",
                explode('||', $row['labels'])
            );
            $enums[$row['name']] = 'CREATE TYPE "' . $row['name'] . '" AS ENUM (' . implode(', ', $labels) . ')';
        }
        return $enums;
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
                            COALESCE(is_generated,'NEVER')                       || '|' ||
                            COALESCE(generation_expression,'')                   || '|' ||
                            COALESCE(domain_name,''),
                            ';' ORDER BY ordinal_position
                        ) AS col_str
                 FROM information_schema.columns
                 WHERE table_schema = 'public'
                 GROUP BY table_name
             ),
             idx_data AS (
                 SELECT tablename AS table_name,
                        string_agg(indexname || '|' || indexdef, ';' ORDER BY indexname) AS idx_str
                 FROM pg_indexes
                 WHERE schemaname = 'public'
                 GROUP BY tablename
             ),
             con_base AS (
                 SELECT tc.table_name,
                        tc.constraint_name || '|' || tc.constraint_type        || '|' ||
                        COALESCE(tc.is_deferrable,'NO')                        || '|' ||
                        COALESCE(tc.initially_deferred,'NO')                   || '|' ||
                        COALESCE(rc.update_rule,'')                            || '|' ||
                        COALESCE(rc.delete_rule,'')                            || '|' ||
                        COALESCE(rc.match_option,'')                           AS con_sig
                 FROM information_schema.table_constraints tc
                 LEFT JOIN information_schema.referential_constraints rc
                   ON tc.constraint_name  = rc.constraint_name
                  AND tc.constraint_schema = rc.constraint_schema
                 WHERE tc.table_schema = 'public'
             ),
             pg_ext_data AS (
                 SELECT c.relname AS table_name,
                        con.conname || '|' || pg_get_constraintdef(con.oid) || '|' ||
                        CASE WHEN con.convalidated THEN 'v' ELSE 'nv' END AS ext_sig
                 FROM pg_constraint con
                 JOIN pg_class c     ON con.conrelid = c.oid
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 WHERE n.nspname = 'public'
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
             )
             SELECT c.table_name,
                    md5(
                        COALESCE(c.col_str, '') || '###' ||
                        COALESCE(i.idx_str, '') || '###' ||
                        COALESCE(co.con_str,'') || '###' ||
                        COALESCE(e.ext_str, '')
                    ) AS schema_hash
             FROM col_data c
             LEFT JOIN idx_data i  ON i.table_name  = c.table_name
             LEFT JOIN con_data co ON co.table_name = c.table_name
             LEFT JOIN ext_data e  ON e.table_name  = c.table_name"
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
                    column_default, numeric_precision, numeric_scale, udt_name,
                    datetime_precision, is_identity, identity_generation,
                    is_generated, generation_expression, domain_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name IN ($ph)
             ORDER BY table_name, ordinal_position",
            $tables
        );

        $domainRows = $connection->select(
            "SELECT t.typname, t.typnotnull
             FROM pg_type t
             JOIN pg_namespace n ON t.typnamespace = n.oid
             WHERE n.nspname = 'public' AND t.typtype = 'd'"
        );
        $domainNotNull = [];
        foreach ($domainRows as $d) {
            $domainNotNull[$d['typname']] = (bool) $d['typnotnull'];
        }

        // Named NOT NULL constraints (PG18+ contype='n'). Built into two lookup
        // maps: $nnByTable for constraint DDL, $nnColsByTable for column NOT NULL
        // suppression. This single query replaces the duplicate per-table queries
        // that fetchColumns() and fetchConstraints() previously issued separately.
        $nnRows = $connection->select(
            "SELECT rel.relname AS table_name, con.conname, con.convalidated, att.attname AS column_name
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = con.conkey[1]
             WHERE nsp.nspname = 'public' AND rel.relname IN ($ph) AND con.contype = 'n'",
            $tables
        );
        $nnByTable     = [];
        $nnColsByTable = [];
        foreach ($nnRows as $r) {
            $default = $r['table_name'] . '_' . $r['column_name'] . '_not_null';
            if ($r['conname'] !== $default || !$r['convalidated']) {
                $nnByTable[$r['table_name']][$r['conname']]         = $r;
                $nnColsByTable[$r['table_name']][$r['column_name']] = true;
            }
        }

        $skipRows = $connection->select(
            "SELECT rel.relname AS table_name, con.conname
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             WHERE nsp.nspname = 'public' AND rel.relname IN ($ph)
               AND con.contype IN ('p', 'u', 'x')",
            $tables
        );
        $skipByTable = [];
        foreach ($skipRows as $r) {
            $skipByTable[$r['table_name']][$r['conname']] = true;
        }

        $idxRows = $connection->select(
            "SELECT tablename AS table_name, indexname, indexdef
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename IN ($ph)
             ORDER BY tablename, indexname",
            $tables
        );

        $conRows = $connection->select(
            "SELECT tc.table_name, tc.constraint_name, tc.constraint_type,
                    tc.is_deferrable, tc.initially_deferred,
                    kcu.column_name, kcu.ordinal_position,
                    ccu.table_name AS foreign_table, ccu.column_name AS foreign_column,
                    rc.update_rule, rc.delete_rule, rc.match_option,
                    con.convalidated
             FROM information_schema.table_constraints tc
             LEFT JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name  = kcu.constraint_name
               AND tc.constraint_schema = kcu.constraint_schema
               AND tc.table_name        = kcu.table_name
             LEFT JOIN information_schema.referential_constraints rc
                ON tc.constraint_name  = rc.constraint_name
               AND tc.constraint_schema = rc.constraint_schema
             LEFT JOIN information_schema.constraint_column_usage ccu
                ON rc.unique_constraint_name   = ccu.constraint_name
               AND rc.unique_constraint_schema  = ccu.constraint_schema
             LEFT JOIN pg_class rel
                ON rel.relname      = tc.table_name
               AND rel.relnamespace = 'public'::regnamespace
             LEFT JOIN pg_constraint con
                ON con.conname  = tc.constraint_name
               AND con.conrelid = rel.oid
             WHERE tc.table_schema = 'public' AND tc.table_name IN ($ph)
               AND tc.constraint_type IN ('FOREIGN KEY', 'UNIQUE', 'PRIMARY KEY')
             ORDER BY tc.table_name, tc.constraint_name, kcu.ordinal_position",
            $tables
        );

        $checkRows = $connection->select(
            "SELECT rel.relname AS table_name, con.conname AS constraint_name,
                    pg_get_constraintdef(con.oid) AS definition
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             WHERE nsp.nspname = 'public' AND rel.relname IN ($ph)
               AND con.contype IN ('c', 'x')
             ORDER BY rel.relname, con.conname",
            $tables
        );

        $columns     = $this->assembleColumns($colRows, $domainNotNull, $nnColsByTable);
        $keys        = $this->assembleIndexes($idxRows, $skipByTable);
        $constraints = $this->assembleConstraints($conRows, $checkRows, $nnByTable);

        $result = [];
        foreach ($tables as $t) {
            $result[$t] = [
                'engine'      => null,
                'collation'   => null,
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
    private function assembleColumns(array $colRows, array $domainNotNull, array $nnColsByTable): array
    {
        $result = [];
        foreach ($colRows as $row) {
            $tbl  = $row['table_name'];
            $name = $row['column_name'];
            if (!isset($result[$tbl])) {
                $result[$tbl] = [];
            }

            $type        = $this->buildColumnType($row);
            $domIsNotNull = $row['domain_name'] && ($domainNotNull[$row['domain_name']] ?? false);
            $notNull     = ($row['is_nullable'] === 'NO' && !$domIsNotNull
                            && !isset($nnColsByTable[$tbl][$name])) ? ' NOT NULL' : '';

            if ($row['is_identity'] === 'YES') {
                $gen = $row['identity_generation'] ?? 'BY DEFAULT';
                $result[$tbl][$name] = '"' . $name . '" ' . $type . $notNull
                    . ' GENERATED ' . $gen . ' AS IDENTITY';
                continue;
            }
            if ($row['is_generated'] === 'ALWAYS') {
                $expr = $row['generation_expression'] ?? '';
                $result[$tbl][$name] = '"' . $name . '" ' . $type . $notNull
                    . ' GENERATED ALWAYS AS (' . $expr . ') STORED';
                continue;
            }
            $default = $row['column_default'] !== null ? ' DEFAULT ' . $row['column_default'] : '';
            $result[$tbl][$name] = '"' . $name . '" ' . $type . $notNull . $default;
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
    private function assembleConstraints(array $conRows, array $checkRows, array $nnByTable): array
    {
        // Group FK/UNIQUE/PK rows by table then constraint (multi-column support)
        $groups = [];
        foreach ($conRows as $row) {
            $tbl  = $row['table_name'];
            $name = $row['constraint_name'];
            if (!isset($groups[$tbl][$name])) {
                $groups[$tbl][$name] = $row;
                $groups[$tbl][$name]['columns'] = [];
            }
            // Keyed by column name: a constraint never repeats a column, so this
            // collapses the row fan-out produced by joining key_column_usage and
            // constraint_column_usage together (an N-column FK referencing an
            // N-column key yields N×N rows). Insertion order follows
            // kcu.ordinal_position from the ORDER BY.
            if ($row['column_name']) {
                $groups[$tbl][$name]['columns'][$row['column_name']] = true;
            }
        }

        $result = [];
        foreach ($groups as $tbl => $tblGroups) {
            foreach ($tblGroups as $name => $c) {
                $c['columns'] = array_keys($c['columns']);
                // buildConstraintDef returns null for constraint types it does
                // not render; those are dropped rather than diffed as nulls.
                $def = $this->buildConstraintDef($name, $c);
                if ($def !== null) {
                    $result[$tbl][$name] = $def;
                }
            }
        }

        foreach ($checkRows as $row) {
            $result[$row['table_name']][$row['constraint_name']] =
                'CONSTRAINT "' . $row['constraint_name'] . '" ' . $row['definition'];
        }

        foreach ($nnByTable as $tbl => $nns) {
            foreach ($nns as $name => $nn) {
                $notValid = $nn['convalidated'] ? '' : ' NOT VALID';
                $result[$tbl][$name] = 'CONSTRAINT "' . $name . '" NOT NULL "' . $nn['column_name'] . '"' . $notValid;
            }
        }

        return $result;
    }

    private function buildConstraintDef(string $name, array $c): ?string {
        $defer = '';
        if (($c['is_deferrable'] ?? 'NO') === 'YES') {
            $defer = ($c['initially_deferred'] ?? 'NO') === 'YES'
                ? ' DEFERRABLE INITIALLY DEFERRED'
                : ' DEFERRABLE INITIALLY IMMEDIATE';
        }

        $notValid = '';
        if (isset($c['convalidated']) && !$c['convalidated']) {
            $notValid = ' NOT VALID';
        }

        $cols = implode('", "', $c['columns']);
        $type = $c['constraint_type'];

        if ($type === 'FOREIGN KEY') {
            $matchMap  = ['FULL' => ' MATCH FULL', 'PARTIAL' => ' MATCH PARTIAL'];
            $match     = $matchMap[$c['match_option'] ?? 'NONE'] ?? '';
            return "CONSTRAINT \"$name\" FOREIGN KEY (\"$cols\")" .
                " REFERENCES \"{$c['foreign_table']}\" (\"{$c['foreign_column']}\")" .
                $match .
                " ON UPDATE {$c['update_rule']} ON DELETE {$c['delete_rule']}" .
                $defer . $notValid;
        }

        if ($type === 'UNIQUE' || $type === 'PRIMARY KEY') {
            return "CONSTRAINT \"$name\" {$type} (\"$cols\")" . $defer;
        }

        return null;
    }

    private function buildColumnType(array $col): string {
        if (!empty($col['domain_name'])) {
            return $col['domain_name'];
        }
        $dataType  = $col['data_type'];
        $simpleMap = [
            'time without time zone' => 'time',
            'time with time zone'    => 'timetz',
            'double precision'       => 'double precision',
            'ARRAY'                  => $col['udt_name'],
        ];
        if (isset($simpleMap[$dataType])) {
            return $simpleMap[$dataType];
        }
        $result = $dataType;
        if ($dataType === 'character varying' || $dataType === 'character') {
            $base   = ['character varying' => 'varchar', 'character' => 'char'][$dataType];
            $result = $col['character_maximum_length'] ? "$base({$col['character_maximum_length']})" : $base;
        } elseif ($dataType === 'numeric' || $dataType === 'decimal') {
            $p      = $col['numeric_precision'];
            $result = ($p !== null) ? "$dataType($p,{$col['numeric_scale']})" : $dataType;
        } elseif (str_starts_with($dataType, 'timestamp')) {
            $base   = ['timestamp with time zone' => 'timestamptz'][$dataType] ?? 'timestamp';
            $result = ($col['datetime_precision'] > 0) ? "$base({$col['datetime_precision']})" : $base;
        }
        return $result;
    }
}
