<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * The output formats of DBDiff's PostgreSQL sessions, pinned.
 *
 * DBDiff compares values as PostgreSQL prints them and writes that text into
 * its SQL, and how a value prints is a session setting that a server, a
 * database or a role can change. With `DateStyle = 'SQL, DMY'` 3 April 2026
 * prints as `03/04/2026`, which a target reading month first stores as
 * 4 March; `extra_float_digits = 0` rounds a double to 15 significant digits;
 * two servers in different time zones print the same instant differently, so
 * every timestamptz compares unequal. Each was silent.
 *
 * These are the settings pg_dump pins for the same reason, plus UTC, so both
 * sides of a comparison print alike. They are applied after the connection
 * URL's own `options`, so a URL cannot unpin them.
 */
final class SessionFormats
{
    public const POSTGRES = [
        'DateStyle'          => 'ISO, MDY',
        'IntervalStyle'      => 'postgres',
        'extra_float_digits' => '3',
        'TimeZone'           => 'UTC',
        'bytea_output'       => 'hex',
    ];

    public static function apply(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::POSTGRES as $name => $value) {
            $connection->select('SELECT set_config(?, ?, false)', [$name, $value]);
        }
    }
}
