<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * What one side of a PostgreSQL diff needs to know about the other: the
 * older of the two servers' major versions. A definition only the newer one
 * can hold is read the way the older one would hold it, on both sides — a
 * PostgreSQL 18 NOT NULL constraint's name, say, which an older server has
 * no way to write, is read as the column's NOT NULL.
 */
final class PostgresPeers {

    private static ?int $lowest = null;

    public static function compare(Connection $source, Connection $target): void {
        self::$lowest = min(PostgresRenderers::major($source), PostgresRenderers::major($target));
    }

    /** The older side's major version; null until both are connected. */
    public static function lowestMajor(): ?int {
        return self::$lowest;
    }

    public static function forget(): void {
        self::$lowest = null;
    }
}
