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
use DBDiff\Diff\CreateEnum;
use DBDiff\Diff\DropEnum;
use DBDiff\Diff\AlterEnum;

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
     * UP order: DropView/DropTrigger/DropRoutine come before AddTable, and
     * CreateView/CreateTrigger come after data ops.
     *
     * CreateRoutine is no longer in that trailing group — see
     * testUpOrderCreateRoutineBeforeItsCallers.
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

    /**
     * UP order: DropEnum before DropView (enums may be referenced by views/tables).
     */
    public function testUpOrderDropEnumBeforeDropView(): void
    {
        $diffs = [
            new DropView('v1', 'CREATE VIEW ...'),
            new DropEnum('status', 'CREATE TYPE "status" AS ENUM (\'a\')'),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names  = array_map([$this, 'className'], $sorted);

        $this->assertSame(['DropEnum', 'DropView'], $names);
    }

    /**
     * UP order: CreateEnum after data ops but before CreateView.
     */
    public function testUpOrderCreateEnumBeforeCreateView(): void
    {
        $diffs = [
            new CreateView('v1', 'CREATE VIEW ...'),
            new CreateEnum('status', 'CREATE TYPE "status" AS ENUM (\'a\')'),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names  = array_map([$this, 'className'], $sorted);

        $this->assertSame(['CreateEnum', 'CreateView'], $names);
    }

    /**
     * DOWN order: Enum operations come after view/trigger/routine operations.
     */
    public function testDownOrderEnumAfterViews(): void
    {
        $diffs = [
            new DropEnum('e1', 'CREATE TYPE "e1" AS ENUM (\'x\')'),
            new DropView('v1', 'CREATE VIEW ...'),
        ];

        $sorted = $this->sorter->sort($diffs, 'down');
        $names  = array_map([$this, 'className'], $sorted);

        $this->assertSame(['DropView', 'DropEnum'], $names);
    }

    /**
     * UP order: DropEnum before AddTable (tables may reference enum types).
     */
    public function testUpOrderDropEnumBeforeAddTable(): void
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);

        $diffs = [
            new AddTable('t1', $stub, 'source'),
            new DropEnum('old_type', 'CREATE TYPE "old_type" AS ENUM (\'x\')'),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names  = array_map([$this, 'className'], $sorted);

        $this->assertSame(['DropEnum', 'AddTable'], $names);
    }

    /**
     * A function must exist before anything that calls it.
     *
     * CreateRoutine used to sort last, after CreateView and CreateTrigger, so a
     * trigger whose function was also new was emitted before the function:
     *
     *   CREATE TRIGGER tg ... EXECUTE FUNCTION f();
     *   CREATE OR REPLACE FUNCTION public.f() ...
     *   ERROR: function f() does not exist
     *
     * The fix shipped in 3.0.0-rc.9 but had no test of its own — it was only
     * covered incidentally by a partition-trigger test that happens to need a
     * function, so a regression would have pointed at triggers rather than at
     * ordering. Views have the same dependency and are asserted here too.
     */
    public function testUpOrderCreateRoutineBeforeItsCallers(): void
    {
        $diffs = [
            new CreateTrigger('trg1', 't1', 'CREATE TRIGGER ...'),
            new CreateView('v1', 'CREATE VIEW ...'),
            new CreateRoutine('fn1', 'CREATE FUNCTION ...'),
        ];

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'up'));

        $routineIdx = array_search('CreateRoutine', $names);
        $viewIdx    = array_search('CreateView', $names);
        $triggerIdx = array_search('CreateTrigger', $names);

        $this->assertLessThan($viewIdx, $routineIdx, 'CreateRoutine before CreateView');
        $this->assertLessThan($triggerIdx, $routineIdx, 'CreateRoutine before CreateTrigger');
    }

    /**
     * UP order: CreateEnum must come BEFORE AddTable. A table with an enum
     * column cannot be created until the type exists — PostgreSQL rejects it
     * with 'type "x" does not exist'. Enums are Postgres-only here (the MySQL
     * and SQLite adapters both report no enums), so ordering them ahead of
     * tables affects nothing else.
     */
    public function testUpOrderCreateEnumBeforeTableAndView(): void
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);

        $diffs = [
            new CreateView('v1', 'CREATE VIEW ...'),
            new AddTable('t1', $stub, 'source'),
            new CreateEnum('status', 'CREATE TYPE "status" AS ENUM (\'a\')'),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names  = array_map([$this, 'className'], $sorted);

        $addIdx    = array_search('AddTable', $names);
        $enumIdx   = array_search('CreateEnum', $names);
        $viewIdx   = array_search('CreateView', $names);
        $this->assertLessThan($addIdx, $enumIdx, 'CreateEnum before AddTable');
        $this->assertLessThan($viewIdx, $enumIdx, 'CreateEnum before CreateView');
    }

    /**
     * Full lifecycle: enum + view + trigger + table in a single UP sort.
     */
    public function testUpFullLifecycleSort(): void
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);

        $diffs = [
            new CreateView('v1', 'CREATE VIEW ...'),
            new CreateEnum('e1', 'CREATE TYPE "e1" AS ENUM (\'a\')'),
            new AddTable('t1', $stub, 'source'),
            new DropView('v2', 'CREATE VIEW ...'),
            new DropEnum('e2', 'CREATE TYPE "e2" AS ENUM (\'b\')'),
            new CreateTrigger('trg1', 't1', 'CREATE TRIGGER ...'),
            new DropTrigger('trg2', 't1', 'CREATE TRIGGER ...'),
        ];

        $sorted = $this->sorter->sort($diffs, 'up');
        $names  = array_map([$this, 'className'], $sorted);

        // Drops come first: DropEnum → DropView → DropTrigger
        $dropEnumIdx   = array_search('DropEnum', $names);
        $dropViewIdx   = array_search('DropView', $names);
        $dropTrigIdx   = array_search('DropTrigger', $names);
        $addTableIdx   = array_search('AddTable', $names);
        $createEnumIdx = array_search('CreateEnum', $names);
        $createViewIdx = array_search('CreateView', $names);
        $createTrigIdx = array_search('CreateTrigger', $names);

        // All drops before AddTable
        $this->assertLessThan($addTableIdx, $dropEnumIdx);
        $this->assertLessThan($addTableIdx, $dropViewIdx);
        $this->assertLessThan($addTableIdx, $dropTrigIdx);

        // Enums precede the tables that may reference them
        $this->assertLessThan($addTableIdx, $createEnumIdx);

        // Views and triggers still come after the tables they depend on
        $this->assertGreaterThan($addTableIdx, $createViewIdx);
        $this->assertGreaterThan($addTableIdx, $createTrigIdx);

        // CreateEnum before CreateView
        $this->assertLessThan($createViewIdx, $createEnumIdx);
    }
}
