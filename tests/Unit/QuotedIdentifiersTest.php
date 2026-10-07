<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBDiff\DB\Support\PostgresSchemaHelper;
use PHPUnit\Framework\TestCase;

/**
 * A name holding a double quote is written with it doubled.
 *
 * Column, constraint, type and policy names were written between plain quotes,
 * so `Col "c"` became `"Col "c""`: a syntax error that failed the whole
 * migration. The corpus case `quoted_identifiers` in pg-conformance covers the
 * round trip; these pin each renderer without a database.
 */
class QuotedIdentifiersTest extends TestCase
{
    public function testIdentDoublesQuotes(): void
    {
        $this->assertSame('"say ""hi"" there"', PostgresSchemaHelper::ident('say "hi" there'));
        $this->assertSame('"plain"', PostgresSchemaHelper::ident('plain'));
    }

    public function testColumnDefinitionQuotesTheColumn(): void
    {
        $row = ['column_name' => 'Col "c"', 'data_type' => 'text', 'is_nullable' => 'YES', 'column_default' => null];
        $this->assertStringStartsWith('"Col ""c""" text', PostgresSchemaHelper::columnDefinition($row, 'text', ''));
    }

    public function testATypeNameIsQuoted(): void
    {
        $this->assertSame('"Mood ""m"""', PostgresSchemaHelper::qualifiedUdt(['udt_name' => 'Mood "m"', 'udt_schema' => 'public']));
        $this->assertSame('"App ""x"""."e"', PostgresSchemaHelper::qualifiedUdt(['udt_name' => 'e', 'udt_schema' => 'App "x"']));
    }

    public function testConstraintNamesAndColumnsAreQuoted(): void
    {
        $unique = PostgresSchemaHelper::constraintDefinition('uq "u"', [
            'constraint_type' => 'UNIQUE', 'columns' => ['Col "c"', 'b'],
        ]);
        $this->assertSame('CONSTRAINT "uq ""u""" UNIQUE ("Col ""c""", "b")', $unique);

        $key = PostgresSchemaHelper::constraintDefinition('fk "k"', [
            'constraint_type' => 'FOREIGN KEY', 'columns' => ['Ref "r"'],
            'foreign_table' => 'Odd "t"', 'foreign_column' => 'id',
            'update_rule' => 'NO ACTION', 'delete_rule' => 'SET NULL', 'delete_set_columns' => '["Ref \"r\""]',
        ]);
        $this->assertSame(
            'CONSTRAINT "fk ""k""" FOREIGN KEY ("Ref ""r""") REFERENCES "Odd ""t""" ("id") ON UPDATE NO ACTION ON DELETE SET NULL ("Ref ""r""")',
            $key,
        );
    }

    public function testPolicyNameIsQuoted(): void
    {
        $sql = PostgresSchemaHelper::policyDefinition([
            'name' => 'say "hi" there', 'permissive' => true, 'command' => 'SELECT',
            'roles' => null, 'using_expr' => 'true', 'check_expr' => null,
        ], '"Odd ""t"""');
        $this->assertSame('CREATE POLICY "say ""hi"" there" ON "Odd ""t""" FOR SELECT USING (true)', $sql);
    }
}
