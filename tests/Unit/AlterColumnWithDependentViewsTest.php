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
    /**
     * @param array<int, array<string, mixed>> $views
     * @param array<string, mixed>             $extra  policies, triggers, defaultGrantees
     */
    private function sqlFor(
        string $oldDef,
        string $newDef,
        array $views,
        array $extra = [],
        array $upPlan = ['skip' => [], 'replace' => []]
    ): AlterTableChangeColumnSQL {
        $diff = new AlterTableChangeColumn('orders', 'amount', new DiffOpChange($oldDef, $newDef));
        $diff->dependants = $extra + ['views' => $views, 'policies' => [], 'triggers' => [], 'defaultGrantees' => []];
        $diff->upPlan = $upPlan;
        return new AlterTableChangeColumnSQL($diff, new PostgresDialect());
    }

    private function view(string $name, int $depth, string $kind = 'v', array $indexes = [], array $extra = []): array
    {
        return $extra + [
            'schema'         => 'public',
            'name'           => $name,
            'kind'           => $kind,
            'owner'          => 'postgres',
            'depth'          => $depth,
            'definition'     => "SELECT 1 AS x FROM orders",
            'options'        => null,
            'comment'        => null,
            'columnComments' => [],
            'triggers'       => [],
            'indexes'        => $indexes,
            'grants'         => [],
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

    public function testANullabilityChangeAloneLeavesTheViewsInPlace(): void
    {
        // The dialect used to restate the type for every column change, and
        // PostgreSQL refuses a same-type `ALTER COLUMN ... TYPE` on a column a
        // view reads — so a change that only added NOT NULL dropped and
        // recreated every view on the column. It no longer restates it.
        $sql = $this->sqlFor(
            '"amount" numeric(12,2)',
            '"amount" numeric(12,2) NOT NULL',
            [$this->view('paid_orders', 1)]
        )->getUp();

        $this->assertSame('ALTER TABLE "orders" ALTER COLUMN "amount" SET NOT NULL;', $sql);
    }

    public function testADefaultChangeAloneLeavesTheViewsInPlace(): void
    {
        $sql = $this->sqlFor(
            '"amount" numeric(12,2) DEFAULT 1',
            '"amount" numeric(12,2) DEFAULT 5',
            [$this->view('paid_orders', 1, 'v', [], ['options' => 'security_invoker=true'])]
        )->getUp();

        $this->assertSame('ALTER TABLE "orders" ALTER COLUMN "amount" SET DEFAULT 5;', $sql);
    }

    public function testStatementsWithNoTypeChangeAreLeftAlone(): void
    {
        // The guard itself: SQL that does not retype anything passes through
        // untouched, so a future change to the dialect that stops restating the
        // type does not start dropping views for nothing.
        $diff = new AlterTableChangeColumn('orders', 'amount', new DiffOpChange('a', 'b'));
        $diff->dependants = ['views' => [$this->view('paid_orders', 1)], 'policies' => [], 'triggers' => []];

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

    // ── Putting a view back as it was ────────────────────────────────────────

    public function testAViewKeepsItsOptions(): void
    {
        // security_invoker above all: without it the view runs with its
        // owner's rights and bypasses the row level security of what it reads.
        $sql = $this->sqlFor(self::OLD, self::NEW, [
            $this->view('paid_orders', 1, 'v', [], ['options' => 'security_invoker=true']),
        ])->getUp();

        $this->assertStringContainsString('CREATE VIEW "paid_orders" WITH (security_invoker=true) AS', $sql);
    }

    public function testAViewKeepsItsGrantsCommentsAndTriggers(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [
            $this->view('paid_orders', 1, 'v', [], [
                'comment'        => "'Paid orders'",
                'columnComments' => [['column' => 'x', 'comment' => "'the x'"]],
                'triggers'       => [['name' => 'ins', 'definition' => 'CREATE TRIGGER ins INSTEAD OF INSERT ON public.paid_orders FOR EACH ROW EXECUTE FUNCTION f()']],
                'grants'         => [
                    ['grantee' => 'reader', 'privilege' => 'SELECT', 'grantable' => false],
                    ['grantee' => 'reader', 'privilege' => 'UPDATE', 'grantable' => false],
                    ['grantee' => 'admin',  'privilege' => 'SELECT', 'grantable' => true],
                ],
            ]),
        ])->getUp();

        $this->assertStringContainsString('GRANT SELECT, UPDATE ON "paid_orders" TO reader;', $sql);
        $this->assertStringContainsString('GRANT SELECT ON "paid_orders" TO admin WITH GRANT OPTION;', $sql);
        $this->assertStringContainsString('COMMENT ON VIEW "paid_orders" IS \'Paid orders\';', $sql);
        $this->assertStringContainsString('COMMENT ON COLUMN "paid_orders"."x" IS \'the x\';', $sql);
        $this->assertStringContainsString('CREATE TRIGGER ins INSTEAD OF INSERT ON public.paid_orders', $sql);
        $this->assertLessThan(
            strpos($sql, 'GRANT SELECT, UPDATE'),
            strpos($sql, 'CREATE VIEW "paid_orders"'),
            'grants go on after the view exists'
        );
    }

    public function testDefaultPrivilegesAreStrippedBeforeTheRecordedGrants(): void
    {
        // On Supabase default privileges grant anon everything on a new
        // relation. A view whose grants had been narrowed must not come back
        // wide open — and the owner's own privileges are never revoked.
        $sql = $this->sqlFor(self::OLD, self::NEW, [
            $this->view('paid_orders', 1, 'v', [], [
                'grants' => [['grantee' => 'authenticated', 'privilege' => 'SELECT', 'grantable' => false]],
            ]),
        ], ['defaultGrantees' => ['anon', 'authenticated', 'postgres']])->getUp();

        $this->assertStringContainsString('REVOKE ALL ON "paid_orders" FROM anon, authenticated;', $sql);
        $this->assertLessThan(
            strpos($sql, 'GRANT SELECT ON "paid_orders" TO authenticated;'),
            strpos($sql, 'REVOKE ALL ON "paid_orders"')
        );
    }

    public function testAViewInAnotherSchemaIsQualified(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [
            $this->view('orders_v', 1, 'v', [], ['schema' => 'api']),
        ])->getUp();

        $this->assertStringContainsString('DROP VIEW IF EXISTS "api"."orders_v";', $sql);
        $this->assertStringContainsString('CREATE VIEW "api"."orders_v" AS', $sql);
    }

    // ── Policies and triggers ────────────────────────────────────────────────

    public function testPoliciesAndTriggerConditionsStandAsideToo(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [], [
            'policies' => [[
                'schema' => 'public', 'table' => 'orders', 'name' => 'own',
                'definition' => 'CREATE POLICY "own" ON "orders" FOR ALL USING ((amount > (0)::numeric))',
            ]],
            'triggers' => [[
                'schema' => 'public', 'table' => 'orders', 'name' => 'big',
                'definition' => 'CREATE TRIGGER big BEFORE UPDATE ON public.orders FOR EACH ROW WHEN ((new.amount > (100)::numeric)) EXECUTE FUNCTION f()',
            ]],
        ])->getUp();

        $alter = strpos($sql, 'ALTER COLUMN "amount" TYPE');
        $this->assertLessThan($alter, strpos($sql, 'DROP POLICY IF EXISTS "own" ON "orders";'));
        $this->assertLessThan($alter, strpos($sql, 'DROP TRIGGER IF EXISTS "big" ON "orders";'));
        $this->assertGreaterThan($alter, strpos($sql, 'CREATE POLICY "own" ON "orders"'));
        $this->assertGreaterThan($alter, strpos($sql, 'CREATE TRIGGER big BEFORE UPDATE'));
    }

    public function testAPolicyReadingAViewIsDroppedBeforeTheView(): void
    {
        // DROP VIEW is refused while a policy depends on the view.
        $sql = $this->sqlFor(self::OLD, self::NEW, [$this->view('paid_orders', 1)], [
            'policies' => [[
                'schema' => 'public', 'table' => 'invoices', 'name' => 'paid',
                'definition' => 'CREATE POLICY "paid" ON "invoices" FOR SELECT USING ((EXISTS (SELECT 1 FROM paid_orders)))',
            ]],
        ])->getUp();

        $this->assertLessThan(
            strpos($sql, 'DROP VIEW IF EXISTS "paid_orders"'),
            strpos($sql, 'DROP POLICY IF EXISTS "paid" ON "invoices"')
        );
        $this->assertLessThan(
            strpos($sql, 'CREATE POLICY "paid"'),
            strpos($sql, 'CREATE VIEW "paid_orders"')
        );
    }

    // ── What the rest of the migration does to them ──────────────────────────

    public function testAViewTheMigrationDropsIsNotBroughtBack(): void
    {
        $sql = $this->sqlFor(self::OLD, self::NEW, [$this->view('paid_orders', 1)], [],
            ['skip' => ['public.paid_orders' => true], 'replace' => []])->getUp();

        $this->assertStringContainsString('DROP VIEW IF EXISTS "paid_orders"', $sql);
        $this->assertStringNotContainsString('CREATE VIEW "paid_orders"', $sql);
    }

    public function testAPolicyTheMigrationChangesComesBackAsTheSourceHasIt(): void
    {
        // `user_id = auth.uid()::text` is not valid once user_id is a uuid —
        // the very change being made — so the old definition cannot go back.
        $new = 'CREATE POLICY "own" ON "orders" FOR ALL USING ((amount > (1)::numeric))';
        $sql = $this->sqlFor(self::OLD, self::NEW, [], [
            'policies' => [[
                'schema' => 'public', 'table' => 'orders', 'name' => 'own',
                'definition' => 'CREATE POLICY "own" ON "orders" FOR ALL USING ((amount > (0)::numeric))',
            ]],
        ], ['skip' => [], 'replace' => ['public.orders.own' => $new]])->getUp();

        $this->assertStringContainsString($new . ';', $sql);
        $this->assertStringNotContainsString('(0)::numeric', $sql);
    }

    public function testTheDownIgnoresTheUpPlan(): void
    {
        // The DOWN restores the target, so everything goes back as it was.
        $sql = $this->sqlFor(self::OLD, self::NEW, [$this->view('paid_orders', 1)], [],
            ['skip' => ['public.paid_orders' => true], 'replace' => []])->getDown();

        $this->assertStringContainsString('CREATE VIEW "paid_orders"', $sql);
    }
}
