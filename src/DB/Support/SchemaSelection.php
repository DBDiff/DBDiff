<?php namespace DBDiff\DB\Support;

use DBDiff\Params\TableFilter;
use Illuminate\Database\Connection;

/**
 * Which PostgreSQL schemas a diff compares.
 *
 * `public` alone unless the params say otherwise: `schemas` names the ones to
 * compare, and `schemasToIgnore` alone compares every schema but those. With
 * neither, a `tables` pattern that names a schema (`app.plans`, `app.*`)
 * brings that schema in beside `public`: it read nothing before, and the diff
 * reported tables it never looked at as identical. The system's own schemas
 * are never compared.
 */
final class SchemaSelection
{
    private const DEFAULT = ['public'];

    /**
     * The schemas to compare, `public` first and the rest by name.
     *
     * Read from both databases: a schema only one of them has is one to
     * create or drop.
     *
     * @return list<string>
     */
    public static function resolve(object $params, Connection $source, Connection $target): array
    {
        $include = self::patterns($params->schemas ?? null);
        $ignore  = self::patterns($params->schemasToIgnore ?? null);

        if ($include === [] && $ignore === []) {
            $named = self::schemasNamedBy(self::patterns($params->tables ?? null));
            if (array_diff($named, self::DEFAULT) === []) {
                return self::DEFAULT;
            }
            $include = array_values(array_unique([...self::DEFAULT, ...$named]));
        }

        $present = array_values(array_unique(array_merge(self::userSchemas($source), self::userSchemas($target))));
        $chosen  = $include === [] ? $present : TableFilter::matchGlobs($present, $include);
        $chosen  = TableFilter::rejectGlobs($chosen, $ignore);

        // A schema named outright is compared even where neither side has it
        // yet: two databases without it are identical in it.
        foreach ($include as $pattern) {
            if (!str_contains($pattern, '*') && !str_contains($pattern, '?') && !in_array($pattern, $chosen, true)
                && TableFilter::rejectGlobs([$pattern], $ignore) !== []) {
                $chosen[] = $pattern;
            }
        }

        usort($chosen, fn(string $a, string $b) => [$a !== 'public', $a] <=> [$b !== 'public', $b]);
        return $chosen;
    }

    /** Whether the selection is the one DBDiff has always compared. */
    public static function isDefault(array $schemas): bool
    {
        return $schemas === self::DEFAULT;
    }

    /**
     * The schemas a user created: not the system's, nor an extension's own.
     *
     * @return list<string>
     */
    public static function userSchemas(Connection $connection): array
    {
        $rows = $connection->select(
            "SELECT n.nspname AS name
               FROM pg_namespace n
              WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
                AND n.nspname NOT LIKE 'pg\\_toast%'
                AND n.nspname NOT LIKE 'pg\\_temp\\_%'
                AND NOT EXISTS (SELECT 1 FROM pg_depend d
                                 WHERE d.classid = 'pg_namespace'::regclass AND d.objid = n.oid
                                   AND d.deptype = 'e')
              ORDER BY 1"
        );
        return array_map(fn($r) => (string) ((array) $r)['name'], $rows);
    }

    /**
     * The schema part of each table pattern that has one: `app` of `app.plans`.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    private static function schemasNamedBy(array $tables): array
    {
        $schemas = [];
        foreach ($tables as $pattern) {
            if (str_contains($pattern, '.')) {
                $schemas[] = substr($pattern, 0, strpos($pattern, '.'));
            }
        }
        return array_values(array_unique($schemas));
    }

    /** @return list<string> */
    private static function patterns(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map('trim', $list), fn($p) => $p !== ''));
    }
}
