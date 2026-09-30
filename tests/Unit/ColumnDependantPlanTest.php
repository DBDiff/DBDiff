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

        $this->assertArrayHasKey('public.mine', $change->upPlan['skip']);
    }

    public function testAnAlteredPolicyIsReplacedBySourceDefinition(): void
    {
        $source = 'CREATE POLICY "own" ON "orders" FOR ALL USING ((user_id = auth.uid()))';
        $change = $this->columnChange([], [$this->policy('own')]);
        ColumnDependantPlan::apply([$change, new AlterPolicy('own', 'orders', $source, $this->policy('own')['definition'])]);

        $this->assertSame($source, $change->upPlan['replace']['public.orders.own']);
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

        $this->assertTrue($alterPolicy->downHandledElsewhere);
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
}
