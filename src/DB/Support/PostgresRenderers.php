<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Which server renders a pair of definitions to compare them — see
 * PostgresExpressionEquivalence.
 *
 * Rendering creates temporary objects in a rolled-back transaction, which a
 * read-only connection, or one without TEMP, refuses.
 */
final class PostgresRenderers {

    /** @var array<int, bool> whether a connection can create temporary objects */
    private static array $renders = [];

    /** @var array<int, int> server major version, per connection */
    private static array $majors = [];

    /** Forget what was probed: connection ids are reused once a connection is gone. */
    public static function reset(): void {
        self::$renders = [];
    }

    /**
     * false: each definition on its own server — the same major version, and
     * both can render. Otherwise one server renders both, since across
     * versions (PostgreSQL 16 dropped the table qualifiers 15 writes in a
     * view) or with only one side able to render, only one server's
     * renderings are comparable: the target if it can, else the source.
     * null: neither can.
     *
     * A read-only source used to leave equivalent definitions reported as
     * changes — a CHECK rewritten as `= ANY (ARRAY[...])` by a dump and
     * restore, a view with DISTINCT ON — because the target-side rendering
     * was tried only across major versions.
     */
    public static function choose(Connection $source, Connection $target): Connection|false|null {
        if (self::major($source) === self::major($target) && self::canRender($source) && self::canRender($target)) {
            return false;
        }
        if (self::canRender($target)) {
            return $target;
        }
        return self::canRender($source) ? $source : null;
    }

    public static function canRender(Connection $connection): bool {
        return self::$renders[spl_object_id($connection)] ??= self::probe($connection);
    }

    public static function major(Connection $connection): int {
        $key = spl_object_id($connection);
        return self::$majors[$key] ??= intdiv((int) $connection->selectOne('SHOW server_version_num')['server_version_num'], 10000);
    }

    private static function probe(Connection $connection): bool {
        $pdo = $connection->getPdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('CREATE TEMP TABLE pg_temp.dbdiff_render_probe (x int)');
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $pdo->rollBack();
        }
    }
}
