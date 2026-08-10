<?php namespace DBDiff\DB\Support;


/**
 * Small stateless helpers shared by the DB adapters and the data-diff layer.
 *
 * These live outside the adapter classes on purpose: MySQLAdapter and
 * PostgresAdapter both sit on the 20-method-per-class ceiling, so shared
 * behaviour has to be extracted rather than added as another private method.
 */
class QueryHelper {

    /**
     * Build a positional-placeholder list for an `IN (...)` clause.
     *
     * Returns "?,?,?" for a three-element list, or an empty string for an
     * empty list — callers must guard against the empty case themselves,
     * since `IN ()` is not valid SQL on any supported driver.
     */
    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Restrict a table-keyed map to the requested table names.
     *
     * An empty $tables list means "no restriction" — the map is returned
     * unchanged. This matches the getSchemaHashMap() contract, where the
     * table list is an optional narrowing filter rather than a required
     * argument. Key order of the original map is preserved.
     */
    public static function restrictToTables(array $map, array $tables): array
    {
        if (empty($tables)) {
            return $map;
        }
        return array_intersect_key($map, array_flip($tables));
    }
}
