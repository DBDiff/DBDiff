<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\Diff\CreateSequence;
use DBDiff\Diff\DropSequence;
use DBDiff\Diff\AlterSequence;
use DBDiff\Diff\CreateCompositeType;
use DBDiff\Diff\DropCompositeType;
use DBDiff\Diff\AlterCompositeType;
use DBDiff\Diff\CreateDomain;
use DBDiff\Diff\DropDomain;
use DBDiff\Diff\AlterDomain;
use DBDiff\Diff\CreateMatView;
use DBDiff\Diff\DropMatView;
use DBDiff\Diff\AlterMatView;
use DBDiff\Diff\CreatePolicy;
use DBDiff\Diff\DropPolicy;
use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterRowSecurity;
use DBDiff\SQLGen\DiffToSQL\CreateSequenceSQL;
use DBDiff\SQLGen\DiffToSQL\DropSequenceSQL;
use DBDiff\SQLGen\DiffToSQL\AlterSequenceSQL;
use DBDiff\SQLGen\DiffToSQL\CreateCompositeTypeSQL;
use DBDiff\SQLGen\DiffToSQL\DropCompositeTypeSQL;
use DBDiff\SQLGen\DiffToSQL\AlterCompositeTypeSQL;
use DBDiff\SQLGen\DiffToSQL\CreateDomainSQL;
use DBDiff\SQLGen\DiffToSQL\DropDomainSQL;
use DBDiff\SQLGen\DiffToSQL\AlterDomainSQL;
use DBDiff\SQLGen\DiffToSQL\CreateMatViewSQL;
use DBDiff\SQLGen\DiffToSQL\DropMatViewSQL;
use DBDiff\SQLGen\DiffToSQL\AlterMatViewSQL;
use DBDiff\SQLGen\DiffToSQL\CreatePolicySQL;
use DBDiff\SQLGen\DiffToSQL\DropPolicySQL;
use DBDiff\SQLGen\DiffToSQL\AlterPolicySQL;
use DBDiff\SQLGen\DiffToSQL\AlterRowSecuritySQL;
use DBDiff\SQLGen\Dialect\PostgresDialect;

/**
 * SQL generation for the object kinds DBDiff previously did not model:
 * standalone sequences, composite types, domains, materialised views, and row
 * level security with its policies.
 *
 * Until these existed DBDiff reported "no differences" when only one of them
 * had changed, which is the failure mode a schema diff can least afford.
 */
class ObjectKindsSQLTest extends TestCase
{
    private PostgresDialect $pg;

    protected function setUp(): void
    {
        $this->pg = new PostgresDialect();
    }

    // ── Sequences ──────────────────────────────────────────────────────────

    private const SEQ = 'CREATE SEQUENCE "o_seq" AS bigint INCREMENT BY 5 '
        . 'MINVALUE 100 MAXVALUE 100000 START WITH 100 CACHE 1 CYCLE';

    public function testCreateSequenceUp(): void
    {
        $sql = new CreateSequenceSQL(new CreateSequence('o_seq', self::SEQ), $this->pg);
        $this->assertSame(self::SEQ . ';', $sql->getUp());
    }

    public function testCreateSequenceDown(): void
    {
        $sql = new CreateSequenceSQL(new CreateSequence('o_seq', self::SEQ), $this->pg);
        $this->assertSame('DROP SEQUENCE IF EXISTS "o_seq";', $sql->getDown());
    }

    public function testDropSequenceIsTheInverse(): void
    {
        $sql = new DropSequenceSQL(new DropSequence('o_seq', self::SEQ), $this->pg);
        $this->assertSame('DROP SEQUENCE IF EXISTS "o_seq";', $sql->getUp());
        $this->assertSame(self::SEQ . ';', $sql->getDown());
    }

    /**
     * A sequence carries a value, so it is altered in place. Dropping and
     * recreating it would silently restart the counter.
     */
    public function testAlterSequenceRewritesCreateToAlter(): void
    {
        $target = 'CREATE SEQUENCE "o_seq" AS bigint INCREMENT BY 1 '
            . 'MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1 NO CYCLE';
        $sql = new AlterSequenceSQL(new AlterSequence('o_seq', self::SEQ, $target), $this->pg);

        $this->assertSame(
            'ALTER SEQUENCE "o_seq" AS bigint INCREMENT BY 5 '
            . 'MINVALUE 100 MAXVALUE 100000 START WITH 100 CACHE 1 CYCLE;',
            $sql->getUp()
        );
        $this->assertStringStartsWith('ALTER SEQUENCE "o_seq"', $sql->getDown());
        $this->assertStringNotContainsString('DROP SEQUENCE', $sql->getUp());
        $this->assertStringNotContainsString('DROP SEQUENCE', $sql->getDown());
    }

    // ── Composite types ───────────────────────────────────────────────────

    public function testCreateCompositeType(): void
    {
        $def  = 'CREATE TYPE "o_addr" AS (street text, city text)';
        $diff = new CreateCompositeType('o_addr', $def);
        $sql  = new CreateCompositeTypeSQL($diff, $this->pg);

        $this->assertSame($def . ';', $sql->getUp());
        $this->assertSame('DROP TYPE IF EXISTS "o_addr";', $sql->getDown());
    }

    public function testDropCompositeTypeIsTheInverse(): void
    {
        $def = 'CREATE TYPE "o_addr" AS (street text, city text)';
        $sql = new DropCompositeTypeSQL(new DropCompositeType('o_addr', $def), $this->pg);

        $this->assertSame('DROP TYPE IF EXISTS "o_addr";', $sql->getUp());
        $this->assertSame($def . ';', $sql->getDown());
    }

    public function testAlterCompositeTypeRecreates(): void
    {
        $src = 'CREATE TYPE "o_addr" AS (street text, city text, postcode text)';
        $tgt = 'CREATE TYPE "o_addr" AS (street text, city text)';
        $sql = new AlterCompositeTypeSQL(new AlterCompositeType('o_addr', $src, $tgt), $this->pg);

        $this->assertSame("DROP TYPE IF EXISTS \"o_addr\";\n" . $src . ';', $sql->getUp());
        $this->assertSame("DROP TYPE IF EXISTS \"o_addr\";\n" . $tgt . ';', $sql->getDown());
    }

    // ── Domains ───────────────────────────────────────────────────────────

    public function testCreateDomain(): void
    {
        $def  = 'CREATE DOMAIN "o_pos" AS integer CONSTRAINT o_pos_check CHECK ((VALUE > 0))';
        $diff = new CreateDomain('o_pos', $def);
        $sql  = new CreateDomainSQL($diff, $this->pg);

        $this->assertSame($def . ';', $sql->getUp());
        $this->assertSame('DROP DOMAIN IF EXISTS "o_pos";', $sql->getDown());
    }

    public function testDropDomainIsTheInverse(): void
    {
        $def = 'CREATE DOMAIN "o_pos" AS integer';
        $sql = new DropDomainSQL(new DropDomain('o_pos', $def), $this->pg);

        $this->assertSame('DROP DOMAIN IF EXISTS "o_pos";', $sql->getUp());
        $this->assertSame($def . ';', $sql->getDown());
    }

    public function testAlterDomainRecreates(): void
    {
        $src = 'CREATE DOMAIN "d" AS text NOT NULL';
        $tgt = 'CREATE DOMAIN "d" AS text';
        $sql = new AlterDomainSQL(new AlterDomain('d', $src, $tgt), $this->pg);

        $this->assertSame("DROP DOMAIN IF EXISTS \"d\";\n" . $src . ';', $sql->getUp());
        $this->assertSame("DROP DOMAIN IF EXISTS \"d\";\n" . $tgt . ';', $sql->getDown());
    }

    // ── Materialised views ────────────────────────────────────────────────

    public function testCreateMatView(): void
    {
        $def  = 'CREATE MATERIALIZED VIEW "o_mv" AS SELECT id, n FROM o_mt WHERE (n > 0)';
        $diff = new CreateMatView('o_mv', $def);
        $sql  = new CreateMatViewSQL($diff, $this->pg);

        $this->assertSame($def . ';', $sql->getUp());
        $this->assertSame('DROP MATERIALIZED VIEW IF EXISTS "o_mv";', $sql->getDown());
    }

    public function testDropMatViewIsTheInverse(): void
    {
        $def = 'CREATE MATERIALIZED VIEW "o_mv" AS SELECT id FROM o_mt';
        $sql = new DropMatViewSQL(new DropMatView('o_mv', $def), $this->pg);

        $this->assertSame('DROP MATERIALIZED VIEW IF EXISTS "o_mv";', $sql->getUp());
        $this->assertSame($def . ';', $sql->getDown());
    }

    /**
     * PostgreSQL has no CREATE OR REPLACE for a materialised view, so a changed
     * definition can only be applied by recreating it.
     */
    public function testAlterMatViewRecreates(): void
    {
        $src = 'CREATE MATERIALIZED VIEW "m" AS SELECT id FROM t WHERE (n > 0)';
        $tgt = 'CREATE MATERIALIZED VIEW "m" AS SELECT id FROM t WHERE (n < 0)';
        $sql = new AlterMatViewSQL(new AlterMatView('m', $src, $tgt), $this->pg);

        $this->assertSame("DROP MATERIALIZED VIEW IF EXISTS \"m\";\n" . $src . ';', $sql->getUp());
        $this->assertSame("DROP MATERIALIZED VIEW IF EXISTS \"m\";\n" . $tgt . ';', $sql->getDown());
    }

    // ── Policies ──────────────────────────────────────────────────────────

    public function testCreatePolicyDropsWithItsTable(): void
    {
        $def  = 'CREATE POLICY "o_sec_read" ON "o_sec" FOR SELECT USING (true)';
        $diff = new CreatePolicy('o_sec_read', 'o_sec', $def);
        $sql  = new CreatePolicySQL($diff, $this->pg);

        $this->assertSame($def . ';', $sql->getUp());
        // A policy name is unique per table, so the table has to be named.
        $this->assertSame('DROP POLICY IF EXISTS "o_sec_read" ON "o_sec";', $sql->getDown());
    }

    public function testDropPolicyIsTheInverse(): void
    {
        $def = 'CREATE POLICY "p" ON "t" FOR ALL USING (true)';
        $sql = new DropPolicySQL(new DropPolicy('p', 't', $def), $this->pg);

        $this->assertSame('DROP POLICY IF EXISTS "p" ON "t";', $sql->getUp());
        $this->assertSame($def . ';', $sql->getDown());
    }

    public function testAlterPolicyRecreates(): void
    {
        $src = 'CREATE POLICY "p" ON "t" FOR SELECT USING (true)';
        $tgt = 'CREATE POLICY "p" ON "t" FOR SELECT USING (false)';
        $sql = new AlterPolicySQL(new AlterPolicy('p', 't', $src, $tgt), $this->pg);

        $this->assertSame("DROP POLICY IF EXISTS \"p\" ON \"t\";\n" . $src . ';', $sql->getUp());
        $this->assertSame("DROP POLICY IF EXISTS \"p\" ON \"t\";\n" . $tgt . ';', $sql->getDown());
    }

    // ── Row level security flags ──────────────────────────────────────────

    public function testEnablingRowSecurity(): void
    {
        $diff = new AlterRowSecurity('t', true, false, false, false);
        $sql  = new AlterRowSecuritySQL($diff, $this->pg);

        $this->assertSame(
            "ALTER TABLE \"t\" ENABLE ROW LEVEL SECURITY;\n"
            . 'ALTER TABLE "t" NO FORCE ROW LEVEL SECURITY;',
            $sql->getUp()
        );
        $this->assertSame(
            "ALTER TABLE \"t\" DISABLE ROW LEVEL SECURITY;\n"
            . 'ALTER TABLE "t" NO FORCE ROW LEVEL SECURITY;',
            $sql->getDown()
        );
    }

    public function testForcingRowSecurity(): void
    {
        $diff = new AlterRowSecurity('t', true, true, false, false);
        $sql  = new AlterRowSecuritySQL($diff, $this->pg);

        $this->assertStringContainsString('ENABLE ROW LEVEL SECURITY', $sql->getUp());
        $this->assertStringContainsString('FORCE ROW LEVEL SECURITY', $sql->getUp());
        $this->assertStringNotContainsString('NO FORCE', $sql->getUp());
    }

    /**
     * A table that exists only on the source side is dropped by its own DOWN
     * migration, so restating its flags there would ALTER a relation that has
     * already gone.
     */
    public function testRowSecurityOnANewTableHasNoDown(): void
    {
        $diff = new AlterRowSecurity('t', true, false, false, false, true);
        $sql  = new AlterRowSecuritySQL($diff, $this->pg);

        $this->assertStringContainsString('ENABLE ROW LEVEL SECURITY', $sql->getUp());
        $this->assertSame('', $sql->getDown());
    }
}
