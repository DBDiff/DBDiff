<?php

namespace Tests\Unit;

use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\DiffToSQL\AlterTableChangeColumnSQL;
use Diff\DiffOp\DiffOpChange;
use PHPUnit\Framework\TestCase;

/**
 * Retyping a column that a view reads (issue #226).
 *
 * PostgreSQL refuses it outright:
 *
 *     ERROR:  cannot alter type of a column used by a view or rule
 *     DETAIL: rule _RETURN on view paid_orders depends on column "amount"
 *
 * so the bare `ALTER COLUMN ... TYPE` that used to be emitted was valid SQL
 * that could not run. The dependants have to stand aside and go back.
 */
class AlterColumnWithDependentViewsTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $dependants */
    private function sqlFor(
        string $oldDef,
        string $newDef,
        array $dependants
    ): AlterTableChangeColumnSQL {
        $diff = new AlterTableChangeColumn('orders', 'amount', new DiffOpChange($oldDef, $newDef));
        $diff->dependentViews = $dependants;
        return new AlterTableChangeColumnSQL($diff, new PostgresDialect());
    }

    private function view(string $name, int $depth, string $kind = 'v', array $indexes = []): array
    {
        return [
            'name'       => $name,
            'kind'       => $kind,
            'depth'      => $depth,
            'definition' => "SELECT 1 AS x FROM orders",
            'indexes'    => $indexes,
        ];
    }

    private const OLD = '"amount" numeric(14,2) NOT NULL';
    private const NEW = '"amount" numeric(12,2) NOT NULL';

    public function testDependentViewIsDroppedBeforeTheAlterAndRecreatedAfter(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [$this->view('paid_orders', 1)])->getUp();

        $drop   = strpos($sql, 'DROP VIEW IF EXISTS "paid_orders"');
        $alter  = strpos($sql, 'ALTER COLUMN "amount" TYPE');
        $create = strpos($sql, 'CREATE VIEW "paid_orders"');

        $this->assertNotFalse($drop);
        $this->assertNotFalse($alter);
        $this->assertNotFalse($create);
        $this->assertLessThan($alter, $drop, 'the view must be dropped before the column is retyped');
        $this->assertLessThan($create, $alter, 'and recreated after');
    }

    public function testNestedViewsAreDroppedDeepestFirstAndRecreatedShallowestFirst(): void
    {
        // paid_summary reads paid_orders, so it has to go first and come back
        // last — otherwise the drop fails, and so does the recreate.
        $sql = $this->sqlFor(self::OLD, self::NEW, [
            $this->view('paid_orders', 1),
            $this->view('paid_summary', 2),
        ])->getUp();

        $this->assertLessThan(
            strpos($sql, 'DROP VIEW IF EXISTS "paid_orders"'),
            strpos($sql, 'DROP VIEW IF EXISTS "paid_summary"')
        );
        $this->assertLessThan(
            strpos($sql, 'CREATE VIEW "paid_summary"'),
            strpos($sql, 'CREATE VIEW "paid_orders"')
        );
    }

    public function testAMaterializedViewUsesItsOwnKeyword(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [$this->view('order_totals', 1, 'm')])->getUp();

        $this->assertStringContainsString('DROP MATERIALIZED VIEW IF EXISTS "order_totals"', $sql);
        $this->assertStringContainsString('CREATE MATERIALIZED VIEW "order_totals"', $sql);
    }

    public function testAMaterializedViewsIndexesComeBackWithIt(): void
    {
        // Nothing else recreates them: the index path takes its relations from
        // getTables(), which never lists a matview.
        $sql = $this->sqlFor(self::OLD, self::NEW, [
            $this->view('order_totals', 1, 'm', ['CREATE UNIQUE INDEX order_totals_n ON public.order_totals USING btree (n)']),
        ])->getUp();

        $this->assertStringContainsString('CREATE UNIQUE INDEX order_totals_n', $sql);
        $this->assertLessThan(
            strpos($sql, 'CREATE UNIQUE INDEX order_totals_n'),
            strpos($sql, 'CREATE MATERIALIZED VIEW "order_totals"')
        );
    }

    public function testTheDownDirectionIsWrappedToo(): void
    {
        // Reverting the change retypes the column as well.
        $sql = $this->sqlFor(self::OLD, self::NEW, [$this->view('paid_orders', 1)])->getDown();

        $this->assertStringContainsString('DROP VIEW IF EXISTS "paid_orders"', $sql);
        $this->assertStringContainsString('CREATE VIEW "paid_orders"', $sql);
    }

    // ── When it must not interfere ───────────────────────────────────────────

    public function testNothingIsDroppedWhenNoViewDependsOnTheColumn(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [])->getUp();

        $this->assertStringNotContainsString('DROP VIEW', $sql);
        $this->assertStringContainsString('ALTER COLUMN "amount" TYPE', $sql);
    }

    public function testAnyChangeCarryingATypeStatementIsWrapped(): void
    {
        // The dialect restates the type for every column change, including one
        // that only adds NOT NULL. PostgreSQL refuses `ALTER COLUMN ... TYPE`
        // on a column a view reads even when the type is unchanged, so the
        // wrap is needed here too rather than being over-eager.
        $sql = $this->sqlFor(
            '"amount" numeric(12,2)',
            '"amount" numeric(12,2) NOT NULL',
            [$this->view('paid_orders', 1)]
        )->getUp();

        $this->assertStringContainsString('DROP VIEW IF EXISTS "paid_orders"', $sql);
        $this->assertStringContainsString('SET NOT NULL', $sql);
        $this->assertStringContainsString('CREATE VIEW "paid_orders"', $sql);
    }

    public function testStatementsWithNoTypeChangeAreLeftAlone(): void
    {
        // The guard itself: SQL that does not retype anything passes through
        // untouched, so a future change to the dialect that stops restating the
        // type does not start dropping views for nothing.
        $diff = new AlterTableChangeColumn('orders', 'amount', new DiffOpChange('a', 'b'));
        $diff->dependentViews = [$this->view('paid_orders', 1)];

        $dialect = new class extends PostgresDialect {
            public function changeColumn(string $table, string $col, string $newDef, string $oldDef = ''): string {
                return 'ALTER TABLE "orders" ALTER COLUMN "amount" SET NOT NULL;';
            }
        };

        $sql = (new AlterTableChangeColumnSQL($diff, $dialect))->getUp();

        $this->assertStringNotContainsString('DROP VIEW', $sql);
        $this->assertSame('ALTER TABLE "orders" ALTER COLUMN "amount" SET NOT NULL;', $sql);
    }

    public function testEveryDependantIsRecreated(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [
            $this->view('a', 1),
            $this->view('b', 1),
            $this->view('c', 2),
        ])->getUp();

        $this->assertSame(3, substr_count($sql, 'DROP VIEW IF EXISTS'));
        $this->assertSame(3, substr_count($sql, 'CREATE VIEW'));
    }
}
