<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBDiff\Params\TableFilter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the centralised TableFilter utility.
 *
 * Covers:
 *  - Glob matching (*, ?)
 *  - Table include list (tables)
 *  - Table exclude list (tablesToIgnore) with globs
 *  - Data-only exclusion (tablesDataToIgnore)
 *  - Per-table scope override (tableScope)
 *  - Field ignore with glob keys (fieldsToIgnore)
 *  - Row filtering by column regex (rowsToIgnore)
 *  - Priority: tables > tablesToIgnore
 *  - Combined filtering scenarios
 */
class TableFilterTest extends TestCase
{
    // ── globMatch ─────────────────────────────────────────────────────────

    public function testGlobMatchExact(): void
    {
        $this->assertTrue(TableFilter::globMatch('users', 'users'));
        $this->assertFalse(TableFilter::globMatch('users', 'orders'));
    }

    public function testGlobMatchStar(): void
    {
        $this->assertTrue(TableFilter::globMatch('wp_*', 'wp_posts'));
        $this->assertTrue(TableFilter::globMatch('wp_*', 'wp_'));
        $this->assertFalse(TableFilter::globMatch('wp_*', 'users'));
    }

    public function testGlobMatchQuestion(): void
    {
        $this->assertTrue(TableFilter::globMatch('log_?', 'log_a'));
        $this->assertFalse(TableFilter::globMatch('log_?', 'log_ab'));
        $this->assertFalse(TableFilter::globMatch('log_?', 'log_'));
    }

    public function testGlobMatchCombined(): void
    {
        $this->assertTrue(TableFilter::globMatch('cache_*_v?', 'cache_session_v2'));
        $this->assertFalse(TableFilter::globMatch('cache_*_v?', 'cache_session_v12'));
    }

    public function testGlobMatchRegexSpecialChars(): void
    {
        // Ensure regex special characters in the pattern are escaped
        $this->assertTrue(TableFilter::globMatch('table.backup', 'table.backup'));
        $this->assertFalse(TableFilter::globMatch('table.backup', 'tableXbackup'));
    }

    public function testGlobMatchIsCaseSensitive(): void
    {
        $this->assertFalse(TableFilter::globMatch('Users', 'users'));
    }

    // ── matchGlobs / rejectGlobs ──────────────────────────────────────────

    public function testMatchGlobs(): void
    {
        $tables = ['users', 'orders', 'wp_posts', 'wp_options', 'cache_items'];
        $result = TableFilter::matchGlobs($tables, ['wp_*', 'users']);
        sort($result);
        $this->assertSame(['users', 'wp_options', 'wp_posts'], $result);
    }

    public function testRejectGlobs(): void
    {
        $tables = ['users', 'cache_items', 'cache_sessions', 'orders'];
        $result = TableFilter::rejectGlobs($tables, ['cache_*']);
        sort($result);
        $this->assertSame(['orders', 'users'], $result);
    }

    // ── filterTables ──────────────────────────────────────────────────────

    /**
     * Include list only — keeps only matching tables.
     */
    public function testFilterTablesIncludeList(): void
    {
        $params = (object) ['tables' => ['users', 'wp_*'], 'tablesToIgnore' => null];
        $tables = ['users', 'orders', 'wp_posts', 'wp_options'];
        $result = TableFilter::filterTables($tables, $params);
        sort($result);
        $this->assertSame(['users', 'wp_options', 'wp_posts'], $result);
    }

    /**
     * Exclude list only — removes matching tables.
     */
    public function testFilterTablesExcludeList(): void
    {
        $params = (object) ['tables' => null, 'tablesToIgnore' => ['cache_*', 'temp_*']];
        $tables = ['users', 'cache_items', 'temp_data', 'orders'];
        $result = TableFilter::filterTables($tables, $params);
        sort($result);
        $this->assertSame(['orders', 'users'], $result);
    }

    /**
     * Include list is applied first, then exclude list narrows further.
     * A table in both lists is excluded (exclude wins as a refinement).
     */
    public function testFilterTablesIncludeAndExcludeCombined(): void
    {
        $params = (object) [
            'tables' => ['users', 'orders', 'wp_*'],
            'tablesToIgnore' => ['wp_wc_session*'],
        ];
        $tables = ['users', 'orders', 'sessions', 'wp_posts', 'wp_wc_sessions'];
        $result = TableFilter::filterTables($tables, $params);
        sort($result);
        // Include: users, orders, wp_posts, wp_wc_sessions
        // Exclude: wp_wc_sessions → removed
        $this->assertSame(['orders', 'users', 'wp_posts'], $result);
    }

    /**
     * tablesDataToIgnore — excluded only in data scope, not schema.
     */
    public function testFilterTablesDataOnlyExclude(): void
    {
        $params = (object) [
            'tables' => null,
            'tablesToIgnore' => null,
            'tablesDataToIgnore' => ['audit_log'],
        ];
        $tables = ['users', 'audit_log', 'orders'];

        // Schema scope — audit_log is NOT excluded
        $schema = TableFilter::filterTables($tables, $params, 'schema');
        sort($schema);
        $this->assertSame(['audit_log', 'orders', 'users'], $schema);

        // Data scope — audit_log IS excluded
        $data = TableFilter::filterTables($tables, $params, 'data');
        sort($data);
        $this->assertSame(['orders', 'users'], $data);
    }

    /**
     * tablesDataToIgnore supports globs.
     */
    public function testFilterTablesDataOnlyExcludeGlob(): void
    {
        $params = (object) [
            'tables' => null,
            'tablesToIgnore' => null,
            'tablesDataToIgnore' => ['log_*'],
        ];
        $tables = ['users', 'log_access', 'log_errors', 'orders'];
        $data = TableFilter::filterTables($tables, $params, 'data');
        sort($data);
        $this->assertSame(['orders', 'users'], $data);
    }

    /**
     * tableScope overrides — 'schema' means exclude from data, etc.
     */
    public function testFilterTablesTableScope(): void
    {
        $params = (object) [
            'tables' => null,
            'tablesToIgnore' => null,
            'tableScope' => [
                'audit_log' => 'schema',
                'config'    => 'data',
            ],
        ];
        $tables = ['users', 'audit_log', 'config', 'orders'];

        // Schema scope: audit_log (schema) ✓, config (data) ✗
        $schema = TableFilter::filterTables($tables, $params, 'schema');
        sort($schema);
        $this->assertSame(['audit_log', 'orders', 'users'], $schema);

        // Data scope: audit_log (schema) ✗, config (data) ✓
        $data = TableFilter::filterTables($tables, $params, 'data');
        sort($data);
        $this->assertSame(['config', 'orders', 'users'], $data);
    }

    /**
     * tableScope 'all' means included in both.
     */
    public function testFilterTablesTableScopeAll(): void
    {
        $params = (object) [
            'tables' => null,
            'tablesToIgnore' => null,
            'tableScope' => ['users' => 'all'],
        ];
        $tables = ['users', 'orders'];
        $this->assertSame(['users', 'orders'], TableFilter::filterTables($tables, $params, 'schema'));
        $this->assertSame(['users', 'orders'], TableFilter::filterTables($tables, $params, 'data'));
    }

    /**
     * scope = 'all' (the default scope argument) ignores tableScope entirely.
     */
    public function testFilterTablesAllScopeIgnoresTableScope(): void
    {
        $params = (object) [
            'tables' => null,
            'tablesToIgnore' => null,
            'tableScope' => ['audit_log' => 'schema'],
        ];
        $tables = ['audit_log', 'users'];
        // When scope is 'all', no tableScope filtering applies
        $result = TableFilter::filterTables($tables, $params, 'all');
        $this->assertSame(['audit_log', 'users'], $result);
    }

    /**
     * No filters set at all — returns all tables unchanged.
     */
    public function testFilterTablesNoFilters(): void
    {
        $params = (object) [];
        $tables = ['a', 'b', 'c'];
        $this->assertSame(['a', 'b', 'c'], TableFilter::filterTables($tables, $params));
    }

    /**
     * Empty include list — returns nothing (strict interpretation).
     */
    public function testFilterTablesEmptyIncludeListReturnsAll(): void
    {
        // null and empty array behave the same: no filtering
        $params = (object) ['tables' => []];
        $tables = ['users', 'orders'];
        // Empty array is falsy → no include filtering applied
        $this->assertSame(['users', 'orders'], TableFilter::filterTables($tables, $params));
    }

    /**
     * Combined: include + data exclude + tableScope.
     */
    public function testFilterTablesCombined(): void
    {
        $params = (object) [
            'tables' => ['wp_*', 'config'],
            'tablesToIgnore' => ['wp_wc_session*'],
            'tablesDataToIgnore' => ['wp_options'],
            'tableScope' => ['config' => 'data'],
        ];
        $tables = ['wp_posts', 'wp_options', 'wp_wc_sessions', 'config', 'unrelated'];

        // Schema: include wp_* and config, exclude wp_wc_session*,
        // config is data-only via tableScope → excluded from schema
        $schema = TableFilter::filterTables($tables, $params, 'schema');
        sort($schema);
        $this->assertSame(['wp_options', 'wp_posts'], $schema);

        // Data: include wp_* and config, exclude wp_wc_session*,
        // wp_options excluded via tablesDataToIgnore
        $data = TableFilter::filterTables($tables, $params, 'data');
        sort($data);
        $this->assertSame(['config', 'wp_posts'], $data);
    }

    // ── getFieldsToIgnore ─────────────────────────────────────────────────

    public function testGetFieldsToIgnoreExactMatch(): void
    {
        $params = (object) ['fieldsToIgnore' => [
            'users' => ['updated_at', 'last_login'],
        ]];
        $this->assertSame(['updated_at', 'last_login'], TableFilter::getFieldsToIgnore('users', $params));
        $this->assertSame([], TableFilter::getFieldsToIgnore('orders', $params));
    }

    public function testGetFieldsToIgnoreGlobKey(): void
    {
        $params = (object) ['fieldsToIgnore' => [
            'wp_*' => ['updated_at'],
            'users' => ['last_login'],
        ]];
        $this->assertSame(['updated_at'], TableFilter::getFieldsToIgnore('wp_posts', $params));
        $this->assertSame(['last_login'], TableFilter::getFieldsToIgnore('users', $params));
    }

    public function testGetFieldsToIgnoreMultipleGlobsMerge(): void
    {
        $params = (object) ['fieldsToIgnore' => [
            '*' => ['created_at'],
            'wp_*' => ['updated_at'],
        ]];
        $result = TableFilter::getFieldsToIgnore('wp_posts', $params);
        sort($result);
        $this->assertSame(['created_at', 'updated_at'], $result);
    }

    public function testGetFieldsToIgnoreDeduplicates(): void
    {
        $params = (object) ['fieldsToIgnore' => [
            '*' => ['updated_at'],
            'users' => ['updated_at', 'last_login'],
        ]];
        $result = TableFilter::getFieldsToIgnore('users', $params);
        sort($result);
        $this->assertSame(['last_login', 'updated_at'], $result);
    }

    public function testGetFieldsToIgnoreNullParam(): void
    {
        $params = (object) ['fieldsToIgnore' => null];
        $this->assertSame([], TableFilter::getFieldsToIgnore('users', $params));
    }

    public function testGetFieldsToIgnoreMissingProperty(): void
    {
        $params = (object) [];
        $this->assertSame([], TableFilter::getFieldsToIgnore('users', $params));
    }

    // ── getRowIgnoreRules ─────────────────────────────────────────────────

    public function testGetRowIgnoreRulesReturnsRules(): void
    {
        $params = (object) ['rowsToIgnore' => [
            'wp_options' => [
                ['column' => 'option_name', 'pattern' => '_transient_.*'],
            ],
        ]];
        $rules = TableFilter::getRowIgnoreRules('wp_options', $params);
        $this->assertCount(1, $rules);
        $this->assertSame('option_name', $rules[0]['column']);
    }

    public function testGetRowIgnoreRulesReturnsEmptyForUnmatched(): void
    {
        $params = (object) ['rowsToIgnore' => [
            'wp_options' => [['column' => 'option_name', 'pattern' => '_transient_.*']],
        ]];
        $this->assertSame([], TableFilter::getRowIgnoreRules('users', $params));
    }

    public function testGetRowIgnoreRulesNullParam(): void
    {
        $params = (object) [];
        $this->assertSame([], TableFilter::getRowIgnoreRules('users', $params));
    }

    // ── filterRows ────────────────────────────────────────────────────────

    public function testFilterRowsRemovesMatchingRows(): void
    {
        $rows = [
            ['id' => 1, 'option_name' => '_transient_timeout_abc', 'value' => '123'],
            ['id' => 2, 'option_name' => 'siteurl', 'value' => 'http://example.com'],
            ['id' => 3, 'option_name' => '_site_transient_foo', 'value' => 'bar'],
        ];
        $rules = [
            ['column' => 'option_name', 'pattern' => '_transient_.*'],
            ['column' => 'option_name', 'pattern' => '_site_transient_.*'],
        ];
        $result = TableFilter::filterRows($rows, $rules);
        $this->assertCount(1, $result);
        $this->assertSame('siteurl', $result[0]['option_name']);
    }

    public function testFilterRowsKeepsAllWhenNoRulesMatch(): void
    {
        $rows = [
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'pending'],
        ];
        $rules = [
            ['column' => 'status', 'pattern' => 'expired|archived'],
        ];
        $result = TableFilter::filterRows($rows, $rules);
        $this->assertCount(2, $result);
    }

    public function testFilterRowsEmptyRulesReturnsAll(): void
    {
        $rows = [['id' => 1], ['id' => 2]];
        $result = TableFilter::filterRows($rows, []);
        $this->assertCount(2, $result);
    }

    public function testFilterRowsHandlesMissingColumn(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'test'],
        ];
        $rules = [
            ['column' => 'nonexistent', 'pattern' => '.*'],
        ];
        // Missing column → treated as empty string, .* matches → row filtered
        $result = TableFilter::filterRows($rows, $rules);
        $this->assertCount(0, $result);
    }

    public function testFilterRowsRegexIsCaseAware(): void
    {
        $rows = [
            ['id' => 1, 'status' => 'Expired'],
            ['id' => 2, 'status' => 'expired'],
        ];
        $rules = [
            ['column' => 'status', 'pattern' => 'expired'],
        ];
        $result = TableFilter::filterRows($rows, $rules);
        // Only exact match removed (the regex is not case-insensitive by default)
        $this->assertCount(1, $result);
        $this->assertSame('Expired', $result[0]['status']);
    }

    public function testFilterRowsAnchoredMatch(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'prefix_match_suffix'],
            ['id' => 2, 'name' => 'match'],
        ];
        $rules = [
            ['column' => 'name', 'pattern' => 'match'],
        ];
        // The pattern is anchored with ^ and $ → only exact match is removed
        $result = TableFilter::filterRows($rows, $rules);
        $this->assertCount(1, $result);
        $this->assertSame('prefix_match_suffix', $result[0]['name']);
    }

    public function testFilterRowsAlternationRegex(): void
    {
        $rows = [
            ['id' => 1, 'status' => 'expired'],
            ['id' => 2, 'status' => 'archived'],
            ['id' => 3, 'status' => 'active'],
        ];
        $rules = [
            ['column' => 'status', 'pattern' => 'expired|archived'],
        ];
        $result = TableFilter::filterRows($rows, $rules);
        $this->assertCount(1, $result);
        $this->assertSame('active', $result[0]['status']);
    }

    public function testFilterRowsSkipsInvalidRules(): void
    {
        $rows = [['id' => 1, 'name' => 'test']];
        $rules = [
            ['column' => null, 'pattern' => '.*'],
            ['pattern' => '.*'],
            ['column' => 'name'],
        ];
        // All rules are invalid/incomplete → no filtering
        $result = TableFilter::filterRows($rows, $rules);
        $this->assertCount(1, $result);
    }

    public function testFilterRowsReindexes(): void
    {
        $rows = [
            ['id' => 1, 'status' => 'expired'],
            ['id' => 2, 'status' => 'active'],
            ['id' => 3, 'status' => 'expired'],
        ];
        $rules = [['column' => 'status', 'pattern' => 'expired']];
        $result = TableFilter::filterRows($rows, $rules);
        // array_values re-indexes
        $this->assertSame(0, array_key_first($result));
        $this->assertCount(1, $result);
    }

    // ── Edge cases ────────────────────────────────────────────────────────

    public function testFilterTablesPreservesOrder(): void
    {
        $params = (object) ['tables' => null, 'tablesToIgnore' => ['b']];
        $tables = ['c', 'a', 'b', 'd'];
        $result = TableFilter::filterTables($tables, $params);
        $this->assertSame(['c', 'a', 'd'], $result);
    }

    public function testFilterTablesEmptyInput(): void
    {
        $params = (object) ['tables' => ['*']];
        $result = TableFilter::filterTables([], $params);
        $this->assertSame([], $result);
    }

    public function testGlobMatchStarMatchesEmpty(): void
    {
        $this->assertTrue(TableFilter::globMatch('prefix*', 'prefix'));
    }

    public function testGlobMatchQuestionDoesNotMatchEmpty(): void
    {
        $this->assertFalse(TableFilter::globMatch('prefix?', 'prefix'));
    }
}
