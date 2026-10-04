<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\Diff\AlterTableAddColumn;
use DBDiff\Diff\AlterTableAddKey;
use DBDiff\Diff\AlterTableDropColumn;
use DBDiff\Diff\AlterTableDropKey;

/**
 * An index is dropped before the column it is on.
 *
 * DROP COLUMN ... CASCADE takes the column's indexes with it, so a DROP INDEX
 * sorted after it failed with `index "t_x" does not exist`: in UP when an
 * indexed column was removed, and in DOWN when one was added.
 */
class DiffSorterIndexColumnOrderTest extends TestCase
{
    private static function kinds(array $diffs): array
    {
        return array_map(fn($d) => (new \ReflectionClass($d))->getShortName(), $diffs);
    }

    public function testUpDropsTheIndexBeforeTheColumn(): void
    {
        $sorted = (new DiffSorter())->sort([
            new AlterTableDropColumn('t', 'x', null),
            new AlterTableDropKey('t', 't_x', null),
        ], 'up');
        $this->assertSame(['AlterTableDropKey', 'AlterTableDropColumn'], self::kinds($sorted));
    }

    public function testUpAddsTheColumnBeforeTheIndex(): void
    {
        $sorted = (new DiffSorter())->sort([
            new AlterTableAddKey('t', 't_x', null),
            new AlterTableAddColumn('t', 'x', null),
        ], 'up');
        $this->assertSame(['AlterTableAddColumn', 'AlterTableAddKey'], self::kinds($sorted));
    }

    // DOWN of an added indexed column: the index's DROP, then the column's.
    public function testDownUndoesTheIndexBeforeTheColumn(): void
    {
        $sorted = (new DiffSorter())->sort([
            new AlterTableAddColumn('t', 'x', null),
            new AlterTableAddKey('t', 't_x', null),
        ], 'down');
        $this->assertSame(['AlterTableAddKey', 'AlterTableAddColumn'], self::kinds($sorted));
    }

    // DOWN of a removed indexed column: the column back, then its index.
    public function testDownRestoresTheColumnBeforeTheIndex(): void
    {
        $sorted = (new DiffSorter())->sort([
            new AlterTableDropKey('t', 't_x', null),
            new AlterTableDropColumn('t', 'x', null),
        ], 'down');
        $this->assertSame(['AlterTableDropColumn', 'AlterTableDropKey'], self::kinds($sorted));
    }
}
