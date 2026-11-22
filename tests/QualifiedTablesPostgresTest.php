<?php

/**
 * `--tables=app.plans` without `--schemas`.
 *
 * DBDiff compares `public` alone unless told otherwise, so a table pattern
 * naming another schema matched nothing, and the diff reported the two
 * databases identical whatever that table held. A pattern that names a
 * schema now brings it in beside `public`. See SchemaSelection.
 */
class QualifiedTablesPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_qt';

    private const TABLES = 'CREATE SCHEMA app; CREATE TABLE app.plans (id int PRIMARY KEY, name text, price int);
                            CREATE TABLE public.notes (id int PRIMARY KEY, body text);';

    public function testATablePatternInAnotherSchemaIsCompared(): void
    {
        $source = $this->db('s', self::TABLES . "INSERT INTO app.plans VALUES (1, 'free', 0), (2, 'pro', 2900);
                                                INSERT INTO public.notes VALUES (1, 'left out');");
        $target = $this->db('t', self::TABLES . "INSERT INTO app.plans VALUES (1, 'free', 0), (2, 'pro', 1900);");

        $this->extraArgs = ' --tables=app.plans';
        [$up] = $this->diff($source, $target, 'data') ?? [''];

        $this->assertMatchesRegularExpression('/UPDATE "app"\."plans" SET "price" = \'2900\'/', $up, $this->lastLog);
        $this->assertStringNotContainsString('notes', $up, 'only the table asked for');

        $this->connect($target)->exec($up);
        $this->assertNull($this->diff($source, $target, 'data'), 'and a second diff finds nothing');
    }

    public function testAPatternInPublicDiffsAsItAlwaysHas(): void
    {
        $source = $this->db('ps', self::TABLES . "INSERT INTO public.notes VALUES (1, 'kept');");
        $target = $this->db('pt', self::TABLES);

        $this->extraArgs = ' --tables=public.notes';
        [$up] = $this->diff($source, $target, 'data') ?? [''];
        $this->assertMatchesRegularExpression('/INSERT INTO "notes"/', $up, $this->lastLog);
    }
}
