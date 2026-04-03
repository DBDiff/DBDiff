<?php namespace DBDiff\Params;

/**
 * Centralised table and field filtering.
 *
 * Supports glob patterns (* and ?) in table include, exclude, and
 * data-only exclude lists, plus fieldsToIgnore keys.
 *
 * All public methods are stateless — they operate on the provided
 * array/params and return new arrays. No side-effects.
 */
class TableFilter
{
    /**
     * Filter a list of table names according to include, exclude, and
     * data-only-exclude rules.
     *
     * Priority: tables (include list) wins over tablesToIgnore (exclude).
     *
     * @param string[] $tables       Raw table list from the adapter
     * @param object   $params       Params object (tables, tablesToIgnore, tablesDataToIgnore, tableScope)
     * @param string   $scope        'all' = both schema+data, 'schema' = schema only, 'data' = data only
     * @return string[] Filtered table list (re-indexed)
     */
    public static function filterTables(array $tables, object $params, string $scope = 'all'): array
    {
        // 1. Include list — keep only matching tables
        $includeList = $params->tables ?? null;
        if (!empty($includeList)) {
            $tables = self::matchGlobs($tables, $includeList);
        }

        // 2. Exclude list — remove matching tables.
        //    When an include list is also active, the exclude list further
        //    narrows the included set (useful for "wp_* minus wp_wc_session*").
        $excludeList = $params->tablesToIgnore ?? null;
        if (!empty($excludeList)) {
            $tables = self::rejectGlobs($tables, $excludeList);
        }

        // 3. Data-only exclusion — when in data scope, additionally exclude
        if ($scope === 'data') {
            $dataExclude = $params->tablesDataToIgnore ?? null;
            if (!empty($dataExclude)) {
                $tables = self::rejectGlobs($tables, $dataExclude);
            }
        }

        // 4. Per-table scope overrides — when diffing schema, remove tables
        //    scoped to 'data' only; when diffing data, remove tables scoped
        //    to 'schema' only.
        $tableScope = $params->tableScope ?? null;
        if (!empty($tableScope) && $scope !== 'all') {
            $tables = array_filter($tables, function (string $name) use ($tableScope, $scope) {
                $s = $tableScope[$name] ?? 'all';
                // 'all' → included in both schema and data
                // 'schema' → included in schema only
                // 'data' → included in data only
                return $s === 'all' || $s === $scope;
            });
        }

        return array_values($tables);
    }

    /**
     * Resolve the list of fields to ignore for a given table, supporting
     * glob patterns in the fieldsToIgnore keys.
     *
     * Example: fieldsToIgnore['wp_*'] = ['updated_at'] matches table 'wp_users'.
     *
     * @param string   $table  Concrete table name
     * @param object   $params Params object
     * @return string[] Field names to ignore (may be empty)
     */
    public static function getFieldsToIgnore(string $table, object $params): array
    {
        $fieldsToIgnore = $params->fieldsToIgnore ?? null;
        if (empty($fieldsToIgnore)) {
            return [];
        }

        $result = [];
        foreach ($fieldsToIgnore as $pattern => $fields) {
            if (self::globMatch($pattern, $table)) {
                $result = array_merge($result, (array) $fields);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Test whether any row-ignore rules apply to a given table.
     *
     * @param string $table  Table name
     * @param object $params Params object
     * @return array[] Array of ['column' => ..., 'pattern' => ...] rules
     */
    public static function getRowIgnoreRules(string $table, object $params): array
    {
        $rowsToIgnore = $params->rowsToIgnore ?? null;
        if (empty($rowsToIgnore) || !isset($rowsToIgnore[$table])) {
            return [];
        }

        return $rowsToIgnore[$table];
    }

    /**
     * Filter an array of rows, removing those that match any rowsToIgnore rule.
     *
     * Each rule is ['column' => 'col_name', 'pattern' => 'regex…'].
     *
     * @param array   $rows   Rows (associative arrays)
     * @param array[] $rules  Row-ignore rules from getRowIgnoreRules()
     * @return array  Filtered rows (re-indexed)
     */
    public static function filterRows(array $rows, array $rules): array
    {
        if (empty($rules)) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($rules) {
            foreach ($rules as $rule) {
                $column  = $rule['column']  ?? null;
                $pattern = $rule['pattern'] ?? null;
                if ($column === null || $pattern === null) {
                    continue;
                }
                $value = (string) ($row[$column] ?? '');
                // Wrap pattern in delimiters; anchor with ^ and $ for full match.
                if (preg_match('/^' . $pattern . '$/u', $value) === 1) {
                    return false; // row matches an ignore rule → exclude
                }
            }
            return true;
        }));
    }

    // ── Glob helpers ──────────────────────────────────────────────────────

    /**
     * Keep only items from $haystack that match any pattern in $patterns.
     *
     * @param string[] $haystack
     * @param string[] $patterns Glob patterns (* and ? supported)
     * @return string[]
     */
    public static function matchGlobs(array $haystack, array $patterns): array
    {
        return array_values(array_filter($haystack, function (string $item) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (self::globMatch($pattern, $item)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Remove items from $haystack that match any pattern in $patterns.
     *
     * @param string[] $haystack
     * @param string[] $patterns Glob patterns (* and ? supported)
     * @return string[]
     */
    public static function rejectGlobs(array $haystack, array $patterns): array
    {
        return array_values(array_filter($haystack, function (string $item) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (self::globMatch($pattern, $item)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Match a single glob pattern against a string.
     *
     * Supports * (any number of characters) and ? (exactly one character).
     * Matching is case-sensitive.
     *
     * @param string $pattern Glob pattern
     * @param string $subject String to test
     * @return bool
     */
    public static function globMatch(string $pattern, string $subject): bool
    {
        // Fast path: no wildcards → exact match
        if (strpos($pattern, '*') === false && strpos($pattern, '?') === false) {
            return $pattern === $subject;
        }

        // Convert glob to regex: escape regex specials, then convert * and ?
        $regex = preg_quote($pattern, '/');
        $regex = str_replace(['\*', '\?'], ['.*', '.'], $regex);

        return preg_match('/^' . $regex . '$/u', $subject) === 1;
    }
}
