<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * MySQL TIMESTAMP values, read and written in UTC.
 *
 * A TIMESTAMP is stored as an instant and printed in the session's
 * time_zone, which defaults to the server's. Read on a server at +13:45 and
 * written on one at +00:00, 10:00 became a different instant, 13h45m later,
 * and a second diff, reading each side in its own zone, saw nothing wrong.
 *
 * DBDiff's connections are in UTC (MySQLAdapter), so values are read and
 * compared as instants. The SQL it writes then has to be applied in UTC too,
 * whatever session runs it — mysqldump's answer: save the session's zone, set
 * UTC, and put it back at the end. Only migrations that change a table with a
 * TIMESTAMP column carry it: its rows, or a definition whose default is a
 * TIMESTAMP literal, now read in UTC.
 */
final class MySqlTimestamps
{
    public const UTC = '+00:00';

    /** Whether any of these tables, on either side, has a TIMESTAMP column. */
    public static function touched(array $connections, array $tables): bool
    {
        if ($tables === []) {
            return false;
        }
        $marks = implode(',', array_fill(0, count($tables), '?'));
        foreach ($connections as $connection) {
            /** @var Connection $connection */
            $found = $connection->select(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND LOWER(data_type) = 'timestamp' AND table_name IN ($marks)
                  LIMIT 1",
                array_values($tables)
            );
            if ($found !== []) {
                return true;
            }
        }
        return false;
    }

    /** `$sql` run in UTC, the session's own time zone put back after it. */
    public static function wrap(string $sql): string
    {
        return "SET @DBDIFF_TIME_ZONE = @@SESSION.time_zone;\n"
            . "SET time_zone = '" . self::UTC . "';\n"
            . $sql
            . "SET time_zone = @DBDIFF_TIME_ZONE;\n";
    }
}
