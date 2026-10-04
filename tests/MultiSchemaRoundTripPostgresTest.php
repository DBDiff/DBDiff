<?php

/**
 * `--schemas`: a diff over more than `public`.
 *
 * Each case round-trips, checked by DBDiff diffing again and by
 * pg-conformance's state query over every schema, which does not depend on
 * what DBDiff reads.
 */
class MultiSchemaRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    use CorpusState;

    protected string $prefix = 'dbdiff_ms';
    protected string $schemas = '*';

    /** Where a pattern first matches, failing the test if it does not. */
    private static function at(string $sql, string $pattern): int
    {
        self::assertSame(1, preg_match($pattern, $sql, $m, PREG_OFFSET_CAPTURE), "$pattern not in:\n$sql");
        return $m[0][1];
    }

    private function roundTrips(string $case, string $source, string $target): string
    {
        return $this->assertRoundTrip($case, $source, $target, fn(string $db) => $this->schemaState($db));
    }

    public function testASchemaOnlyTheSourceHasIsCreatedAndDroppedAgain(): void
    {
        $up = $this->roundTrips(
            'created',
            "CREATE SCHEMA billing; CREATE TYPE billing.state AS ENUM ('open', 'paid');
             CREATE TABLE billing.invoices (id int PRIMARY KEY, state billing.state NOT NULL DEFAULT 'open');",
            ''
        );
        $this->assertStringContainsString('CREATE SCHEMA IF NOT EXISTS "billing";', $up);
        $this->assertLessThan(strpos($up, 'CREATE TYPE'), strpos($up, 'CREATE SCHEMA'));
    }

    public function testASchemaOnlyTheTargetHasIsDropped(): void
    {
        $up = $this->roundTrips(
            'dropped',
            '',
            'CREATE SCHEMA old; CREATE TABLE old.t (id int PRIMARY KEY); CREATE VIEW old.v AS SELECT id FROM old.t;'
        );
        $this->assertStringEndsWith('DROP SCHEMA IF EXISTS "old";', trim($up));
    }

    // A table in `billing` sorts before one in `public` by name; created in
    // that order, its key onto the other would fail.
    public function testNewTablesAreCreatedAfterTheTablesTheyReferenceInAnotherSchema(): void
    {
        $up = $this->roundTrips(
            'across',
            'CREATE SCHEMA billing; CREATE TABLE customers (id int PRIMARY KEY);
             CREATE TABLE billing.invoices (id int PRIMARY KEY, customer_id int REFERENCES public.customers (id));',
            'CREATE SCHEMA billing;'
        );
        // Either renderer: pg_dump writes billing.invoices, DBDiff's own "billing"."invoices".
        $this->assertLessThan(self::at($up, '/CREATE TABLE "?billing"?\."?invoices/'), self::at($up, '/CREATE TABLE "?public"?\."?customers|CREATE TABLE "customers"/'));
    }

    public function testTablesOfTheSameNameInTwoSchemasStayApart(): void
    {
        $this->roundTrips(
            'same_name',
            'CREATE SCHEMA a; CREATE SCHEMA b;
             CREATE TABLE a.t (id int PRIMARY KEY, x text); CREATE TABLE b.t (id int PRIMARY KEY, y int);',
            'CREATE SCHEMA a; CREATE SCHEMA b;
             CREATE TABLE a.t (id int PRIMARY KEY); CREATE TABLE b.t (id int PRIMARY KEY);'
        );
    }

    public function testSchemaObjectsOutsidePublicAreNamedInFull(): void
    {
        $up = $this->roundTrips(
            'kinds',
            "CREATE SCHEMA app;
             CREATE SEQUENCE app.counter START 5;
             CREATE DOMAIN app.positive AS int CHECK (VALUE > 0);
             CREATE TYPE app.pair AS (a int, b int);
             CREATE TABLE app.t (id serial PRIMARY KEY, n app.positive, p app.pair);
             CREATE VIEW app.v AS SELECT id, n FROM app.t;
             CREATE MATERIALIZED VIEW app.m AS SELECT count(*) AS c FROM app.t;
             ALTER TABLE app.t ENABLE ROW LEVEL SECURITY;
             CREATE POLICY own ON app.t USING (n > 1);
             CREATE FUNCTION app.\"Touch\"() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
             CREATE TRIGGER touch BEFORE UPDATE ON app.t FOR EACH ROW EXECUTE FUNCTION app.\"Touch\"();",
            'CREATE SCHEMA app; CREATE TABLE app.t (id serial PRIMARY KEY);'
        );
        $this->assertStringNotContainsString('"public"', $up);
    }

    public function testDataInAnotherSchema(): void
    {
        $schema = 'CREATE SCHEMA app; CREATE TABLE app.t (id int PRIMARY KEY, v text);';
        $source = $this->db('data_s', $schema . "INSERT INTO app.t VALUES (1, 'a'), (2, 'b');");
        $target = $this->db('data_t', $schema . "INSERT INTO app.t VALUES (1, 'z'), (3, 'c');");
        $rows   = fn(string $db) => $this->rows($db, 'SELECT id, v FROM app.t ORDER BY id');

        [$up, $down] = $this->diff($source, $target, 'data');
        $before = $rows($target);
        $this->apply($target, $up);
        $this->assertEquals($rows($source), $rows($target), "UP did not reach the source's rows:\n$up");
        $this->apply($target, $down);
        $this->assertEquals($before, $rows($target), "DOWN did not restore the rows:\n$down");
    }

    // The default is unchanged: without --schemas, only `public` is read.
    public function testWithoutTheOptionOnlyPublicIsCompared(): void
    {
        $this->schemas = '';
        $source = $this->db('default_s', 'CREATE SCHEMA app; CREATE TABLE app.t (id int);');
        $target = $this->db('default_t', 'CREATE SCHEMA app;');
        $this->assertNull($this->diff($source, $target));
    }
}
