<?php

namespace DBDiff\Tests\Unit;

use DBDiff\Diff\AlterEnum;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\DiffToSQL\AlterEnumSQL;
use DBDiff\SQLGen\DiffToSQL\EnumSwapSQL;
use PHPUnit\Framework\TestCase;

/**
 * An enum label removed or reordered by moving to a new type (issue #237).
 * What the plan holds is read live (EnumSwapPlan); here it is given.
 */
class EnumSwapSQLTest extends TestCase
{
    private const THREE = "CREATE TYPE \"st\" AS ENUM ('new', 'paid', 'shipped')";
    private const TWO   = "CREATE TYPE \"st\" AS ENUM ('new', 'shipped')";

    private function plan(array $usage = [], array $dependants = [], array $blockers = []): array
    {
        return [
            'usage' => $usage + [
                'columns' => [
                    ['table' => 'o', 'column' => 's', 'array' => false, 'inherited' => false, 'partitionKey' => false,
                     'default' => "'new'::st", 'restoreDefault' => true],
                    ['table' => 'o', 'column' => 'arr', 'array' => true, 'inherited' => false, 'partitionKey' => false,
                     'default' => "'{paid}'::st[]", 'restoreDefault' => false],
                    ['table' => 'kid', 'column' => 's', 'array' => false, 'inherited' => true, 'partitionKey' => false,
                     'default' => "'shipped'::st", 'restoreDefault' => true],
                ],
                'constraints' => [
                    ['table' => 'o', 'name' => 'ck', 'definition' => "CHECK ((s <> 'shipped'::st))", 'comment' => "'why'", 'recreate' => true],
                    ['table' => 'o', 'name' => 'ck_paid', 'definition' => "CHECK ((s <> 'paid'::st))", 'comment' => null, 'recreate' => false],
                ],
                'indexes' => [
                    ['table' => 'o', 'name' => 'o_p', 'definition' => "CREATE INDEX o_p ON public.o USING btree (id) WHERE (s = 'new'::st)", 'comment' => null, 'recreate' => true],
                ],
                'readers' => [], 'others' => [],
                'comment' => "'states'", 'grants' => [['grantee' => 'app', 'privilege' => 'USAGE', 'grantable' => false]],
                'publicRevoked' => true,
            ],
            'dependants' => $dependants + ['views' => [], 'policies' => [], 'triggers' => [], 'generated' => [], 'defaultGrantees' => []],
            'skip' => [],
            'blockers' => $blockers,
        ];
    }

    private function statements(array $plan): array
    {
        return (new EnumSwapSQL('st', ['new', 'shipped'], $plan, new PostgresDialect()))->statements();
    }

    public function testTheSwapInOrder(): void
    {
        $this->assertSame([
            "CREATE TYPE \"st__new\" AS ENUM ('new', 'shipped');",
            'ALTER TABLE "o" DROP CONSTRAINT IF EXISTS "ck";',
            'ALTER TABLE "o" DROP CONSTRAINT IF EXISTS "ck_paid";',
            'DROP INDEX IF EXISTS "o_p";',
            'ALTER TABLE "o" ALTER COLUMN "s" DROP DEFAULT;',
            'ALTER TABLE "o" ALTER COLUMN "arr" DROP DEFAULT;',
            'ALTER TABLE "kid" ALTER COLUMN "s" DROP DEFAULT;',
            'ALTER TABLE "o" ALTER COLUMN "s" TYPE "st__new" USING "s"::text::"st__new", '
                . 'ALTER COLUMN "arr" TYPE "st__new"[] USING "arr"::text[]::"st__new"[];',
            'DROP TYPE "st";',
            'ALTER TYPE "st__new" RENAME TO "st";',
            "ALTER TABLE \"o\" ALTER COLUMN \"s\" SET DEFAULT 'new'::st;",
            "ALTER TABLE \"kid\" ALTER COLUMN \"s\" SET DEFAULT 'shipped'::st;",
            "ALTER TABLE \"o\" ADD CONSTRAINT \"ck\" CHECK ((s <> 'shipped'::st));",
            "COMMENT ON CONSTRAINT \"ck\" ON \"o\" IS 'why';",
            "CREATE INDEX o_p ON public.o USING btree (id) WHERE (s = 'new'::st);",
            "COMMENT ON TYPE \"st\" IS 'states';",
            'REVOKE USAGE ON TYPE "st" FROM PUBLIC;',
            'GRANT USAGE ON TYPE "st" TO app;',
        ], $this->statements($this->plan()));
    }

    public function testDependantsStandAsideAroundTheSwap(): void
    {
        $plan = $this->plan([], ['policies' => [
            ['schema' => 'public', 'table' => 'o', 'name' => 'p', 'definition' => "CREATE POLICY \"p\" ON \"o\" USING ((s = 'new'::st))"],
        ]]);
        $lines = $this->statements($plan);

        $this->assertSame('DROP POLICY IF EXISTS "p" ON "o";', $lines[1]);
        $this->assertSame("CREATE POLICY \"p\" ON \"o\" USING ((s = 'new'::st));", end($lines));
    }

    public function testAlterEnumUsesTheSwapWhenThereIsAPlan(): void
    {
        $diff = new AlterEnum('st', self::TWO, self::THREE);
        $diff->swaps['up'] = $this->plan();
        $sql = new AlterEnumSQL($diff, new PostgresDialect());

        $this->assertStringStartsWith('CREATE TYPE "st__new"', $sql->getUp());
        // The DOWN adds the label back.
        $this->assertSame("ALTER TYPE \"st\" ADD VALUE IF NOT EXISTS 'paid' AFTER 'new';", $sql->getDown());
    }

    public function testWhatTheSwapCannotCarryIsNamedAndTheTypeReplaced(): void
    {
        $diff = new AlterEnum('st', self::TWO, self::THREE);
        $diff->swaps['up'] = $this->plan([], [], ['function f(st)', 'type dst']);
        $up = (new AlterEnumSQL($diff, new PostgresDialect()))->getUp();

        $this->assertStringContainsString("--   function f(st)\n--   type dst\n", $up);
        $this->assertStringContainsString('DROP TYPE IF EXISTS "st";', $up);
        $this->assertStringNotContainsString('st__new', $up);
    }

    public function testRecognisesLabelAdditionsForTheRunner(): void
    {
        $this->assertTrue(AlterEnumSQL::isValueAddition("ALTER TYPE \"st\" ADD VALUE IF NOT EXISTS 'paid' AFTER 'new';"));
        $this->assertTrue(AlterEnumSQL::isValueAddition("-- note\nALTER TYPE st ADD VALUE 'x'"));
        $this->assertFalse(AlterEnumSQL::isValueAddition('ALTER TYPE "st__new" RENAME TO "st";'));
        $this->assertFalse(AlterEnumSQL::isValueAddition("ALTER TABLE t ADD VALUE"));
    }
}
