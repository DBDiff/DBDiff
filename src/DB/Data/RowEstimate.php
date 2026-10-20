<?php namespace DBDiff\DB\Data;

use DBDiff\DB\Support\PostgresSnapshot;
use Illuminate\Database\Connection;

/**
 * How many rows PostgreSQL expects a table to hold, without counting them.
 *
 * Estimated the way its planner does: the rows per page seen by the last
 * ANALYZE or VACUUM, times the pages the table has now — so a table that has
 * grown since is not mistaken for its old size. With no statistics yet
 * (reltuples -1, or no pages counted), or for anything but a plain table or
 * materialised view (a partitioned parent has no pages of its own), there is
 * no estimate.
 */
class RowEstimate
{
    /** Whether `$table` (already quoted) is expected to hold at most `$limit` rows. Unknown is not small. */
    public static function atMost(Connection $conn, string $table, int $limit): bool
    {
        $estimate = self::of($conn, $table);
        return $estimate !== null && $estimate <= $limit;
    }

    /** The estimated row count of `$table` (already quoted), or null when there is none. */
    public static function of(Connection $conn, string $table): ?float
    {
        $stats = self::stats($conn, $table);
        if ($stats === null || !in_array($stats['kind'], ['r', 'm'], true)) {
            return null;
        }
        if ((int) $stats['current_pages'] === 0) {
            return 0.0;
        }
        return (int) $stats['pages'] > 0 && (float) $stats['tuples'] >= 0
            ? (float) $stats['tuples'] / (int) $stats['pages'] * (int) $stats['current_pages']
            : null;
    }

    /** @return array{kind: string, tuples: float, pages: int, current_pages: int}|null */
    private static function stats(Connection $conn, string $table): ?array
    {
        try {
            $row = PostgresSnapshot::attempt($conn, fn() => $conn->selectOne(
                "SELECT c.relkind AS kind, c.reltuples::float8 AS tuples, c.relpages AS pages,
                        pg_relation_size(c.oid) / current_setting('block_size')::int AS current_pages
                   FROM pg_class c WHERE c.oid = to_regclass(?)",
                [$table]
            ));
        } catch (\Throwable) {
            return null;
        }
        // DBDiff's connections fetch arrays, a plain Laravel one objects.
        return $row === null ? null : (array) $row;
    }
}
