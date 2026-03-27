<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\Diff\AddTable;
use DBDiff\Diff\DropTable;
use DBDiff\Diff\CreateView;
use DBDiff\Diff\DropView;
use DBDiff\Diff\AlterView;
use DBDiff\Diff\CreateTrigger;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\CreateRoutine;
use DBDiff\Diff\DropRoutine;

class DiffSorterProgrammableTest extends TestCase
{
    private DiffSorter $sorter;

    protected function setUp(): void
    {
        $this->sorter = new DiffSorter();
    }

    private function className($obj): string
    {
        return (new \ReflectionClass($obj))->getShortName();
    }

    /**
     * UP order: DropView/DropTrigger/DropRoutine come before AddTable,
     * and CreateView/CreateTrigger/CreateRoutine come after data ops.
     */
    public function testUpOrderDropsProgrammableBeforeTables(): void
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);

        $diffs = [
            new CreateView('v1', 'CREATE VIEW ...'),
            new AddTable('t1', $stub, 'source'),
            new DropView('v2', 'CREATE VIEW ...'),
            new CreateTrigger('trg1', 'products', 'CREATE TRIGGER ...'),
            new DropTrigger('trg2', 'products', 'CREATE TRIGGER ...'),
            new CreateRoutine('fn1', 'CREATE FUNCTION ...'),
            new DropRoutine('fn2', 'CREATE FUNCTION ...'),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names  = array_map([$this, 'className'], $sorted);

        // Drops of programmable objects before AddTable
        $dropViewIdx    = array_search('DropView', $names);
        $dropTriggerIdx = array_search('DropTrigger', $names);
        $dropRoutineIdx = array_search('DropRoutine', $names);
        $addTableIdx    = array_search('AddTable', $names);

        $this->assertLessThan($addTableIdx, $dropViewIdx);
        $this->assertLessThan($addTableIdx, $dropTriggerIdx);
        $this->assertLessThan($addTableIdx, $dropRoutineIdx);

        // Creates of programmable objects after AddTable
        $createViewIdx    = array_search('CreateView', $names);
        $createTriggerIdx = array_search('CreateTrigger', $names);
        $createRoutineIdx = array_search('CreateRoutine', $names);

        $this->assertGreaterThan($addTableIdx, $createViewIdx);
        $this->assertGreaterThan($addTableIdx, $createTriggerIdx);
        $this->assertGreaterThan($addTableIdx, $createRoutineIdx);
    }

    /**
     * DOWN order: programmable object operations before table operations.
     */
    public function testDownOrderProgrammableBeforeTables(): void
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);

        $diffs = [
            new AddTable('t1', $stub, 'source'),
            new DropTable('t2', $stub, 'target'),
            new CreateView('v1', 'CREATE VIEW ...'),
            new DropView('v2', 'CREATE VIEW ...'),
            new AlterView('v3', 'src def', 'tgt def'),
        ];

        $sorted = $this->sorter->sort($diffs, 'down');
        $names  = array_map([$this, 'className'], $sorted);

        // All view operations should come before table operations in DOWN
        $lastViewIdx  = max(
            array_search('DropView', $names),
            array_search('AlterView', $names),
            array_search('CreateView', $names)
        );
        $firstTableIdx = min(
            array_search('AddTable', $names),
            array_search('DropTable', $names)
        );

        $this->assertLessThan($firstTableIdx, $lastViewIdx);
    }

    /**
     * Views, triggers, and routines sort by name within their type.
     */
    public function testProgrammableObjectsSortByName(): void
    {
        $diffs = [
            new CreateView('z_view', 'CREATE VIEW ...'),
            new CreateView('a_view', 'CREATE VIEW ...'),
            new CreateView('m_view', 'CREATE VIEW ...'),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names  = array_map(fn($d) => $d->name, $sorted);

        $this->assertSame(['a_view', 'm_view', 'z_view'], $names);
    }
}
