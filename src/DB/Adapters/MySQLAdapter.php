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
        $result = $connection->select("show tables");
        return Arr::flatten($result);
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
                $columns[$name] = $line;
            } elseif (Str::startsWith($line, 'CONSTRAINT')) {
                $constraints[$name] = $line;
            } else {
                $keys[$name] = $line;
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
}
