<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;


class PostgresAdapter implements DBAdapterInterface {

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
        $columns     = $this->fetchColumns($connection, $table);
        $keys        = $this->fetchIndexes($connection, $table);
        $constraints = $this->fetchConstraints($connection, $table);

        return [
            'engine'      => null,
            'collation'   => null,
            'columns'     => $columns,
            'keys'        => $keys,
            'constraints' => $constraints,
        ];
    }

    public function getCreateStatement(Connection $connection, string $table): string {
        $partition = $this->fetchPartitionMeta($connection, $table);

        // A partition is declared against its parent, which supplies the
        // columns, constraints and indexes. Reconstructing it as a standalone
        // CREATE TABLE produced a detached ordinary table: the rows still went
        // somewhere, but the partitioning was silently gone.
        if ($partition['is_partition']) {
            $ddl = "CREATE TABLE \"$table\" PARTITION OF \"{$partition['parent']}\" {$partition['bound']}";
            foreach ($this->fetchIndexes($connection, $table) as $idxDef) {
                $ddl .= ";\n$idxDef";
            }
            return $ddl;
        }

        // Reconstruct a CREATE TABLE statement from information_schema.
        $columns     = $this->fetchColumns($connection, $table);
        $keys        = $this->fetchIndexes($connection, $table);
        $constraints = $this->fetchConstraints($connection, $table);

        // The primary key is NOT added separately here: fetchConstraints()
        // already returns it as CONSTRAINT "<name>" PRIMARY KEY (...), because
        // its query includes constraint_type 'PRIMARY KEY'. Emitting it here as
        // well produced two PRIMARY KEY clauses in one CREATE TABLE, which
        // PostgreSQL rejects outright:
        //   ERROR: multiple primary keys for table "t" are not allowed
        // Keeping the named form (rather than a bare "PRIMARY KEY (cols)")
        // preserves the constraint name, so the table round-trips faithfully.
        $parts = array_values($columns);
        foreach ($constraints as $constraintDef) {
            $parts[] = $constraintDef;
        }

        $ddl  = "CREATE TABLE \"$table\" (\n";
        $ddl .= implode(",\n", array_map(fn($p) => "  $p", $parts));
        $ddl .= "\n)";

        // Without this the parent came out as an ordinary table and every
        // partition attached to it had nowhere to go.
        if ($partition['partition_by'] !== null) {
            // pg_get_partkeydef() returns just the strategy and key, e.g.
            // "RANGE (order_date)", so the PARTITION BY keyword is ours to add.
            $ddl .= ' PARTITION BY ' . $partition['partition_by'];
        }

        // Append CREATE INDEX statements after the main DDL
        foreach ($keys as $idxDef) {
            $ddl .= ";\n$idxDef";
        }

        return $ddl;
    }

    /**
     * Partitioning facts for a table: whether it is a partitioned parent (and
     * on what key), and whether it is itself a partition (of what, for which
     * bound). Both are empty for an ordinary table.
     */
    private function fetchPartitionMeta(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT c.relkind,
                    c.relispartition,
                    CASE WHEN c.relkind = 'p'
                         THEN pg_get_partkeydef(c.oid) END AS partition_by,
                    parent.relname AS parent,
                    CASE WHEN c.relispartition
                         THEN pg_get_expr(c.relpartbound, c.oid) END AS bound
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
               LEFT JOIN pg_inherits i ON i.inhrelid = c.oid
               LEFT JOIN pg_class parent ON parent.oid = i.inhparent
              WHERE n.nspname = 'public' AND c.relname = ?",
            [$table]
        );
        $row = $rows[0] ?? null;
        if (!$row) {
            return ['partition_by' => null, 'is_partition' => false, 'parent' => null, 'bound' => null];
        }
        return [
            'partition_by' => $row['partition_by'] ?? null,
            'is_partition' => (bool) ($row['relispartition'] ?? false),
            'parent'       => $row['parent'] ?? null,
            'bound'        => $row['bound'] ?? null,
        ];
    }

    public function getDBVariable(Connection $connection, string $variable): ?string {
        // Postgres does not have MySQL-style server variables for collation/charset
        return null;
    }

    public function getBinaryColumns(Connection $connection, string $table): array {
        // PostgreSQL bytea columns are rare in typical use; the streaming-merge
        // path handles them via ::text cast. Returning [] for now.
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
              WHERE n.nspname = 'public'"
        ) as $row) {
            $map[$row['child']][] = $row['parent'];
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function fetchColumns(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT column_name, data_type, character_maximum_length, is_nullable,
                    column_default, numeric_precision, numeric_scale, udt_name,
                    udt_schema, datetime_precision, is_identity, identity_generation,
                    is_generated, generation_expression, domain_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?
             ORDER BY ordinal_position",
            [$table]
        );

        // Map of public-schema domains that are themselves NOT NULL, so we can
        // skip a redundant column-level NOT NULL when the domain enforces it.
        $domainNotNull = [];
        foreach ($connection->select(
            "SELECT t.typname, t.typnotnull
             FROM pg_type t
             JOIN pg_namespace n ON t.typnamespace = n.oid
             WHERE n.nspname = 'public' AND t.typtype = 'd'"
        ) as $d) {
            $domainNotNull[$d['typname']] = (bool) $d['typnotnull'];
        }
        // Columns whose NOT NULL is enforced by a custom-named constraint
        // (PG18 contype='n'): emit those as a separate constraint, not inline,
        // to preserve the constraint name.
        $customNotNullCols = [];
        foreach ($this->fetchNamedNotNullConstraints($connection, $table) as $nn) {
            $customNotNullCols[$nn['column']] = true;
        }
        $serialCols = $this->fetchSerialColumns($connection, $table);

        $columns = [];
        foreach ($rows as $row) {
            $name    = $row['column_name'];
            $type    = $this->buildColumnType($row);
            // Skip redundant NOT NULL when the domain itself enforces it, or
            // when a custom-named NOT NULL constraint carries it.
            $domainName = $row['domain_name'] ?? null;
            $domainIsNotNull = $domainName && ($domainNotNull[$domainName] ?? false);
            $notNull = ($row['is_nullable'] === 'NO' && !$domainIsNotNull
                        && !isset($customNotNullCols[$name])) ? ' NOT NULL' : '';

            if ($row['is_identity'] === 'YES') {
                $gen = $row['identity_generation'] ?? 'BY DEFAULT';
                $columns[$name] = '"' . $name . '" ' . $type . $notNull
                    . ' GENERATED ' . $gen . ' AS IDENTITY';
            } elseif ($row['is_generated'] === 'ALWAYS') {
                $expr = $row['generation_expression'] ?? '';
                $columns[$name] = '"' . $name . '" ' . $type . $notNull
                    . ' GENERATED ALWAYS AS (' . $expr . ') STORED';
            } else {
                $default = '';
                if (!is_null($row['column_default'])) {
                    $default = ' DEFAULT ' . $row['column_default'];
                }

                // A serial column's default is nextval('<table>_<col>_seq'),
                // but that sequence belongs to the table and is not emitted
                // anywhere in this migration, so replaying the CREATE TABLE
                // failed with: relation "<table>_<col>_seq" does not exist.
                // Writing the column back as serial/bigserial re-creates the
                // owned sequence implicitly and restores the same default.
                $serialType = $this->serialTypeFor($row, $serialCols);
                if ($serialType !== null) {
                    $columns[$name] = '"' . $name . '" ' . $serialType;
                } else {
                    $columns[$name] = '"' . $name . '" ' . $type . $notNull . $default;
                }
            }
        }
        return $columns;
    }

    /**
     * Return 'smallserial'/'serial'/'bigserial' when this column is a genuine
     * serial: an integer, NOT NULL, defaulting to nextval() on a sequence the
     * column actually owns. Anything else (a shared or standalone sequence, a
     * nullable column) returns null and keeps its explicit DEFAULT, because
     * serial would change the semantics rather than preserve them.
     */
    private function serialTypeFor(array $row, array $serialCols): ?string {
        if (!isset($serialCols[$row['column_name']])) {
            return null;
        }
        if ($row['is_nullable'] !== 'NO') {
            return null; // serial implies NOT NULL
        }
        if (!preg_match('/^nextval\(/i', (string) $row['column_default'])) {
            return null;
        }
        return [
            'smallint' => 'smallserial',
            'integer'  => 'serial',
            'bigint'   => 'bigserial',
        ][$row['data_type']] ?? null;
    }

    /**
     * Columns that own their sequence, i.e. real serial columns.
     * pg_get_serial_sequence() returns null for a sequence the column merely
     * references, which is exactly the distinction that matters here.
     */
    private function fetchSerialColumns(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT a.attname AS column_name
               FROM pg_attribute a
               JOIN pg_class c ON c.oid = a.attrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public'
                AND c.relname = ?
                AND a.attnum > 0
                AND NOT a.attisdropped
                AND pg_get_serial_sequence(format('%I.%I', n.nspname, c.relname), a.attname) IS NOT NULL",
            [$table]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['column_name']] = true;
        }
        return $out;
    }

    /**
     * PG18+ named NOT NULL constraints (pg_constraint.contype = 'n'), excluding
     * the auto-generated "<table>_<column>_not_null" names — those are already
     * reproduced by the column's own NOT NULL. Returns [conname => column].
     */
    private function fetchNamedNotNullConstraints(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT con.conname, con.convalidated, att.attname AS column_name
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             JOIN pg_attribute att ON att.attrelid = con.conrelid
                                  AND att.attnum = con.conkey[1]
             WHERE nsp.nspname = 'public' AND rel.relname = ?
               AND con.contype = 'n'",
            [$table]
        );
        $custom = [];
        foreach ($rows as $row) {
            $default   = $table . '_' . $row['column_name'] . '_not_null';
            $isDefault = $row['conname'] === $default;
            // A default-named, validated NOT NULL is reproduced by the column's
            // own NOT NULL, so skip it. Custom names and any unvalidated (NOT
            // VALID) constraint must be emitted explicitly to round-trip.
            if (!$isDefault || !$row['convalidated']) {
                $custom[$row['conname']] = [
                    'column'   => $row['column_name'],
                    'notValid' => !$row['convalidated'],
                ];
            }
        }
        return $custom;
    }

    private function fetchIndexes(Connection $connection, string $table): array {
        // Collect names of indexes that back constraints (PK, UNIQUE, EXCLUDE)
        // so we can skip them — they're handled via fetchConstraints() instead.
        $constraintIndexes = $connection->select(
            "SELECT con.conname
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             WHERE nsp.nspname = 'public' AND rel.relname = ?
               AND con.contype IN ('p', 'u', 'x')",
            [$table]
        );
        $skip = [];
        foreach ($constraintIndexes as $row) {
            $skip[$row['conname']] = true;
        }

        $rows = $connection->select(
            "SELECT indexname, indexdef
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = ?",
            [$table]
        );

        $keys = [];
        foreach ($rows as $row) {
            if (isset($skip[$row['indexname']])) {
                continue;
            }
            $keys[$row['indexname']] = $row['indexdef'];
        }
        return $keys;
    }

    private function fetchConstraints(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT tc.constraint_name, tc.constraint_type,
                    tc.is_deferrable, tc.initially_deferred,
                    kcu.column_name, kcu.ordinal_position,
                    ccu.table_name  AS foreign_table,
                    ccu.column_name AS foreign_column,
                    rc.update_rule, rc.delete_rule, rc.match_option,
                    con.convalidated
             FROM information_schema.table_constraints tc
             LEFT JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
               AND tc.constraint_schema = kcu.constraint_schema
             LEFT JOIN information_schema.referential_constraints rc
                ON tc.constraint_name = rc.constraint_name
               AND tc.constraint_schema = rc.constraint_schema
             LEFT JOIN information_schema.constraint_column_usage ccu
                ON rc.unique_constraint_name = ccu.constraint_name
               AND rc.unique_constraint_schema = ccu.constraint_schema
             LEFT JOIN pg_constraint con
                ON tc.constraint_name = con.conname
               AND con.connamespace = 'public'::regnamespace
             WHERE tc.table_schema = 'public' AND tc.table_name = ?
               AND tc.constraint_type IN ('FOREIGN KEY', 'UNIQUE', 'PRIMARY KEY')
             ORDER BY tc.constraint_name, kcu.ordinal_position",
            [$table]
        );

        // Group by constraint name
        $groups = [];
        foreach ($rows as $row) {
            $name = $row['constraint_name'];
            if (!isset($groups[$name])) {
                $groups[$name] = $row;
                $groups[$name]['columns'] = [];
            }
            if ($row['column_name']) {
                $groups[$name]['columns'][] = $row['column_name'];
            }
        }

        $constraints = [];
        foreach ($groups as $name => $c) {
            $constraints[$name] = $this->buildConstraintDef($name, $c);
        }
        $constraints = array_filter($constraints);

        // CHECK constraints — query pg_constraint directly since
        // information_schema doesn't expose the check expression.
        $checks = $connection->select(
            "SELECT con.conname AS constraint_name,
                    pg_get_constraintdef(con.oid) AS definition
             FROM pg_constraint con
             JOIN pg_class rel ON con.conrelid = rel.oid
             JOIN pg_namespace nsp ON rel.relnamespace = nsp.oid
             WHERE nsp.nspname = 'public'
               AND rel.relname = ?
               AND con.contype IN ('c', 'x')
             ORDER BY con.conname",
            [$table]
        );

        foreach ($checks as $row) {
            $name = $row['constraint_name'];
            $constraints[$name] = "CONSTRAINT \"$name\" " . $row['definition'];
        }

        // PG18+ custom-named NOT NULL constraints (contype='n'), including the
        // unvalidated (NOT VALID) variant.
        foreach ($this->fetchNamedNotNullConstraints($connection, $table) as $name => $nn) {
            $notValid = $nn['notValid'] ? ' NOT VALID' : '';
            $constraints[$name] = "CONSTRAINT \"$name\" NOT NULL \"{$nn['column']}\"" . $notValid;
        }

        return $constraints;
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
        $simpleMap = [
            'time without time zone' => 'time',
            'time with time zone'    => 'timetz',
            'double precision'       => 'double precision',
            'ARRAY'                  => $col['udt_name'],
            // information_schema reports every enum, composite and extension
            // type as the literal string 'USER-DEFINED'; the real name is in
            // udt_name. Without this, an enum column was emitted as
            //   "status" USER-DEFINED
            // which is a syntax error, so no table using an enum could be
            // created — and enums are ubiquitous in Supabase schemas.
            'USER-DEFINED'           => $this->qualifiedUdt($col),
        ];
        return $simpleMap[$col['data_type']] ?? $this->resolveParameterisedType($col);
    }

    /**
     * Quote a user-defined type, qualifying it with its schema when it does not
     * live in public (Supabase puts extension types in "extensions", which is
     * not usually on the search_path of the session running the migration).
     */
    private function qualifiedUdt(array $col): string {
        $name   = '"' . $col['udt_name'] . '"';
        $schema = $col['udt_schema'] ?? null;
        if ($schema && !in_array($schema, ['public', 'pg_catalog'], true)) {
            return '"' . $schema . '".' . $name;
        }
        return $name;
    }

    /**
     * Resolve column types that carry length, precision, or scale parameters.
     * All other types fall through to $dataType unchanged.
     */
    private function resolveParameterisedType(array $col): string {
        $dataType = $col['data_type'];
        $result   = $dataType;

        if ($dataType === 'character varying' || $dataType === 'character') {
            $len    = $col['character_maximum_length'];
            $base   = $dataType === 'character varying' ? 'varchar' : 'char';
            $result = $len ? "$base($len)" : $base;
        } elseif ($dataType === 'numeric' || $dataType === 'decimal') {
            $p      = $col['numeric_precision'];
            $s      = $col['numeric_scale'];
            $result = ($p !== null) ? "$dataType($p,$s)" : $dataType;
        } elseif (str_starts_with($dataType, 'timestamp')) {
            $p      = $col['datetime_precision'];
            $base   = $dataType === 'timestamp with time zone' ? 'timestamptz' : 'timestamp';
            $result = ($p > 0) ? "$base($p)" : $base;
        }

        return $result;
    }
}
