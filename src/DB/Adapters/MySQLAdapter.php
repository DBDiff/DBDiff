<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;


class MySQLAdapter implements DBAdapterInterface {

    public function buildConnectionConfig(array $server, string $db): array {
        return [
            'driver'    => 'mysql',
            'host'      => $server['host'],
            'port'      => $server['port'],
            'database'  => $db,
            'username'  => $server['user'],
            'password'  => $server['password'],
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
        ];
    }

    public function getTables(Connection $connection): array {
        $db = $connection->getDatabaseName();
        $result = $connection->select(
            "SHOW FULL TABLES FROM `$db` WHERE Table_type = 'BASE TABLE'"
        );
        return array_map(fn($row) => array_values((array) $row)[0], $result);
    }

    public function getColumns(Connection $connection, string $table): array {
        $result = $connection->select("show columns from `$table`");
        return Arr::pluck($result, 'Field');
    }

    public function getPrimaryKey(Connection $connection, string $table): array {
        $keys = $connection->select("show indexes from `$table`");
        $pkey = [];
        foreach ($keys as $key) {
            if ($key['Key_name'] === 'PRIMARY') {
                $pkey[] = $key['Column_name'];
            }
        }
        return $pkey;
    }

    public function getTableSchema(Connection $connection, string $table): array {
        // Engine & collation
        $status    = $connection->select("show table status like '$table'");
        $engine    = $status[0]['Engine'];
        $collation = $status[0]['Collation'];

        // Parse SHOW CREATE TABLE
        $schema = $connection->select("SHOW CREATE TABLE `$table`")[0]['Create Table'];
        $lines  = array_map(fn($l) => trim($l), explode("\n", $schema));
        $lines  = array_slice($lines, 1, -1); // remove CREATE TABLE ... ( and closing ) ENGINE=...

        $columns     = [];
        $keys        = [];
        $constraints = [];

        foreach ($lines as $line) {
            preg_match("/`([^`]+)`/", $line, $matches);
            $name = $matches[1];
            $line = trim($line, ',');
            if (Str::startsWith($line, '`')) {
                $columns[$name] = $this->normalizeColumnDef($line);
            } elseif (Str::startsWith($line, 'CONSTRAINT')) {
                $constraints[$name] = $line;
            } elseif (Str::startsWith($line, 'PRIMARY KEY')) {
                $keys['PRIMARY'] = $line;
            } else {
                $keys[$name] = $this->normalizeKeyDef($line);
            }
        }

        return [
            'engine'      => $engine,
            'collation'   => $collation,
            'columns'     => $columns,
            'keys'        => $keys,
            'constraints' => $constraints,
        ];
    }

    public function getCreateStatement(Connection $connection, string $table): string {
        $res = $connection->select("SHOW CREATE TABLE `$table`");
        return $res[0]['Create Table'];
    }

    public function getDBVariable(Connection $connection, string $variable): ?string {
        $result = $connection->select("show variables like '$variable'");
        return $result[0]['Value'] ?? null;
    }

    public function getBinaryColumns(Connection $connection, string $table): array {
        $result = $connection->select("SHOW COLUMNS FROM `$table`");
        $binary = [];
        foreach ($result as $row) {
            $type = strtolower($row['Type']);
            if (preg_match('/^(binary|varbinary|tinyblob|blob|mediumblob|longblob)/', $type)) {
                $binary[] = $row['Field'];
            }
        }
        return $binary;
    }

    public function getForeignKeyMap(Connection $connection): array {
        $db = $connection->getDatabaseName();
        $result = $connection->select(
            "SELECT TABLE_NAME, REFERENCED_TABLE_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$db]
        );
        $map = [];
        foreach ($result as $row) {
            $map[$row['TABLE_NAME']][] = $row['REFERENCED_TABLE_NAME'];
        }
        return empty($map) ? $map : array_map(fn($p) => array_values(array_unique($p)), $map);
    }

    public function getViews(Connection $connection): array {
        $db = $connection->getDatabaseName();
        $result = $connection->select(
            "SHOW FULL TABLES FROM `$db` WHERE Table_type = 'VIEW'"
        );
        $views = [];
        foreach ($result as $row) {
            $name = array_values((array) $row)[0];
            $stmt = $connection->select("SHOW CREATE VIEW `$name`");
            $views[$name] = $this->normalizeCreateStatement($stmt[0]['Create View']);
        }
        return $views;
    }

    public function getTriggers(Connection $connection): array {
        $db = $connection->getDatabaseName();
        $result = $connection->select("SHOW TRIGGERS FROM `$db`");
        $triggers = [];
        foreach ($result as $row) {
            $name  = $row['Trigger'];
            $table = $row['Table'];
            $stmt  = $connection->select("SHOW CREATE TRIGGER `$name`");
            $triggers[$name] = [
                'definition' => $this->normalizeCreateStatement($stmt[0]['SQL Original Statement']),
                'table'      => $table,
            ];
        }
        return $triggers;
    }

    public function getRoutines(Connection $connection): array {
        $db = $connection->getDatabaseName();
        $result = $connection->select(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = ?",
            [$db]
        );
        $routines = [];
        foreach ($result as $row) {
            $name = $row['ROUTINE_NAME'];
            $type = $row['ROUTINE_TYPE']; // PROCEDURE or FUNCTION
            $stmt = $connection->select("SHOW CREATE $type `$name`");
            $key  = $type === 'PROCEDURE' ? 'Create Procedure' : 'Create Function';
            $routines[$name] = $this->normalizeCreateStatement($stmt[0][$key]);
        }
        return $routines;
    }

    public function getEnums(Connection $connection): array {
        // MySQL enums are column-level type constraints, not standalone types.
        return [];
    }

    /**
     * Strip MySQL-specific DEFINER, ALGORITHM, and SQL SECURITY clauses
     * from a CREATE statement so that definitions can be compared across
     * environments and emitted without environment-specific metadata.
     */
    private function normalizeCreateStatement(string $definition): string {
        $definition = preg_replace('/\s*ALGORITHM\s*=\s*\w+/i', '', $definition);
        $definition = preg_replace('/\s*DEFINER\s*=\s*`[^`]*`@`[^`]*`/i', '', $definition);
        $definition = preg_replace('/\s*SQL\s+SECURITY\s+(?:DEFINER|INVOKER)/i', '', $definition);
        return rtrim(trim($definition), ';');
    }

    /**
     * Normalise a column DDL fragment so that two MySQL versions produce
     * identical strings for semantically identical columns.
     *
     * 1. Strip integer display widths removed in MySQL 8.0.17+.
     * 2. Canonicalise CURRENT_TIMESTAMP (case + parentheses).
     */
    private function normalizeColumnDef(string $def): string {
        // Integer display widths: int(11) → int, tinyint(4) → tinyint, etc.
        $def = preg_replace(
            '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i',
            '$1',
            $def
        );

        // Normalise CURRENT_TIMESTAMP variants (with optional precision).
        // current_timestamp() → CURRENT_TIMESTAMP
        // current_timestamp(3) → CURRENT_TIMESTAMP(3)
        $def = preg_replace_callback(
            '/\bcurrent_timestamp(?:\((\d*)\))?/i',
            function ($m) {
                $precision = $m[1] ?? '';
                return ($precision !== '') ? "CURRENT_TIMESTAMP($precision)" : 'CURRENT_TIMESTAMP';
            },
            $def
        );

        return $def;
    }

    /**
     * Normalise an index / key DDL fragment.
     *
     * Strip trailing USING BTREE — it is the default index type and its
     * inclusion varies between MySQL versions, causing false positives.
     */
    private function normalizeKeyDef(string $def): string {
        return preg_replace('/\s+USING BTREE$/i', '', $def);
    }
}
