<?php namespace DBDiff\DB\Data;

use DBDiff\Logger;
use DBDiff\DB\Support\PostgresSnapshot;
use Illuminate\Database\Connection;

/**
 * The order a key-ordered merge reads both tables in, and how it compares
 * keys — chosen together, so the database and the merge agree.
 *
 * A numeric key sorts natively and compares as a number. Anything else sorts
 * by the bytes of its text, which is what strcmp() compares: under a
 * linguistic collation (en_US, utf8mb4_0900_ai_ci) the database puts 'a'
 * before 'B' where strcmp() puts 'B' first, and '10' sorts before '9' as text
 * but after it as a number. A merge whose order differs from the database's
 * falls out of step and reports rows both sides have as a delete and an
 * insert. A uuid keeps its native order, which is already its hex text's, so
 * its index still serves.
 */
class KeyOrder
{
    private const MYSQL_NUMBERS = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year'];

    public function __construct(private Connection $source, private string $driver)
    {
    }

    /**
     * @param string   $table     The table's name.
     * @param string   $qualified The table as queries name it.
     * @param string[] $key       Key columns, in order.
     * @return array{0: string, 1: string[]} the ORDER BY, and the key columns to compare as numbers
     */
    public function of(string $table, string $qualified, array $key): array
    {
        $kinds   = $this->kinds($table, $qualified);
        $numeric = [];
        $terms   = [];
        foreach ($key as $col) {
            $kind = $kinds[$col] ?? 'text';
            if ($kind === 'number') {
                $numeric[] = $col;
            }
            $terms[] = $this->term($this->quote($col), $kind);
        }
        return [implode(', ', $terms), $numeric];
    }

    private function term(string $quoted, string $kind): string
    {
        if ($kind !== 'text') {
            return $quoted;
        }
        return match ($this->driver) {
            'pgsql' => "({$quoted})::text COLLATE \"C\"",
            'mysql' => "CAST({$quoted} AS BINARY)",
            default => "CAST({$quoted} AS TEXT) COLLATE BINARY",
        };
    }

    /**
     * 'number', 'uuid' or 'text' for each column, from the source's catalog.
     * A column it cannot classify is 'text', the order always consistent
     * with strcmp().
     *
     * @return array<string, string>
     */
    private function kinds(string $table, string $qualified): array
    {
        try {
            return match ($this->driver) {
                'pgsql' => PostgresSnapshot::attempt($this->source, fn() => $this->pgsqlKinds($qualified)),
                'mysql' => $this->mysqlKinds($table),
                default => $this->sqliteKinds($table),
            };
        } catch (\Throwable $e) {
            Logger::info("Could not read key column types for `$table`; ordering keys as text: " . $e->getMessage());
            return [];
        }
    }

    private function pgsqlKinds(string $qualified): array
    {
        $rows = $this->source->select(
            "SELECT a.attname AS name,
                    CASE WHEN t.typcategory = 'N' THEN 'number' WHEN t.typname = 'uuid' THEN 'uuid' ELSE 'text' END AS kind
               FROM pg_attribute a
               JOIN pg_type d ON d.oid = a.atttypid
               -- A domain sorts as its base type.
               JOIN pg_type t ON t.oid = CASE WHEN d.typtype = 'd' THEN d.typbasetype ELSE d.oid END
              WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped",
            [$qualified]
        );
        return self::byName($rows);
    }

    private function mysqlKinds(string $table): array
    {
        $rows = $this->source->select(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        $kinds = [];
        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_LOWER);
            $kinds[$row['name']] = in_array(strtolower($row['type']), self::MYSQL_NUMBERS, true) ? 'number' : 'text';
        }
        return $kinds;
    }

    private function sqliteKinds(string $table): array
    {
        $kinds = [];
        foreach ($this->source->select('PRAGMA table_info(' . $this->quote($table) . ')') as $row) {
            $row = (array) $row;
            // SQLite's own affinity rules: a declared type naming INT, REAL, FLOA, DOUB, NUM or DEC.
            $kinds[$row['name']] = preg_match('/INT|REAL|FLOA|DOUB|NUM|DEC/i', (string) $row['type']) ? 'number' : 'text';
        }
        return $kinds;
    }

    /** @return array<string, string> name => kind, from rows fetched as arrays or objects */
    private static function byName(array $rows): array
    {
        $kinds = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $kinds[$row['name']] = $row['kind'];
        }
        return $kinds;
    }

    private function quote(string $name): string
    {
        return $this->driver === 'mysql' ? "`$name`" : "\"$name\"";
    }
}
