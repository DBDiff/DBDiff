<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;


class SQLiteAdapter implements DBAdapterInterface {

    public function buildConnectionConfig(array $server, string $db): array {
        return [
            'driver'   => 'sqlite',
            'database' => $db,   // absolute path to the .db file
            'prefix'   => '',
        ];
    }

    public function getTables(Connection $connection): array {
        $result = $connection->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );
        return Arr::pluck($result, 'name');
    }

    public function getColumns(Connection $connection, string $table): array {
        $rows = $connection->select("PRAGMA table_info(\"$table\")");
        return Arr::pluck($rows, 'name');
    }

    public function getPrimaryKey(Connection $connection, string $table): array {
        $rows = $connection->select("PRAGMA table_info(\"$table\")");
        $pk   = [];
        foreach ($rows as $row) {
            if ($row['pk'] > 0) {
                $pk[$row['pk']] = $row['name'];
            }
        }
        ksort($pk);
        return array_values($pk);
    }

    public function getTableSchema(Connection $connection, string $table): array {
        $columns     = $this->fetchColumns($connection, $table);
        $keys        = $this->fetchIndexes($connection, $table);
        $constraints = $this->fetchForeignKeys($connection, $table);

        return [
            'engine'      => null,
            'collation'   => null,
            'columns'     => $columns,
            'keys'        => $keys,
            'constraints' => $constraints,
        ];
    }

    public function getCreateStatement(Connection $connection, string $table): string {
        $result = $connection->select(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );
        $ddl = $result[0]['sql'] ?? "-- Could not retrieve CREATE TABLE for \"$table\"";

        // Also append CREATE INDEX statements
        $indexes = $connection->select(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
            [$table]
        );
        foreach ($indexes as $idx) {
            $ddl .= ";\n" . $idx['sql'];
        }

        return $ddl;
    }

    public function getDBVariable(Connection $connection, string $variable): ?string {
        // SQLite has no server-level collation / charset concept.
        return null;
    }

    public function getBinaryColumns(Connection $connection, string $table): array {
        // SQLite stores all data as text; no binary-encoding issue.
        return [];
    }

    public function getForeignKeyMap(Connection $connection): array {
        $tables = $this->getTables($connection);
        $map = [];
        foreach ($tables as $table) {
            $fks = $connection->select("PRAGMA foreign_key_list(\"$table\")");
            foreach ($fks as $fk) {
                $map[$table][] = $fk['table'];
            }
        }
        return empty($map) ? $map : array_map(fn($p) => array_values(array_unique($p)), $map);
    }

    public function getViews(Connection $connection): array {
        $result = $connection->select(
            "SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name"
        );
        $views = [];
        foreach ($result as $row) {
            $views[$row['name']] = $row['sql'];
        }
        return $views;
    }

    public function getTriggers(Connection $connection): array {
        $result = $connection->select(
            "SELECT name, sql, tbl_name FROM sqlite_master WHERE type = 'trigger' ORDER BY name"
        );
        $triggers = [];
        foreach ($result as $row) {
            $triggers[$row['name']] = [
                'definition' => $row['sql'],
                'table'      => $row['tbl_name'],
            ];
        }
        return $triggers;
    }

    public function getRoutines(Connection $connection): array {
        // SQLite has no stored procedures or functions.
        return [];
    }

    public function getEnums(Connection $connection): array {
        // SQLite has no standalone enum types.
        return [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function fetchColumns(Connection $connection, string $table): array {
        $rows    = $connection->select("PRAGMA table_info(\"$table\")");
        $columns = [];

        foreach ($rows as $row) {
            $name    = $row['name'];
            $type    = strtolower($row['type'] ?: 'text');
            $notNull = $row['notnull'] ? ' NOT NULL' : '';
            $default = ($row['dflt_value'] !== null) ? ' DEFAULT ' . $row['dflt_value'] : '';
            $pk      = ($row['pk'] == 1) ? ' PRIMARY KEY' : '';

            $columns[$name] = '"' . $name . '" ' . $type . $pk . $notNull . $default;
        }

        return $columns;
    }

    private function fetchIndexes(Connection $connection, string $table): array {
        $indexList = $connection->select("PRAGMA index_list(\"$table\")");
        $keys      = [];

        foreach ($indexList as $idx) {
            // Skip auto-generated primary key indexes
            if ($idx['origin'] === 'pk') {
                continue;
            }

            $idxName   = $idx['name'];
            $indexInfo = $connection->select("PRAGMA index_info(\"$idxName\")");
            $cols      = array_map(fn($i) => '"' . $i['name'] . '"', $indexInfo);
            $unique    = $idx['unique'] ? 'UNIQUE ' : '';

            $keys[$idxName] =
                "CREATE {$unique}INDEX \"$idxName\" ON \"$table\" (" .
                implode(', ', $cols) . ')';
        }

        return $keys;
    }

    private function fetchForeignKeys(Connection $connection, string $table): array {
        $rows        = $connection->select("PRAGMA foreign_key_list(\"$table\")");
        $constraints = [];

        // FK rows may be multi-column (same id, different seq); group by id
        $groups = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'table'     => $row['table'],
                    'on_delete' => $row['on_delete'],
                    'on_update' => $row['on_update'],
                    'from'      => [],
                    'to'        => [],
                ];
            }
            $groups[$id]['from'][] = $row['from'];
            $groups[$id]['to'][]   = $row['to'];
        }

        foreach ($groups as $id => $fk) {
            $name    = 'fk_' . $table . '_' . $id;
            $fromCols = implode('", "', $fk['from']);
            $toCols   = implode('", "', $fk['to']);
            $constraints[$name] =
                "CONSTRAINT \"$name\" FOREIGN KEY (\"$fromCols\")" .
                " REFERENCES \"{$fk['table']}\" (\"$toCols\")" .
                " ON DELETE {$fk['on_delete']} ON UPDATE {$fk['on_update']}";
        }

        return $constraints;
    }
}
