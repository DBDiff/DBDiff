<?php

/**
 * Shapes taken from real, widely used schemas — AdventureWorks, Pagila,
 * GitLab's structure.sql, any pgvector project — that the synthetic corpus
 * did not have, against a live server. See PostgresRoundTripTestCase.
 *
 * Each was wrong in a way the round trip alone could not see: an array that
 * lost its element's modifier, or a domain that resolved to a different
 * type, re-diffed as identical. So every case also compares the catalog's own
 * reading of the columns, domains and partitions.
 *
 * Each runs with pg_dump rendering new tables and with DBDiff's own renderer,
 * which is what runs wherever no pg_dump as new as the server is installed.
 */
class RealWorldShapesPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_rw';

    /** Partitions and types outside `public` are part of what is checked. */
    protected string $schemas = '*';

    /** @return array<string, array{string}> */
    public static function renderers(): array
    {
        return ['pg_dump' => ['on'], 'built-in' => ['off']];
    }

    protected function tearDown(): void
    {
        putenv('DBDIFF_PG_DUMP_RENDERER');
        parent::tearDown();
    }

    private function renderer(string $mode): void
    {
        putenv('DBDIFF_PG_DUMP_RENDERER=' . $mode);
    }

    /** What the catalog says, which the diff must not be the only judge of. */
    private function catalog(string $db): array
    {
        $pdo = $this->connect($db);
        $q = fn(string $sql) => $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
        return [
            'columns' => $q("SELECT n.nspname, c.relname, a.attname, format_type(a.atttypid, a.atttypmod)
                               FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
                               JOIN pg_namespace n ON n.oid = c.relnamespace
                              WHERE a.attnum > 0 AND NOT a.attisdropped AND c.relkind IN ('r', 'p')
                                AND n.nspname NOT IN ('pg_catalog', 'information_schema')
                              ORDER BY 1, 2, 3"),
            'domains' => $q("SELECT t.typname, con.conname, pg_get_constraintdef(con.oid), con.convalidated
                               FROM pg_constraint con JOIN pg_type t ON t.oid = con.contypid
                              ORDER BY 1, 2"),
            'partitions' => $q("SELECT i.inhrelid::regclass::text, i.inhparent::regclass::text
                                  FROM pg_inherits i ORDER BY 1"),
        ];
    }

    private function roundTrip(string $case, string $mode, string $source, string $target): string
    {
        $this->renderer($mode);
        return $this->assertRoundTrip("{$case}_$mode", $source, $target, fn(string $db) => $this->catalog($db));
    }

    /** AdventureWorks: `"Name"` written bare is PostgreSQL's own `name` type. */
    /** @dataProvider renderers */
    public function testMixedCaseDomainsOnANewTable(string $mode): void
    {
        $domains = 'CREATE DOMAIN "Name" AS varchar(50); CREATE DOMAIN "Flag" AS boolean NOT NULL;';
        $this->roundTrip('domains', $mode,
            $domains . 'CREATE TABLE store (id int PRIMARY KEY, n "Name", f "Flag" DEFAULT false, aliases "Name"[]);',
            $domains);
    }

    /** Arrays keep their element's modifier, and name mixed-case and non-public element types. */
    /** @dataProvider renderers */
    public function testArraysWithModifiers(string $mode): void
    {
        $types = 'CREATE SCHEMA app; CREATE TYPE app.status AS ENUM (\'x\'); CREATE TYPE "Mood" AS ENUM (\'a\');';
        $this->roundTrip('arrays', $mode,
            $types . 'CREATE TABLE t (id int PRIMARY KEY, a varchar(20)[], b numeric(10,2)[], c timestamptz(3)[],
                                      i bit(4)[], d "Mood"[], e app.status[]);',
            $types);
    }

    /** A column whose array lost its modifier is a difference, not identical. */
    public function testALostArrayModifierIsADifference(): void
    {
        $up = $this->assertRoundTrip('array_typmod',
            'CREATE TABLE t (id int PRIMARY KEY, a varchar(20)[], b numeric(10,2)[]);',
            'CREATE TABLE t (id int PRIMARY KEY, a varchar[], b numeric[]);',
            fn(string $db) => $this->catalog($db));
        $this->assertStringContainsString('character varying(20)[]', $up);
    }

    /** Pagila and GitLab: a partitioned table gaining a column gains it once, on the parent. */
    /** @dataProvider renderers */
    public function testAColumnAddedToAPartitionedTable(string $mode): void
    {
        $parts = "CREATE TABLE p (id int, at date NOT NULL) PARTITION BY RANGE (at);
                  CREATE TABLE p_1 PARTITION OF p FOR VALUES FROM ('2020-01-01') TO ('2021-01-01');
                  CREATE TABLE p_2 (id int, at date NOT NULL);
                  ALTER TABLE p ATTACH PARTITION p_2 FOR VALUES FROM ('2021-01-01') TO ('2022-01-01');";
        $this->roundTrip('part_add', $mode, $parts . "ALTER TABLE p ADD COLUMN note text DEFAULT 'x';", $parts);
    }

    /** GitLab keeps hash partitions of public tables in a schema of their own. */
    /** @dataProvider renderers */
    public function testAPartitionInAnotherSchemaThanItsParent(string $mode): void
    {
        $this->roundTrip('part_schema', $mode,
            'CREATE SCHEMA parts; CREATE TABLE ev (id bigint NOT NULL, k int) PARTITION BY HASH (id);
             CREATE TABLE parts.ev_0 PARTITION OF ev FOR VALUES WITH (modulus 2, remainder 0);
             CREATE TABLE parts.ev_1 PARTITION OF ev FOR VALUES WITH (modulus 2, remainder 1);',
            'CREATE SCHEMA parts;');
    }

    /** AdventureWorks: a domain in use gains a NOT VALID check, which only ALTER DOMAIN can add. */
    public function testADomainInUseGainsANotValidCheck(): void
    {
        $base = 'CREATE DOMAIN "Phone" AS varchar(25); CREATE TABLE person (id int PRIMARY KEY, phone "Phone");';
        $up = $this->assertRoundTrip('domain_check',
            $base . 'ALTER DOMAIN "Phone" ADD CONSTRAINT phone_len CHECK (length(VALUE) <= 40) NOT VALID;',
            $base, fn(string $db) => $this->catalog($db));
        $this->assertStringNotContainsString('DROP DOMAIN', $up);
    }

    /** A new domain with a NOT VALID check stays unvalidated. */
    public function testANewDomainWithANotValidCheck(): void
    {
        $this->assertRoundTrip('domain_new',
            'CREATE DOMAIN dd AS text CONSTRAINT dd_ok CHECK (VALUE <> \'\');
             ALTER DOMAIN dd ADD CONSTRAINT dd_len CHECK (length(VALUE) < 5) NOT VALID;',
            '', fn(string $db) => $this->catalog($db));
    }
}
