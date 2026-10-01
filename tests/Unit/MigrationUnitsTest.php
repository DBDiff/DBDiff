<?php

namespace DBDiff\Tests\Unit;

use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\Diff\CreateView;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\MigrationGenerator;
use Diff\DiffOp\DiffOpChange;
use PHPUnit\Framework\TestCase;

/**
 * `--units`: each change's statements marked as one unit, for tools that
 * apply changes one at a time.
 */
class MigrationUnitsTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        $this->previous = DialectRegistry::get();
        DialectRegistry::set(new PostgresDialect());
    }

    protected function tearDown(): void
    {
        DialectRegistry::set($this->previous);
    }

    public function testEachChangeIsOneMarkedUnit(): void
    {
        $column = new AlterTableChangeColumn('t', 'id', new DiffOpChange('"id" integer NOT NULL', '"id" bigint NOT NULL'));
        $policy = new AlterPolicy('own', 't', 'CREATE POLICY "own" ON "t" USING (true)', 'CREATE POLICY "own" ON "t" USING (false)');
        $view   = new CreateView('v', 'CREATE VIEW "v" AS SELECT 1');

        $this->assertSame(
            "-- dbdiff:unit AlterTableChangeColumn t.id\n"
            . "ALTER TABLE \"t\" ALTER COLUMN \"id\" TYPE bigint;\n"
            . "-- dbdiff:end\n"
            . "-- dbdiff:unit AlterPolicy t.own\n"
            . "DROP POLICY IF EXISTS \"own\" ON \"t\";\nCREATE POLICY \"own\" ON \"t\" USING (true);\n"
            . "-- dbdiff:end\n"
            . "-- dbdiff:unit CreateView v\n"
            . "CREATE VIEW \"v\" AS SELECT 1;\n"
            . "-- dbdiff:end\n",
            MigrationGenerator::generate([$column, $policy, $view], 'getUp', true)
        );
    }

    public function testWithoutTheFlagTheOutputIsUnchanged(): void
    {
        $view = new CreateView('v', 'CREATE VIEW "v" AS SELECT 1');

        $this->assertSame("CREATE VIEW \"v\" AS SELECT 1;\n", MigrationGenerator::generate([$view], 'getUp'));
    }

    public function testAChangeWithNothingToDoInADirectionHasNoUnit(): void
    {
        $view = new CreateView('v', 'CREATE VIEW "v" AS SELECT 1');
        $view->downHandledElsewhere = true;

        $this->assertSame('', MigrationGenerator::generate([$view], 'getDown', true));
    }
}
