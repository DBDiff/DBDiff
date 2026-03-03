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
        // Reconstruct a CREATE TABLE statement from information_schema.
        $columns     = $this->fetchColumns($connection, $table);
        $keys        = $this->fetchIndexes($connection, $table);
        $constraints = $this->fetchConstraints($connection, $table);

        // Primary key
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

        // Append CREATE INDEX statements after the main DDL
        foreach ($keys as $idxDef) {
            $ddl .= ";\n$idxDef";
        }

        return $ddl;
    }

    public function getDBVariable(Connection $connection, string $variable): ?string {
        // Postgres does not have MySQL-style server variables for collation/charset
        return null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function fetchColumns(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT column_name, data_type, character_maximum_length, is_nullable,
                    column_default, numeric_precision, numeric_scale, udt_name,
                    datetime_precision
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?
             ORDER BY ordinal_position",
            [$table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $name    = $row['column_name'];
            $type    = $this->buildColumnType($row);
            $notNull = ($row['is_nullable'] === 'NO') ? ' NOT NULL' : '';
            $default = '';
            if (!is_null($row['column_default'])) {
                $default = ' DEFAULT ' . $row['column_default'];
            }
            $columns[$name] = '"' . $name . '" ' . $type . $notNull . $default;
        }
        return $columns;
    }

    private function fetchIndexes(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT indexname, indexdef
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = ?",
            [$table]
        );

        $keys = [];
        foreach ($rows as $row) {
            // Skip primary key indexes – they are captured as constraints
            if (substr($row['indexname'], -5) === '_pkey') {
                continue;
            }
            $keys[$row['indexname']] = $row['indexdef'];
        }
        return $keys;
    }

    private function fetchConstraints(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT tc.constraint_name, tc.constraint_type,
                    kcu.column_name, kcu.ordinal_position,
                    ccu.table_name  AS foreign_table,
                    ccu.column_name AS foreign_column,
                    rc.update_rule, rc.delete_rule
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
             WHERE tc.table_schema = 'public' AND tc.table_name = ?
               AND tc.constraint_type IN ('FOREIGN KEY', 'UNIQUE')
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
            if ($c['constraint_type'] === 'FOREIGN KEY') {
                $cols = implode('", "', $c['columns']);
                $constraints[$name] =
                    "CONSTRAINT \"$name\" FOREIGN KEY (\"$cols\")" .
                    " REFERENCES \"{$c['foreign_table']}\" (\"{$c['foreign_column']}\")" .
                    " ON UPDATE {$c['update_rule']} ON DELETE {$c['delete_rule']}";
            } elseif ($c['constraint_type'] === 'UNIQUE') {
                $cols = implode('", "', $c['columns']);
                $constraints[$name] = "CONSTRAINT \"$name\" UNIQUE (\"$cols\")";
            }
        }

        return $constraints;
    }

    private function buildColumnType(array $col): string {
        $simpleMap = [
            'time without time zone' => 'time',
            'time with time zone'    => 'timetz',
            'double precision'       => 'double precision',
            'ARRAY'                  => $col['udt_name'],
        ];
        return $simpleMap[$col['data_type']] ?? $this->resolveParameterisedType($col);
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
