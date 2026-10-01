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
use DBDiff\Diff\CreateDomain;
use DBDiff\Diff\CreateSequence;
use DBDiff\Diff\DropDomain;
use DBDiff\Diff\DropSequence;
use DBDiff\Diff\CreateCompositeType;

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
     * UP order: views and triggers the source no longer has come off before
     * any table is touched; routines it no longer has are dropped last, once
     * the triggers, views, defaults and tables calling them are gone (issue
     * #238). New views and triggers come after the tables.
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

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'up'));
        $addTableIdx = array_search('AddTable', $names);

        $this->assertLessThan($addTableIdx, array_search('DropView', $names));
        $this->assertLessThan($addTableIdx, array_search('DropTrigger', $names));
        $this->assertSame('DropRoutine', end($names));

        $this->assertGreaterThan($addTableIdx, array_search('CreateView', $names));
        $this->assertGreaterThan($addTableIdx, array_search('CreateTrigger', $names));
        $this->assertGreaterThan($addTableIdx, array_search('CreateRoutine', $names));
    }

    /**
     * DOWN order: views the UP created or changed are taken back before the
     * tables are reverted; a view the UP dropped is recreated after them,
     * since it may read a table or column the DOWN puts back (issue #238).
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

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'down'));
        $firstTableIdx = min(array_search('AddTable', $names), array_search('DropTable', $names));
        $lastTableIdx  = max(array_search('AddTable', $names), array_search('DropTable', $names));

        $this->assertLessThan($firstTableIdx, array_search('CreateView', $names));
        $this->assertLessThan($firstTableIdx, array_search('AlterView', $names));
        $this->assertGreaterThan($lastTableIdx, array_search('DropView', $names));
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
     * UP order: an enum the source no longer has is dropped after the views
     * that may use it — the other way round, DROP TYPE failed with "cannot
     * drop type ... because other objects depend on it" (issue #238).
     */
    public function testUpOrderDropEnumAfterDropView(): void
    {
        $diffs = [
            new DropEnum('status', 'CREATE TYPE "status" AS ENUM (\'a\')'),
            new DropView('v1', 'CREATE VIEW ...'),
        ];

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'up'));

        $this->assertSame(['DropView', 'DropEnum'], $names);
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
     * DOWN order: an enum the UP dropped is recreated before a view the UP
     * dropped, which may use it (issue #238).
     */
    public function testDownOrderRecreatesEnumBeforeViews(): void
    {
        $diffs = [
            new DropView('v1', 'CREATE VIEW ...'),
            new DropEnum('e1', 'CREATE TYPE "e1" AS ENUM (\'x\')'),
        ];

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'down'));

        $this->assertSame(['DropEnum', 'DropView'], $names);
    }

    /**
     * UP order: an enum, domain, composite or sequence the source no longer
     * has is dropped after the tables — a dropped table or column may still
     * be typed by it, or default to it (issue #238).
     */
    public function testUpOrderDroppedTypesAfterTables(): void
    {
        $stub = $this->createMock(\DBDiff\DB\DBManager::class);

        $diffs = [
            new DropEnum('old_type', 'CREATE TYPE "old_type" AS ENUM (\'x\')'),
            new DropDomain('d', 'CREATE DOMAIN "d" AS int'),
            new DropSequence('s', 'CREATE SEQUENCE "s"'),
            new DropTable('t1', $stub, 'target'),
            new AddTable('t2', $stub, 'source'),
        ];

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'up'));

        $this->assertSame(['AddTable', 'DropTable', 'DropSequence', 'DropDomain', 'DropEnum'], $names);
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

        // Views and triggers come off first; the enum only once nothing can
        // use it.
        $dropEnumIdx   = array_search('DropEnum', $names);
        $dropViewIdx   = array_search('DropView', $names);
        $dropTrigIdx   = array_search('DropTrigger', $names);
        $addTableIdx   = array_search('AddTable', $names);
        $createEnumIdx = array_search('CreateEnum', $names);
        $createViewIdx = array_search('CreateView', $names);
        $createTrigIdx = array_search('CreateTrigger', $names);

        $this->assertSame(count($names) - 1, $dropEnumIdx);
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

    /**
     * A routine a table's default, check or index calls is created before
     * the tables (issue #238); any other stays after them.
     */
    public function testUpOrderAnEarlyRoutineGoesBeforeTheTables(): void
    {
        $stub  = $this->createMock(\DBDiff\DB\DBManager::class);
        $early = new CreateRoutine('f', 'CREATE FUNCTION f() ...');
        $early->early = true;
        $diffs = [new AddTable('t1', $stub, 'source'), new CreateRoutine('g', 'CREATE FUNCTION g() ...'), $early];

        $sorted = $this->sorter->sort($diffs, 'up');

        $this->assertSame([$early, $diffs[0], $diffs[1]], $sorted);
    }

    /**
     * The DOWN drops what the UP created only once nothing uses it: after the
     * tables and columns that may be typed by it or call it (issue #238).
     */
    public function testDownOrderDropsCreatedTypesAndRoutinesLast(): void
    {
        $stub  = $this->createMock(\DBDiff\DB\DBManager::class);
        $diffs = [
            new CreateEnum('e', "CREATE TYPE \"e\" AS ENUM ('a')"),
            new CreateDomain('d', 'CREATE DOMAIN "d" AS int'),
            new CreateSequence('s', 'CREATE SEQUENCE "s"'),
            new CreateCompositeType('c', 'CREATE TYPE "c" AS (a int)'),
            new CreateRoutine('f', 'CREATE FUNCTION f() ...'),
            new AddTable('t1', $stub, 'source'),
        ];

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'down'));

        $this->assertSame('AddTable', $names[0]);
        $this->assertSame(['CreateRoutine', 'CreateCompositeType', 'CreateDomain', 'CreateEnum', 'CreateSequence'], array_slice($names, 1));
    }

    /**
     * The DOWN changes an enum's labels before it puts back the routines,
     * views and policies that may name them (issue #237).
     */
    public function testDownOrderAlterEnumBeforeProgrammableObjects(): void
    {
        $diffs = [
            new AlterView('v', 'CREATE VIEW ...', 'CREATE VIEW ...'),
            new DropRoutine('f', 'CREATE FUNCTION ...'),
            new AlterEnum('e', "CREATE TYPE \"e\" AS ENUM ('a')", "CREATE TYPE \"e\" AS ENUM ('a', 'b')"),
        ];

        $names = array_map([$this, 'className'], $this->sorter->sort($diffs, 'down'));

        $this->assertSame('AlterEnum', $names[0]);
    }
}
