<?php

/**
 * A column renamed on the source is renamed on the target — its data kept
 * — rather than dropped and added again.
 *
 * See PostgresRoundTripTestCase for how each case is checked.
 */
class RenamesRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_ren';

    public function testARenamedColumnKeepsItsData(): void
    {
        $up = $this->assertRoundTrip(
            'col',
            "CREATE TABLE people (id int PRIMARY KEY, full_name text NOT NULL DEFAULT '' CHECK (full_name <> 'x'));
             CREATE INDEX people_name ON people (full_name);
             CREATE VIEW names AS SELECT full_name FROM people;
             INSERT INTO people VALUES (1, 'Ann'), (2, 'Bo');",
            "CREATE TABLE people (id int PRIMARY KEY, name text NOT NULL DEFAULT '' CHECK (name <> 'x'));
             CREATE INDEX people_name ON people (name);
             CREATE VIEW names AS SELECT name FROM people;
             INSERT INTO people VALUES (1, 'Ann'), (2, 'Bo');",
            fn(string $db) => $this->rows($db, 'SELECT * FROM people ORDER BY id')
        );
        $this->assertStringContainsString('RENAME COLUMN "name" TO "full_name"', $up);
        $this->assertStringNotContainsString('DROP COLUMN', $up);
    }

    /**
     * A column renamed on the source keeps its NOT NULL constraint's old name
     * on PostgreSQL 18, and renaming the target's column gives the target's
     * the same: adding it again failed with "already exists".
     */
    public function testAColumnRenamedOnTheSourceKeepsItsNotNull(): void
    {
        $up = $this->assertRoundTrip(
            'col_renamed_source',
            "CREATE TABLE people (id int PRIMARY KEY, name text NOT NULL);
             ALTER TABLE people RENAME COLUMN name TO full_name;",
            "CREATE TABLE people (id int PRIMARY KEY, name text NOT NULL);
             INSERT INTO people VALUES (1, 'Ann'), (2, 'Bo');",
            fn(string $db) => $this->rows($db, "SELECT attname, attnotnull FROM pg_attribute WHERE attrelid = 'people'::regclass AND attnum > 0 ORDER BY attnum")
        );
        $this->assertStringContainsString('RENAME COLUMN "name" TO "full_name"', $up);
        $this->assertStringNotContainsString('ADD CONSTRAINT', $up);
    }

    public function testColumnsSharingADefinitionAreNotGuessed(): void
    {
        [$up] = $this->diff(
            $this->db('amb_s', 'CREATE TABLE t (id int PRIMARY KEY, c text, d text);'),
            $this->db('amb_t', 'CREATE TABLE t (id int PRIMARY KEY, a text, b text);')
        );
        $this->assertStringNotContainsString('RENAME COLUMN', $up);
        $this->assertStringContainsString('not taken for renames', $this->lastLog);
    }

    public function testRenamesCanBeTurnedOff(): void
    {
        $this->extraArgs = ' --renames=off';
        [$up] = $this->diff(
            $this->db('off_s', 'CREATE TABLE t (id int PRIMARY KEY, b text);'),
            $this->db('off_t', 'CREATE TABLE t (id int PRIMARY KEY, a text);')
        );
        $this->assertStringNotContainsString('RENAME COLUMN', $up);
        $this->assertStringContainsString('DROP COLUMN', $up);
    }

    public function testARenamedTableKeepsItsRows(): void
    {
        $up = $this->assertRoundTrip(
            'table',
            "CREATE TABLE customers (id serial PRIMARY KEY, name text NOT NULL, CONSTRAINT customers_name_check CHECK (name <> ''));
             CREATE INDEX customers_name_idx ON customers (name);
             CREATE TABLE orders (id int PRIMARY KEY, customer int REFERENCES customers (id));
             INSERT INTO customers (name) VALUES ('Ann'), ('Bo');",
            "CREATE TABLE clients (id serial PRIMARY KEY, name text NOT NULL, CONSTRAINT clients_name_check CHECK (name <> ''));
             CREATE INDEX clients_name_idx ON clients (name);
             CREATE TABLE orders (id int PRIMARY KEY, customer int REFERENCES clients (id));
             INSERT INTO clients (name) VALUES ('Ann'), ('Bo');",
            fn(string $db) => $this->rows($db,
                "SELECT c.relname, (SELECT count(*) FROM pg_class t WHERE t.relname IN ('customers', 'clients') AND t.relkind = 'r') AS tables
                   FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace ORDER BY 1")
        );
        $this->assertStringContainsString('ALTER TABLE "clients" RENAME TO "customers"', $up);
        $this->assertStringNotContainsString('DROP TABLE', $up);
    }

    /**
     * A table renamed on the source keeps its NOT NULL constraints' old names
     * from PostgreSQL 18, where they are constraints: read as constraints of
     * their own beside the target's plain NOT NULLs, it no longer matched its
     * old self, was created afresh, and its primary key's old name clashed
     * with the target's.
     */
    public function testATableRenamedOnTheSourceIsStillARename(): void
    {
        $up = $this->assertRoundTrip(
            'renamed_source',
            "CREATE TABLE settings (key text PRIMARY KEY, value jsonb NOT NULL);
             ALTER TABLE settings RENAME TO config;",
            "CREATE TABLE settings (key text PRIMARY KEY, value jsonb NOT NULL);
             INSERT INTO settings VALUES ('plan', '{\"tier\": \"pro\"}'), ('flags', '{}');",
            fn(string $db) => $this->rows($db, "SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind = 'r'")
        );
        $this->assertStringContainsString('ALTER TABLE "settings" RENAME TO "config"', $up);
        $this->assertStringNotContainsString('CREATE TABLE', $up);
    }

    public function testTablesSharingAStructureAreNotGuessed(): void
    {
        [$up] = $this->diff(
            $this->db('tamb_s', 'CREATE TABLE c (id int); CREATE TABLE d (id int);'),
            $this->db('tamb_t', 'CREATE TABLE a (id int); CREATE TABLE b (id int);')
        );
        $this->assertStringNotContainsString('RENAME TO', $up);
        $this->assertStringContainsString('not taken for renames', $this->lastLog);
    }
}
