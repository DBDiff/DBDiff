<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Calls to an extension's functions, read the same whichever schema the
 * extension is in.
 *
 * Supabase installs uuid-ossp, pgcrypto and others in its `extensions`
 * schema; a database restored from elsewhere has them in `public`. A default
 * of `uuid_generate_v1()` then renders as `extensions.uuid_generate_v1()` on
 * one side and unqualified on the other, and every such column read as
 * changed. A call is the same on both sides only when each resolves to a
 * function the same extension owns (pg_depend): a function of that name that
 * is not the extension's, or another extension's, still differs.
 */
final class PostgresExtensionFunctions {

    /** @var array<int, list<array{schema: string, name: string, extension: string}>> */
    private static array $members = [];

    public static function reset(): void {
        self::$members = [];
    }

    /** Whether two definitions differ only in where an extension's functions live. */
    public static function sameApartFromSchema(Connection $source, Connection $target, string $sourceDef, string $targetDef): bool {
        if ($sourceDef === $targetDef) {
            return false;
        }
        return self::normalise($source, $sourceDef) === self::normalise($target, $targetDef);
    }

    /** Each call to an extension's function written as the extension's, not a schema's. */
    public static function normalise(Connection $connection, string $definition): string {
        foreach (self::members($connection) as $m) {
            $fn = self::namePattern($m['name']);
            $token = '«' . $m['extension'] . '».' . $m['name'] . '(';
            $definition = preg_replace('/(?<![\w."])' . self::namePattern($m['schema']) . '\.' . $fn . '\(/', $token, $definition);
            // Unqualified, a call resolves through the search path, which is
            // `public` while DBDiff reads (DBManager::useSchema).
            if ($m['schema'] === 'public') {
                $definition = preg_replace('/(?<![\w."«»])' . $fn . '\(/', $token, $definition);
            }
        }
        return $definition;
    }

    private static function namePattern(string $name): string {
        $quoted = '"' . str_replace('"', '""', $name) . '"';
        return preg_match('/^[a-z_][a-z0-9_$]*$/', $name)
            ? '(?:' . preg_quote($name, '/') . '|' . preg_quote($quoted, '/') . ')'
            : preg_quote($quoted, '/');
    }

    /** @return list<array{schema: string, name: string, extension: string}> */
    private static function members(Connection $connection): array {
        return self::$members[spl_object_id($connection)] ??= array_map(
            fn($row) => (array) $row,
            $connection->select(
                "SELECT n.nspname AS schema, p.proname AS name, e.extname AS extension
                   FROM pg_depend d
                   JOIN pg_proc p ON p.oid = d.objid
                   JOIN pg_namespace n ON n.oid = p.pronamespace
                   JOIN pg_extension e ON e.oid = d.refobjid
                  WHERE d.classid = 'pg_proc'::regclass AND d.refclassid = 'pg_extension'::regclass
                    AND d.deptype = 'e'
                  GROUP BY n.nspname, p.proname, e.extname
                  ORDER BY length(p.proname) DESC, 2, 1"
            )
        );
    }
}
