<?php

namespace Tests\Unit;

use DBDiff\DB\Schema\ColumnDependantPlan;
use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\Diff\AlterView;
use DBDiff\Diff\CreateView;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\DropView;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\MigrationGenerator;
use Diff\DiffOp\DiffOpChange;
use PHPUnit\Framework\TestCase;

/**
 * How a column type change's dependants fit around the rest of the migration.
 *
 * Each of these was a migration that failed or left the wrong result:
 *
 *   - a view the source dropped was recreated by the column change after
 *     DropView had removed it, and the next diff offered to drop it again;
 *   - a policy the source changed was recreated from its old definition,
 *     which is invalid against the column's new type;
 *   - on the way DOWN, that policy's own revert ran before the type was
 *     reverted, against the wrong type.
 */
class ColumnDependantPlanTest extends TestCase
{
    private function columnChange(array $views = [], array $policies = []): AlterTableChangeColumn
    {
        $diff = new AlterTableChangeColumn('orders', 'user_id', new DiffOpChange('"user_id" text', '"user_id" uuid'));
        $diff->dependants = [
            'views' => $views, 'policies' => $policies, 'triggers' => [], 'defaultGrantees' => [],
        ];
        return $diff;
    }

    private function view(string $name, array $triggers = []): array
    {
        return [
            'schema' => 'public', 'name' => $name, 'kind' => 'v', 'owner' => 'postgres', 'depth' => 1,
            'definition' => 'SELECT user_id FROM orders', 'options' => null, 'comment' => null,
            'columnComments' => [], 'triggers' => $triggers, 'indexes' => [], 'grants' => [],
        ];
    }

    private function policy(string $name): array
    {
        return [
            'schema' => 'public', 'table' => 'orders', 'name' => $name,
            'definition' => "CREATE POLICY \"$name\" ON \"orders\" FOR ALL USING ((user_id = (auth.uid())::text))",
        ];
    }

    public function testADroppedViewIsSkippedInTheUp(): void
    {
        $change = $this->columnChange([$this->view('mine')]);
        ColumnDependantPlan::apply([$change, new DropView('mine', 'CREATE VIEW "mine" AS SELECT 1')]);

        $this->assertArrayHasKey('public.mine', $change->upSkip);
    }

    public function testAnAlteredPolicyIsLeftToItsOwnDiffInTheUp(): void
    {
        $change = $this->columnChange([], [$this->policy('own')]);
        ColumnDependantPlan::apply([$change, new AlterPolicy('own', 'orders', 'CREATE POLICY "own" ON "orders" USING (true)', $this->policy('own')['definition'])]);

        $this->assertArrayHasKey('public.orders.own', $change->upSkip);
    }

    public function testADependantsOwnDownIsLeftToTheColumnChange(): void
    {
        $alterPolicy = new AlterPolicy('own', 'orders', 'CREATE POLICY "own" ON "orders" USING (true)', $this->policy('own')['definition']);
        $alterView   = new AlterView('mine', 'CREATE VIEW "mine" AS SELECT 2', 'CREATE VIEW "mine" AS SELECT 1');
        $dropTrigger = new DropTrigger('ins', 'mine', 'CREATE TRIGGER ins INSTEAD OF INSERT ON public.mine FOR EACH ROW EXECUTE FUNCTION f()');
        $unrelated   = new AlterView('other', 'CREATE VIEW "other" AS SELECT 2', 'CREATE VIEW "other" AS SELECT 1');

        ColumnDependantPlan::apply([
            $this->columnChange([$this->view('mine', [['name' => 'ins', 'definition' => 'x']])], [$this->policy('own')]),
            $alterPolicy, $alterView, $dropTrigger, $unrelated,
        ]);

        // A changed policy still comes off at its own point in the DOWN, so a
        // routine it calls can be dropped there; the column change recreates
        // the target's version.
        $this->assertFalse($alterPolicy->downHandledElsewhere);
        $this->assertTrue($alterPolicy->downDropOnly);
        $this->assertTrue($alterView->downHandledElsewhere);
        $this->assertTrue($dropTrigger->downHandledElsewhere, 'a trigger on a dependent view goes with the view');
        $this->assertFalse($unrelated->downHandledElsewhere, 'a view the column change does not touch keeps its DOWN');
    }

    public function testTheGeneratorLeavesOutAHandledDownButKeepsItsUp(): void
    {
        $previous = DialectRegistry::get();
        DialectRegistry::set(new PostgresDialect());
        try {
            $view = new AlterView('mine', 'CREATE VIEW "mine" AS SELECT 2', 'CREATE VIEW "mine" AS SELECT 1');
            $view->downHandledElsewhere = true;

            $this->assertStringContainsString('SELECT 2', MigrationGenerator::generate([$view], 'getUp'));
            $this->assertSame('', MigrationGenerator::generate([$view], 'getDown'));
        } finally {
            DialectRegistry::set($previous);
        }
    }

    public function testNothingChangesWithoutAColumnTypeChange(): void
    {
        $drop = new DropView('mine', 'CREATE VIEW "mine" AS SELECT 1');
        $create = new CreateView('theirs', 'CREATE VIEW "theirs" AS SELECT 1');
        ColumnDependantPlan::apply([$drop, $create]);

        $this->assertFalse($drop->downHandledElsewhere);
    }

    public function testADropOnlyPolicyDownIsJustTheDrop(): void
    {
        $previous = DialectRegistry::get();
        DialectRegistry::set(new PostgresDialect());
        try {
            $policy = new AlterPolicy('own', 'orders', 'CREATE POLICY "own" ON "orders" USING (true)', 'CREATE POLICY "own" ON "orders" USING (false)');
            $policy->downDropOnly = true;

            $this->assertSame('DROP POLICY IF EXISTS "own" ON "orders";' . "\n", MigrationGenerator::generate([$policy], 'getDown'));
        } finally {
            DialectRegistry::set($previous);
        }
    }
}
