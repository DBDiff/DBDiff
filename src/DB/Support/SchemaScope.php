<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * The schema a PostgreSQL connection's catalog reads are about.
 *
 * Each read used to name `public` itself — some sixty of them — so no schema
 * but public could be compared at all: a table, type or function in `app` or
 * `private` was not seen, and two databases differing only there read as
 * identical. The schema now comes from the connection's `schema` setting, in
 * this one place, so a connection can be pointed at another schema and every
 * read follows it.
 */
final class SchemaScope
{
    /** The schema this connection reads, `public` unless it says otherwise. */
    public static function of(Connection $connection): string
    {
        return (string) ($connection->getConfig('schema') ?: 'public');
    }

    /** The schema as an SQL string literal, its quotes doubled. */
    public static function literal(Connection $connection): string
    {
        return "'" . str_replace("'", "''", self::of($connection)) . "'";
    }
}
