<?php

namespace DBDiff\Tests\Unit;

use DBDiff\DB\Support\EnumLabels;
use PHPUnit\Framework\TestCase;

class EnumLabelsTest extends TestCase
{
    public function testReadsLabelsWithQuotesAndCommas(): void
    {
        $this->assertSame(['new', "it's", 'a,b'], EnumLabels::of("CREATE TYPE \"t\" AS ENUM ('new', 'it''s', 'a,b')"));
        $this->assertNull(EnumLabels::of('CREATE DOMAIN "d" AS integer'));
    }

    public function testCreateStatementQuotesEachLabel(): void
    {
        $this->assertSame(
            "CREATE TYPE \"t__new\" AS ENUM ('new', 'it''s');",
            EnumLabels::createStatement('"t__new"', ['new', "it's"])
        );
    }

    public function testFindsALabelInARenderedLiteral(): void
    {
        $this->assertTrue(EnumLabels::mentioned("CHECK ((s <> 'paid'::st))", 'st', ['paid']));
        $this->assertTrue(EnumLabels::mentioned("WHERE (s = 'paid'::public.st)", 'st', ['paid']));
        $this->assertTrue(EnumLabels::mentioned("'it''s'::\"Order State\"", 'Order State', ["it's"]));
    }

    public function testFindsALabelInAnArrayLiteral(): void
    {
        $this->assertTrue(EnumLabels::mentioned("'{new,paid}'::st[]", 'st', ['paid']));
        $this->assertTrue(EnumLabels::mentioned("'{\"paid\"}'::st[]", 'st', ['paid']));
        $this->assertFalse(EnumLabels::mentioned("'{new,shipped}'::st[]", 'st', ['paid']));
    }

    public function testIgnoresOtherTypesAndUncastStrings(): void
    {
        $this->assertFalse(EnumLabels::mentioned("'paid'::text", 'st', ['paid']));
        $this->assertFalse(EnumLabels::mentioned("'paid'::status", 'st', ['paid']));
        $this->assertFalse(EnumLabels::mentioned("'paid'::st", 'st', []));
    }
}
