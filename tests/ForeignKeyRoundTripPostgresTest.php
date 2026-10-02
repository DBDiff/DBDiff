<?php

/**
 * Foreign keys added and removed, against a live server. See
 * PostgresRoundTripTestCase for how each case is checked.
 *
 * Two shapes used to produce SQL that could not run: a key onto a table in
 * another schema — every Supabase table referencing auth.users — rendered
 * without the schema, and a key over several columns rendered with only the
 * first referenced column.
 *
 * The plain round trips of these shapes, and of both on a new table, are in
 * the shared corpus (CorpusMigrationsRoundTripTest). These check the SQL
 * DBDiff writes for them.
 */
class ForeignKeyRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_fk';

    private const PARENTS = 'CREATE SCHEMA app; CREATE TABLE app.users (id int PRIMARY KEY);
                             CREATE TABLE p (a int, b int, PRIMARY KEY (a, b));';

    public function testAKeyOntoAnotherSchemasTable(): void
    {
        $up = $this->assertRoundTrip(
            'schema',
            self::PARENTS . 'CREATE TABLE profiles (id int, user_id int REFERENCES app.users (id));',
            self::PARENTS . 'CREATE TABLE profiles (id int, user_id int);'
        );
        $this->assertStringContainsString('REFERENCES "app"."users" ("id")', $up);
    }

    public function testAKeyOverSeveralColumns(): void
    {
        $up = $this->assertRoundTrip(
            'multi',
            self::PARENTS . 'CREATE TABLE c (x int, y int, FOREIGN KEY (x, y) REFERENCES p (a, b));',
            self::PARENTS . 'CREATE TABLE c (x int, y int);'
        );
        $this->assertStringContainsString('REFERENCES "p" ("a", "b")', $up);
    }
}
