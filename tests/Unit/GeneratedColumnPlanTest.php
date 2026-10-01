<?php

namespace Tests\Unit;

use DBDiff\DB\Schema\GeneratedColumnPlan;
use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\DiffToSQL\AlterTableChangeColumnSQL;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpChange;
use Diff\DiffOp\DiffOpRemove;
use PHPUnit\Framework\TestCase;

/**
 * How changing stored generated columns are applied (issue #233), and how an
 * inherited column's type change is left to its parent (issue #232).
 */
class GeneratedColumnPlanTest extends TestCase
{
    private const A_OLD = '"a" numeric(10,2)';
    private const A_NEW = '"a" numeric(12,2)';

    public function testAGeneratedColumnChangingWithTheColumnItReadsIsAttachedToThatChange(): void
    {
        $plan = GeneratedColumnPlan::plan([
            'a' => new DiffOpChange(self::A_OLD, self::A_NEW),
            'g' => new DiffOpChange('"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED', '"g" numeric GENERATED ALWAYS AS ((a * 3)) STORED'),
        ]);

        $this->assertSame(['g' => 'a'], $plan['attach']);
        $this->assertSame([], $plan['regenerate']);
    }

    public function testAnExpressionChangeAloneIsRegenerated(): void
    {
        // Before, only a stray DROP NOT NULL was emitted for this.
        $plan = GeneratedColumnPlan::plan([
            'g' => new DiffOpChange('"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED', '"g" numeric GENERATED ALWAYS AS ((a * 3)) STORED'),
        ]);

        $this->assertSame(['g' => true], $plan['regenerate']);
    }

    public function testAColumnCeasingToBeGeneratedIsPlannedForItsDown(): void
    {
        // The UP is a DROP EXPRESSION; the DOWN gives a plain column an
        // expression, which only a drop and re-add can.
        $plan = GeneratedColumnPlan::plan([
            'g' => new DiffOpChange('"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED', '"g" numeric'),
        ]);

        $this->assertSame(['g' => true], $plan['regenerate']);
    }

    public function testEachDirectionDecidesWhetherItRegenerates(): void
    {
        $diff = new AlterTableChangeColumn('t', 'g', new DiffOpChange('"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED', '"g" numeric'));
        $diff->regenerated = true;
        $diff->dependants = ['views' => [], 'policies' => [], 'triggers' => [], 'defaultGrantees' => [], 'generated' => [[
            'schema' => 'public', 'table' => 't', 'name' => 'g', 'type' => 'numeric', 'collation' => null,
            'expression' => '(a * 2)', 'notNull' => false,
        ]]];
        $sql = new AlterTableChangeColumnSQL($diff, new PostgresDialect());

        $this->assertSame('ALTER TABLE "t" ALTER COLUMN "g" DROP EXPRESSION;', $sql->getUp());
        $this->assertStringContainsString('ALTER TABLE "t" DROP COLUMN "g";', $sql->getDown());
        $this->assertStringContainsString('GENERATED ALWAYS AS ((a * 2)) STORED', $sql->getDown());
    }

    public function testANullabilityChangeAloneIsNotRegenerated(): void
    {
        $plan = GeneratedColumnPlan::plan([
            'g' => new DiffOpChange('"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED', '"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED NOT NULL'),
        ]);

        $this->assertSame(['attach' => [], 'regenerate' => [], 'dropFirst' => []], $plan);
    }

    public function testARemovedGeneratedColumnReadingARetypedColumnIsDroppedFirst(): void
    {
        $plan = GeneratedColumnPlan::plan([
            'a' => new DiffOpChange(self::A_OLD, self::A_NEW),
            'g' => new DiffOpRemove('"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED'),
        ]);

        $this->assertSame(['g' => true], $plan['dropFirst']);
    }

    public function testADefaultChangeOnTheReadColumnDoesNotForceAnything(): void
    {
        $plan = GeneratedColumnPlan::plan([
            'a' => new DiffOpChange('"a" numeric(10,2) DEFAULT 1', '"a" numeric(10,2) DEFAULT 2'),
            'g' => new DiffOpRemove('"g" numeric GENERATED ALWAYS AS ((a * 2)) STORED'),
            'h' => new DiffOpAdd('"h" numeric GENERATED ALWAYS AS ((a * 4)) STORED'),
        ]);

        $this->assertSame(['attach' => [], 'regenerate' => [], 'dropFirst' => []], $plan);
    }

    private function sqlFor(AlterTableChangeColumn $change): AlterTableChangeColumnSQL
    {
        return new AlterTableChangeColumnSQL($change, new PostgresDialect());
    }

    public function testAnInheritedColumnKeepsEverythingButTheTypeChange(): void
    {
        $change = new AlterTableChangeColumn('p1', 'a',
            new DiffOpChange('"a" numeric(10,2) DEFAULT 1', '"a" numeric(12,2) DEFAULT 5'));
        $change->typeInherited = true;

        $this->assertSame('ALTER TABLE "p1" ALTER COLUMN "a" SET DEFAULT 5;', $this->sqlFor($change)->getUp());
        $this->assertSame('ALTER TABLE "p1" ALTER COLUMN "a" SET DEFAULT 1;', $this->sqlFor($change)->getDown());
    }

    public function testAnInheritedColumnWithOnlyATypeChangeEmitsNothing(): void
    {
        $change = new AlterTableChangeColumn('p1', 'a', new DiffOpChange(self::A_OLD, self::A_NEW));
        $change->typeInherited = true;

        $this->assertSame('', $this->sqlFor($change)->getUp());
    }

    private function generated(array $extra = []): array
    {
        return $extra + [
            'schema' => 'public', 'table' => 't', 'name' => 'g', 'type' => 'numeric', 'collation' => null,
            'expression' => '(a * (2)::numeric)', 'notNull' => true, 'comment' => "'doubled'",
            'indexes' => ['CREATE INDEX t_g ON public.t USING btree (g)'],
            'constraints' => ['ALTER TABLE t ADD CONSTRAINT g_nonneg CHECK ((g >= (0)::numeric))'],
            'grants' => [['grantee' => 'reader', 'privilege' => 'SELECT', 'grantable' => false]],
        ];
    }

    public function testAGeneratedDependantIsDroppedBeforeAndReAddedWholeAfter(): void
    {
        $change = new AlterTableChangeColumn('t', 'a', new DiffOpChange(self::A_OLD, self::A_NEW));
        $change->dependants = ['views' => [], 'policies' => [], 'triggers' => [], 'defaultGrantees' => [],
            'generated' => [$this->generated()]];
        $sql = $this->sqlFor($change)->getUp();

        $drop  = strpos($sql, 'ALTER TABLE "t" DROP COLUMN "g";');
        $alter = strpos($sql, 'ALTER COLUMN "a" TYPE numeric(12,2)');
        $add   = strpos($sql, 'ALTER TABLE "t" ADD COLUMN "g" numeric GENERATED ALWAYS AS ((a * (2)::numeric)) STORED NOT NULL;');
        $this->assertTrue($drop !== false && $alter !== false && $add !== false, $sql);
        $this->assertLessThan($alter, $drop);
        $this->assertGreaterThan($alter, $add);
        foreach (['CREATE INDEX t_g', 'ADD CONSTRAINT g_nonneg', "COMMENT ON COLUMN \"t\".\"g\" IS 'doubled';",
                  'GRANT SELECT ("g") ON "t" TO reader;'] as $part) {
            $this->assertStringContainsString($part, $sql);
        }
        $this->assertStringNotContainsString('CASCADE', $sql);
    }

    public function testARegeneratedColumnComesBackAsTheSourceDefinesItOnTheWayUpOnly(): void
    {
        $change = new AlterTableChangeColumn('t', 'g', new DiffOpChange(
            '"g" numeric GENERATED ALWAYS AS ((a * (2)::numeric)) STORED',
            '"g" numeric GENERATED ALWAYS AS ((a * (3)::numeric)) STORED'
        ));
        $change->regenerated = true;
        $change->dependants = ['views' => [], 'policies' => [], 'triggers' => [], 'defaultGrantees' => [],
            'generated' => [$this->generated(['notNull' => false,
                'upDefinition' => '"g" numeric GENERATED ALWAYS AS ((a * (3)::numeric)) STORED'])]];

        $up = $this->sqlFor($change)->getUp();
        $down = $this->sqlFor($change)->getDown();

        $this->assertStringContainsString('ADD COLUMN "g" numeric GENERATED ALWAYS AS ((a * (3)::numeric)) STORED;', $up);
        $this->assertStringContainsString('ADD COLUMN "g" numeric GENERATED ALWAYS AS ((a * (2)::numeric)) STORED;', $down);
        $this->assertStringNotContainsString('ALTER COLUMN', $up, 'no ALTER: dropping and re-adding is the change');
    }
}
