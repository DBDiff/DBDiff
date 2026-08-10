<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBDiff\DB\Support\QueryHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared DB query helpers.
 *
 * Covers:
 *  - Positional placeholder generation for IN (...) clauses
 *  - Table-map narrowing used by getSchemaHashMap() on every adapter
 */
class QueryHelperTest extends TestCase
{
    // ── placeholders ──────────────────────────────────────────────────────

    public function testPlaceholdersForSingleValue(): void
    {
        $this->assertSame('?', QueryHelper::placeholders(['a']));
    }

    public function testPlaceholdersForMultipleValues(): void
    {
        $this->assertSame('?,?,?', QueryHelper::placeholders(['a', 'b', 'c']));
    }

    public function testPlaceholdersCountMatchesBindingCount(): void
    {
        $values = range(1, 50);
        $ph     = QueryHelper::placeholders($values);
        $this->assertCount(count($values), explode(',', $ph));
    }

    public function testPlaceholdersForEmptyListIsEmptyString(): void
    {
        // IN () is invalid SQL on every supported driver, so callers must guard
        // the empty case. The helper stays total rather than throwing.
        $this->assertSame('', QueryHelper::placeholders([]));
    }

    public function testPlaceholdersIgnoresKeys(): void
    {
        // Non-sequential keys must not change the placeholder count.
        $this->assertSame('?,?', QueryHelper::placeholders([5 => 'a', 9 => 'b']));
    }

    // ── restrictToTables ──────────────────────────────────────────────────

    public function testRestrictToTablesKeepsOnlyRequested(): void
    {
        $map = ['users' => 'h1', 'orders' => 'h2', 'logs' => 'h3'];
        $this->assertSame(
            ['users' => 'h1', 'logs' => 'h3'],
            QueryHelper::restrictToTables($map, ['users', 'logs'])
        );
    }

    public function testRestrictToTablesWithEmptyListReturnsEverything(): void
    {
        // An empty filter means "no restriction" — this is what getSchemaHashMap()
        // relies on when called without an explicit table list.
        $map = ['users' => 'h1', 'orders' => 'h2'];
        $this->assertSame($map, QueryHelper::restrictToTables($map, []));
    }

    public function testRestrictToTablesIgnoresUnknownNames(): void
    {
        $map = ['users' => 'h1'];
        $this->assertSame(
            ['users' => 'h1'],
            QueryHelper::restrictToTables($map, ['users', 'does_not_exist'])
        );
    }

    public function testRestrictToTablesPreservesOriginalOrder(): void
    {
        $map = ['a' => 1, 'b' => 2, 'c' => 3];
        $this->assertSame(
            ['a' => 1, 'b' => 2, 'c' => 3],
            QueryHelper::restrictToTables($map, ['c', 'b', 'a'])
        );
    }

    public function testRestrictToTablesReturnsEmptyWhenNothingMatches(): void
    {
        $this->assertSame([], QueryHelper::restrictToTables(['a' => 1], ['z']));
    }

    public function testRestrictToTablesOnEmptyMap(): void
    {
        $this->assertSame([], QueryHelper::restrictToTables([], ['a']));
    }
}
