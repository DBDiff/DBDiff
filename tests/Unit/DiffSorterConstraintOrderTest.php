<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\Diff\AddTable;
use DBDiff\Diff\DropTable;
use DBDiff\Diff\AlterTableAddConstraint;
use DBDiff\Diff\AlterTableDropConstraint;
use DBDiff\Diff\AlterTableChangeConstraint;

/**
 * Bug #41: FK dependency ordering — drop constraints before dropping
 * referenced tables (UP) and before dropping tables whose FKs were
 * added (DOWN).
 */
class DiffSorterConstraintOrderTest extends TestCase
{
    private DiffSorter $sorter;

    protected function setUp(): void
    {
        $this->sorter = new DiffSorter();
    }

    // ── UP: drop constraints before drop tables ───────────────────────────

    public function testUpDropConstraintBeforeDropTable(): void
    {
        $dropConstraint = new AlterTableDropConstraint('orders', 'fk_orders_user', []);
        $dropTable      = $this->makeDropTable('users');

        $diffs  = [$dropTable, $dropConstraint];
        $sorted = $this->sorter->sort($diffs, 'up');

        $classes = array_map(fn($d) => (new \ReflectionClass($d))->getShortName(), $sorted);

        $this->assertSame(
            ['AlterTableDropConstraint', 'DropTable'],
            $classes,
            'DROP CONSTRAINT must come before DROP TABLE in UP migration'
        );
    }

    public function testUpAddConstraintAfterAddTable(): void
    {
        $addTable      = $this->makeAddTable('users');
        $addConstraint = new AlterTableAddConstraint('orders', 'fk_orders_user', []);

        $diffs  = [$addConstraint, $addTable];
        $sorted = $this->sorter->sort($diffs, 'up');

        $classes = array_map(fn($d) => (new \ReflectionClass($d))->getShortName(), $sorted);

        $this->assertSame(
            ['AddTable', 'AlterTableAddConstraint'],
            $classes,
            'ADD TABLE must come before ADD CONSTRAINT in UP migration'
        );
    }

    // ── DOWN: reverse ordering ────────────────────────────────────────────

    public function testDownAddConstraintDroppedBeforeAddTableDropped(): void
    {
        // In DOWN: AddConstraint.getDown()=DROP CONSTRAINT, AddTable.getDown()=DROP TABLE
        // DROP CONSTRAINT must come before DROP TABLE
        $addConstraint = new AlterTableAddConstraint('orders', 'fk_orders_user', []);
        $addTable      = $this->makeAddTable('users');

        $diffs  = [$addTable, $addConstraint];
        $sorted = $this->sorter->sort($diffs, 'down');

        $classes = array_map(fn($d) => (new \ReflectionClass($d))->getShortName(), $sorted);

        $this->assertSame(
            ['AlterTableAddConstraint', 'AddTable'],
            $classes,
            'In DOWN, ADD CONSTRAINT (=DROP FK) must come before ADD TABLE (=DROP TABLE)'
        );
    }

    public function testDownDropConstraintReaddedAfterDropTableRecreated(): void
    {
        // In DOWN: DropTable.getDown()=CREATE TABLE, DropConstraint.getDown()=ADD CONSTRAINT
        // CREATE TABLE must come before ADD CONSTRAINT
        $dropConstraint = new AlterTableDropConstraint('orders', 'fk_orders_user', []);
        $dropTable      = $this->makeDropTable('users');

        $diffs  = [$dropConstraint, $dropTable];
        $sorted = $this->sorter->sort($diffs, 'down');

        $classes = array_map(fn($d) => (new \ReflectionClass($d))->getShortName(), $sorted);

        $this->assertSame(
            ['DropTable', 'AlterTableDropConstraint'],
            $classes,
            'In DOWN, DROP TABLE (=CREATE TABLE) must come before DROP CONSTRAINT (=ADD FK)'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeAddTable(string $name): AddTable
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);
        return new AddTable($name, $stub, 'source');
    }

    private function makeDropTable(string $name): DropTable
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);
        return new DropTable($name, $stub, 'target');
    }
}
