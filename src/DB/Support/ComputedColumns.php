<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;

/**
 * Columns whose values the server computes, which a data diff must treat
 * apart from the rest. Per engine, kept out of the adapters, which describe
 * schema rather than how rows are written.
 */
final class ComputedColumns
{
    /**
     * Generated columns: a data diff leaves them out, since writing one is an
     * error on every engine and its value follows from the columns written.
     *
     * @return string[]
     */
    public static function generated(Connection $connection, string $driver, string $table): array
    {
        return match ($driver) {
            'pgsql'  => Arr::pluck($connection->select(
                "SELECT a.attname FROM pg_attribute a
                   JOIN pg_class c ON c.oid = a.attrelid
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = 'public' AND c.relname = ?
                    AND a.attnum > 0 AND NOT a.attisdropped AND a.attgenerated <> ''
                  ORDER BY a.attnum",
                [$table]
            ), 'attname'),
            'mysql'  => self::mysqlGenerated($connection, $table),
            'sqlite' => self::sqliteGenerated($connection, $table),
            default  => [],
        };
    }

    /**
     * Columns that are GENERATED ALWAYS AS IDENTITY, which only PostgreSQL has:
     * a row keeps its value in one only if the INSERT overrides the system value.
     *
     * @return string[]
     */
    public static function identityAlways(Connection $connection, string $driver, string $table): array
    {
        if ($driver !== 'pgsql') {
            return [];
        }
        return Arr::pluck($connection->select(
            "SELECT a.attname FROM pg_attribute a
               JOIN pg_class c ON c.oid = a.attrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relname = ?
                AND a.attnum > 0 AND NOT a.attisdropped AND a.attidentity = 'a'",
            [$table]
        ), 'attname');
    }

    /**
     * Read from SHOW COLUMNS rather than with LIKE on information_schema,
     * whose collation can clash with the connection's (error 1267). Only
     * VIRTUAL or STORED GENERATED: MySQL 8 also writes DEFAULT_GENERATED for a
     * column whose default is an expression, which is an ordinary column.
     *
     * @return string[]
     */
    private static function mysqlGenerated(Connection $connection, string $table): array
    {
        $generated = [];
        foreach ($connection->select("SHOW COLUMNS FROM `$table`") as $row) {
            $row = (array) $row;
            if (preg_match('/\b(?:VIRTUAL|STORED) GENERATED\b/i', (string) ($row['Extra'] ?? ''))) {
                $generated[] = $row['Field'];
            }
        }
        return $generated;
    }

    /**
     * table_xinfo marks a generated column hidden = 2 (virtual) or 3 (stored).
     *
     * @return string[]
     */
    private static function sqliteGenerated(Connection $connection, string $table): array
    {
        $generated = [];
        foreach ($connection->select("PRAGMA table_xinfo(\"$table\")") as $row) {
            $row = (array) $row;
            if (in_array((int) $row['hidden'], [2, 3], true)) {
                $generated[] = $row['name'];
            }
        }
        return $generated;
    }
}
