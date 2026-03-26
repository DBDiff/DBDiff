<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\Diff\AddTable;
use DBDiff\Diff\DropTable;
use DBDiff\Diff\AlterTableChangeColumn;

class DiffSorterFKTest extends TestCase
{
    private DiffSorter $sorter;

    protected function setUp(): void
    {
        $this->sorter = new DiffSorter();
    }

    // ── Helper ────────────────────────────────────────────────────────────

    private function makeAddTable(string $name, ?int $sortOrder = null): AddTable
    {
        // Stub manager — AddTableSQL::getUp() won't be called in sort tests
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);
        $diff = new AddTable($name, $stub, 'source');
        $diff->sortOrder = $sortOrder;
        return $diff;
    }

    private function makeDropTable(string $name, ?int $sortOrder = null): DropTable
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);
        $diff = new DropTable($name, $stub, 'target');
        $diff->sortOrder = $sortOrder;
        return $diff;
    }

    // ── AddTable UP: parents before children ──────────────────────────────

    public function testAddTableUpParentsBeforeChildren(): void
    {
        // accounts → users (accounts FK references users)
        // Topo order: users(0), accounts(1)
        // Without FK sorting, alphabetical would give: accounts, users (WRONG)
        $diffs = [
            $this->makeAddTable('accounts', 1),
            $this->makeAddTable('users', 0),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names = array_map(fn($d) => $d->table, $sorted);

        $this->assertSame(['users', 'accounts'], $names);
    }

    public function testAddTableUpThreeLevelChain(): void
    {
        // transactions → accounts → users
        $diffs = [
            $this->makeAddTable('transactions', 2),
            $this->makeAddTable('accounts', 1),
            $this->makeAddTable('users', 0),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names = array_map(fn($d) => $d->table, $sorted);

        $this->assertSame(['users', 'accounts', 'transactions'], $names);
    }

    // ── DropTable UP: children before parents ─────────────────────────────

    public function testDropTableUpChildrenBeforeParents(): void
    {
        // accounts → users: drop accounts first, then users
        $diffs = [
            $this->makeDropTable('users', 0),
            $this->makeDropTable('accounts', 1),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names = array_map(fn($d) => $d->table, $sorted);

        $this->assertSame(['accounts', 'users'], $names);
    }

    // ── AddTable DOWN: reverse of UP (children before parents) ────────────

    public function testAddTableDownChildrenBeforeParents(): void
    {
        // DOWN for AddTable = DROP TABLE (the reverse operation)
        $diffs = [
            $this->makeAddTable('users', 0),
            $this->makeAddTable('accounts', 1),
        ];

        $sorted = $this->sorter->sort($diffs, 'down');
        $names = array_map(fn($d) => $d->table, $sorted);

        $this->assertSame(['accounts', 'users'], $names);
    }

    // ── DropTable DOWN: parents before children (recreate) ────────────────

    public function testDropTableDownParentsBeforeChildren(): void
    {
        // DOWN for DropTable = CREATE TABLE (recreate)
        $diffs = [
            $this->makeDropTable('accounts', 1),
            $this->makeDropTable('users', 0),
        ];

        $sorted = $this->sorter->sort($diffs, 'down');
        $names = array_map(fn($d) => $d->table, $sorted);

        $this->assertSame(['users', 'accounts'], $names);
    }

    // ── No sortOrder falls back to alphabetical ───────────────────────────

    public function testNoSortOrderFallsBackToAlphabetical(): void
    {
        $diffs = [
            $this->makeAddTable('zebra', null),
            $this->makeAddTable('alpha', null),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names = array_map(fn($d) => $d->table, $sorted);

        $this->assertSame(['alpha', 'zebra'], $names);
    }

    // ── Mixed diffs: AddTable sorted among other types ────────────────────

    public function testMixedDiffTypesPreserveTypeOrder(): void
    {
        $alter = new AlterTableChangeColumn('zebra', 'col1', []);

        $diffs = [
            $alter,
            $this->makeAddTable('child_table', 1),
            $this->makeAddTable('parent_table', 0),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $classes = array_map(fn($d) => (new \ReflectionClass($d))->getShortName(), $sorted);

        // AddTable comes before AlterTableChangeColumn in up_order
        $this->assertSame(['AddTable', 'AddTable', 'AlterTableChangeColumn'], $classes);

        // Within AddTable, parent before child
        $addTables = array_filter($sorted, fn($d) => $d instanceof AddTable);
        $names = array_map(fn($d) => $d->table, $addTables);
        $this->assertSame(['parent_table', 'child_table'], array_values($names));
    }
}
