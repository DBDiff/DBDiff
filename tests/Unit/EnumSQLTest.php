<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\Diff\CreateEnum;
use DBDiff\Diff\DropEnum;
use DBDiff\Diff\AlterEnum;
use DBDiff\SQLGen\DiffToSQL\CreateEnumSQL;
use DBDiff\SQLGen\DiffToSQL\DropEnumSQL;
use DBDiff\SQLGen\DiffToSQL\AlterEnumSQL;
use DBDiff\SQLGen\Dialect\PostgresDialect;

class EnumSQLTest extends TestCase
{
    // ── CreateEnum ─────────────────────────────────────────────────────────

    public function testCreateEnumUp(): void
    {
        $def  = 'CREATE TYPE "status" AS ENUM (\'active\', \'inactive\', \'pending\')';
        $diff = new CreateEnum('status', $def);
        $sql  = new CreateEnumSQL($diff, new PostgresDialect());
        $this->assertSame($def . ';', $sql->getUp());
    }

    public function testCreateEnumDown(): void
    {
        $def  = 'CREATE TYPE "status" AS ENUM (\'active\', \'inactive\', \'pending\')';
        $diff = new CreateEnum('status', $def);
        $sql  = new CreateEnumSQL($diff, new PostgresDialect());
        $this->assertSame('DROP TYPE IF EXISTS "status";', $sql->getDown());
    }

    // ── DropEnum ──────────────────────────────────────────────────────────

    public function testDropEnumUp(): void
    {
        $def  = 'CREATE TYPE "old_status" AS ENUM (\'a\', \'b\')';
        $diff = new DropEnum('old_status', $def);
        $sql  = new DropEnumSQL($diff, new PostgresDialect());
        $this->assertSame('DROP TYPE IF EXISTS "old_status";', $sql->getUp());
    }

    public function testDropEnumDown(): void
    {
        $def  = 'CREATE TYPE "old_status" AS ENUM (\'a\', \'b\')';
        $diff = new DropEnum('old_status', $def);
        $sql  = new DropEnumSQL($diff, new PostgresDialect());
        $this->assertSame($def . ';', $sql->getDown());
    }

    // ── AlterEnum ─────────────────────────────────────────────────────────

    public function testAlterEnumUp(): void
    {
        $srcDef = 'CREATE TYPE "priority" AS ENUM (\'low\', \'medium\', \'high\', \'critical\')';
        $tgtDef = 'CREATE TYPE "priority" AS ENUM (\'low\', \'medium\', \'high\')';
        $diff   = new AlterEnum('priority', $srcDef, $tgtDef);
        $sql    = new AlterEnumSQL($diff, new PostgresDialect());
        $up     = $sql->getUp();
        $this->assertStringContainsString('DROP TYPE IF EXISTS "priority";', $up);
        $this->assertStringContainsString('critical', $up);
    }

    public function testAlterEnumDown(): void
    {
        $srcDef = 'CREATE TYPE "priority" AS ENUM (\'low\', \'medium\', \'high\', \'critical\')';
        $tgtDef = 'CREATE TYPE "priority" AS ENUM (\'low\', \'medium\', \'high\')';
        $diff   = new AlterEnum('priority', $srcDef, $tgtDef);
        $sql    = new AlterEnumSQL($diff, new PostgresDialect());
        $down   = $sql->getDown();
        $this->assertStringContainsString('DROP TYPE IF EXISTS "priority";', $down);
        $this->assertStringNotContainsString('critical', $down);
    }

    // ── Quoting ───────────────────────────────────────────────────────────

    public function testEnumNameWithSpecialCharsIsQuotedProperly(): void
    {
        $def  = 'CREATE TYPE "my-type" AS ENUM (\'x\')';
        $diff = new CreateEnum('my-type', $def);
        $sql  = new CreateEnumSQL($diff, new PostgresDialect());
        $this->assertSame('DROP TYPE IF EXISTS "my-type";', $sql->getDown());
    }

    // ── Edge cases ────────────────────────────────────────────────────────

    public function testSingleValueEnum(): void
    {
        $def  = 'CREATE TYPE "boolean_flag" AS ENUM (\'yes\')';
        $diff = new CreateEnum('boolean_flag', $def);
        $sql  = new CreateEnumSQL($diff, new PostgresDialect());
        $this->assertSame($def . ';', $sql->getUp());
        $this->assertSame('DROP TYPE IF EXISTS "boolean_flag";', $sql->getDown());
    }

    public function testEnumLabelWithEscapedQuotes(): void
    {
        $def  = 'CREATE TYPE "tricky" AS ENUM (\'it\'\'s\', \'O\'\'Brien\')';
        $diff = new CreateEnum('tricky', $def);
        $sql  = new CreateEnumSQL($diff, new PostgresDialect());
        $this->assertSame($def . ';', $sql->getUp());
    }

    public function testAlterEnumUpIsDropThenCreate(): void
    {
        $srcDef = 'CREATE TYPE "status" AS ENUM (\'a\', \'b\', \'c\')';
        $tgtDef = 'CREATE TYPE "status" AS ENUM (\'a\', \'b\')';
        $diff   = new AlterEnum('status', $srcDef, $tgtDef);
        $sql    = new AlterEnumSQL($diff, new PostgresDialect());
        $up     = $sql->getUp();
        // Must drop first, then create — in that order
        $dropPos   = strpos($up, 'DROP TYPE IF EXISTS "status"');
        $createPos = strpos($up, 'CREATE TYPE "status" AS ENUM');
        $this->assertNotFalse($dropPos);
        $this->assertNotFalse($createPos);
        $this->assertLessThan($createPos, $dropPos, 'DROP must precede CREATE in AlterEnum UP');
    }

    public function testDropEnumUpAndCreateEnumDownAreInverse(): void
    {
        $def  = 'CREATE TYPE "role" AS ENUM (\'admin\', \'user\', \'guest\')';
        $diff = new DropEnum('role', $def);
        $sql  = new DropEnumSQL($diff, new PostgresDialect());
        // DropEnum.getUp() == CreateEnum.getDown()
        $this->assertSame('DROP TYPE IF EXISTS "role";', $sql->getUp());
        // DropEnum.getDown() == CreateEnum.getUp()
        $this->assertSame($def . ';', $sql->getDown());
    }
}
