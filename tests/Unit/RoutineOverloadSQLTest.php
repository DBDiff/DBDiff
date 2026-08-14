<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffToSQL\RoutineDrop;
use DBDiff\SQLGen\DiffToSQL\DropRoutineSQL;
use DBDiff\SQLGen\DiffToSQL\AlterRoutineSQL;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use DBDiff\Diff\DropRoutine;
use DBDiff\Diff\AlterRoutine;

/**
 * Regression tests for issue #187 — overloaded routines.
 *
 * Postgres routines are keyed by signature so overloads stay distinct, which
 * means the DROP has to carry the argument list. An unqualified DROP is a hard
 * error once overloads exist:
 *
 *     ERROR: function name "cosine_distance" is not unique
 *
 * and, were it unambiguous, would remove every overload to recreate one.
 */
class RoutineOverloadSQLTest extends TestCase
{
    private const FN_DEF = 'CREATE OR REPLACE FUNCTION public.cosine_distance(a text, b text) RETURNS text AS $$ SELECT $$';

    public function testDropCarriesTheArgumentList(): void
    {
        $sql = RoutineDrop::build(self::FN_DEF, 'cosine_distance(text,text)', new PostgresDialect());
        $this->assertSame('DROP FUNCTION IF EXISTS "cosine_distance"(text,text);', $sql);
    }

    public function testArgumentListSitsOutsideTheQuotedIdentifier(): void
    {
        // Quoting the whole signature would yield "cosine_distance(text,text)"
        // — one odd identifier rather than a call signature.
        $sql = RoutineDrop::build(self::FN_DEF, 'cosine_distance(text,text)', new PostgresDialect());
        $this->assertStringNotContainsString('"cosine_distance(text,text)"', $sql);
        $this->assertStringContainsString('"cosine_distance"(text,text)', $sql);
    }

    public function testBareNameIsUnchangedForDriversWithoutOverloads(): void
    {
        // MySQL has no overloading and passes a bare name.
        $sql = RoutineDrop::build('CREATE PROCEDURE do_thing()', 'do_thing', new MySQLDialect());
        $this->assertSame('DROP PROCEDURE IF EXISTS `do_thing`;', $sql);
    }

    public function testProcedureIsDetectedFromTheDefinition(): void
    {
        $sql = RoutineDrop::build('CREATE PROCEDURE public.p(a int)', 'p(integer)', new PostgresDialect());
        $this->assertStringStartsWith('DROP PROCEDURE IF EXISTS', $sql);
    }

    public function testFunctionIsTheDefaultType(): void
    {
        $sql = RoutineDrop::build('CREATE FUNCTION public.f()', 'f()', new PostgresDialect());
        $this->assertStringStartsWith('DROP FUNCTION IF EXISTS', $sql);
    }

    public function testNoArgumentSignatureStillRendersParens(): void
    {
        $sql = RoutineDrop::build('CREATE FUNCTION public.f()', 'f()', new PostgresDialect());
        $this->assertSame('DROP FUNCTION IF EXISTS "f"();', $sql);
    }

    public function testDropRoutineSQLUsesTheSignature(): void
    {
        $obj = new DropRoutine('cosine_distance(text,text)', self::FN_DEF);
        $sql = (new DropRoutineSQL($obj, new PostgresDialect()))->getUp();
        $this->assertSame('DROP FUNCTION IF EXISTS "cosine_distance"(text,text);', $sql);
    }

    public function testAlterRoutineSQLDropsOnlyTheChangedOverload(): void
    {
        $source = 'CREATE OR REPLACE FUNCTION public.cosine_distance(a text, b text) RETURNS text AS $$ SELECT 1 $$';
        $target = 'CREATE OR REPLACE FUNCTION public.cosine_distance(a text, b text) RETURNS text AS $$ SELECT 2 $$';
        $obj = new AlterRoutine('cosine_distance(text,text)', $source, $target);

        $up = (new AlterRoutineSQL($obj, new PostgresDialect()))->getUp();
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS "cosine_distance"(text,text);', $up);
        // The sibling overloads must survive.
        $this->assertStringNotContainsString('"cosine_distance";', $up);
    }

    public function testAlterRoutineDownAlsoCarriesTheSignature(): void
    {
        $source = 'CREATE OR REPLACE FUNCTION public.f(a int) RETURNS int AS $$ SELECT 1 $$';
        $target = 'CREATE OR REPLACE FUNCTION public.f(a int) RETURNS int AS $$ SELECT 2 $$';
        $obj = new AlterRoutine('f(integer)', $source, $target);

        $down = (new AlterRoutineSQL($obj, new PostgresDialect()))->getDown();
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS "f"(integer);', $down);
    }
}
