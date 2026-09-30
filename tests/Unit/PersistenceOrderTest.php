<?php

namespace Tests\Unit;

use DBDiff\Diff\AlterTablePersistence;
use DBDiff\SQLGen\DiffSorter;
use PHPUnit\Framework\TestCase;

/**
 * LOGGED/UNLOGGED changes follow foreign keys (issue #229).
 *
 * A logged table cannot reference an unlogged one, so the referenced table
 * becomes logged first and unlogged last:
 *
 *     ERROR:  could not change table "c" to logged because it references
 *             unlogged table "p"
 *
 * sortOrder is the foreign-key rank DBSchema assigns, parents first.
 */
class PersistenceOrderTest extends TestCase
{
    private function change(string $table, bool $unlogged, int $rank): AlterTablePersistence
    {
        $diff = new AlterTablePersistence($table, $unlogged, !$unlogged);
        $diff->sortOrder = $rank;
        return $diff;
    }

    private function tables(array $sorted): array
    {
        return array_map(fn($d) => $d->table, $sorted);
    }

    public function testBecomingLoggedGoesParentsFirst(): void
    {
        // Named so that alphabetical order would be wrong.
        $sorted = (new DiffSorter())->sort([
            $this->change('a_child', false, 1),
            $this->change('z_parent', false, 0),
        ], 'up');

        $this->assertSame(['z_parent', 'a_child'], $this->tables($sorted));
    }

    public function testBecomingUnloggedGoesChildrenFirst(): void
    {
        $sorted = (new DiffSorter())->sort([
            $this->change('z_parent', true, 0),
            $this->change('a_child', true, 1),
        ], 'up');

        $this->assertSame(['a_child', 'z_parent'], $this->tables($sorted));
    }

    public function testTheDownRevertsInTheOppositeOrder(): void
    {
        // UP makes both logged; the DOWN makes them unlogged again.
        $sorted = (new DiffSorter())->sort([
            $this->change('z_parent', false, 0),
            $this->change('a_child', false, 1),
        ], 'down');

        $this->assertSame(['a_child', 'z_parent'], $this->tables($sorted));
    }
}
