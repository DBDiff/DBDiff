<?php

/**
 * COMMENT ON, for every kind of object, checked by pg-conformance's state
 * query as well as by DBDiff diffing again — DBDiff did not read comments
 * at all, so diffing again could not tell one was lost.
 */
class CommentsRoundTripTest extends PostgresRoundTripTestCase
{
    use CorpusState;

    protected string $prefix = 'dbdiff_cm';
    protected string $schemas = '*';

    private const OBJECTS = "
        CREATE TABLE h (id int CONSTRAINT h_pos CHECK (id > 0), n text);
        CREATE INDEX h_i ON h (n);
        CREATE VIEW h_v AS SELECT id FROM h;
        CREATE FUNCTION h_f() RETURNS int LANGUAGE sql AS \$\$ SELECT 1 \$\$;
        CREATE DOMAIN dd AS int CONSTRAINT dd_pos CHECK (VALUE > 0);
        CREATE TYPE pr AS (a int);
        CREATE TYPE en AS ENUM ('x');
        CREATE SEQUENCE sq;
        CREATE FUNCTION h_t() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
        CREATE TRIGGER h_tr BEFORE UPDATE ON h FOR EACH ROW EXECUTE FUNCTION h_t();
        ALTER TABLE h ENABLE ROW LEVEL SECURITY;
        CREATE POLICY h_p ON h USING (id > 0);";

    private const COMMENTS = "
        COMMENT ON TABLE h IS 'the table'; COMMENT ON COLUMN h.n IS 'it''s the column';
        COMMENT ON INDEX h_i IS 'the index'; COMMENT ON VIEW h_v IS 'the view';
        COMMENT ON COLUMN h_v.id IS 'a view column'; COMMENT ON FUNCTION h_f() IS 'the function';
        COMMENT ON CONSTRAINT h_pos ON h IS 'positive'; COMMENT ON DOMAIN dd IS 'a domain';
        COMMENT ON CONSTRAINT dd_pos ON DOMAIN dd IS 'domain check'; COMMENT ON TYPE pr IS 'a pair';
        COMMENT ON COLUMN pr.a IS 'an attribute'; COMMENT ON TYPE en IS 'an enum';
        COMMENT ON SEQUENCE sq IS 'a sequence'; COMMENT ON TRIGGER h_tr ON h IS 'the trigger';
        COMMENT ON POLICY h_p ON h IS 'the policy';";

    private function roundTrips(string $case, string $source, string $target): string
    {
        return $this->assertRoundTrip($case, $source, $target, fn(string $db) => $this->schemaState($db) + [
            // The state query reads tables', columns', views' and routines'
            // comments; this reads every one.
            'comments' => $this->rows($db,
                "SELECT pg_describe_object(classoid, objoid, objsubid) AS object, description
                   FROM pg_description d
                  WHERE objoid >= 16384
                    AND NOT EXISTS (SELECT 1 FROM pg_depend e WHERE e.objid = d.objoid AND e.deptype = 'e')
                  ORDER BY 1"),
        ]);
    }

    public function testCommentsAreAddedAndRemoved(): void
    {
        $up = $this->roundTrips('added', self::OBJECTS . self::COMMENTS, self::OBJECTS);
        $this->assertStringContainsString("COMMENT ON COLUMN public.h.n IS 'it''s the column';", $up);
    }

    public function testCommentsChange(): void
    {
        $this->roundTrips('changed', self::OBJECTS . self::COMMENTS,
            self::OBJECTS . str_replace("IS '", "IS 'old ", self::COMMENTS));
    }

    public function testObjectsArriveWithTheirComments(): void
    {
        $this->roundTrips('created', self::OBJECTS . self::COMMENTS, '');
    }

    // Each of these is dropped and recreated by its own change, which takes
    // its comment with it, though both sides have the same one.
    public function testARecreatedObjectKeepsItsComment(): void
    {
        $base = "CREATE TABLE r (id int CONSTRAINT r_pos CHECK (id > 0), n text);
                 CREATE INDEX r_i ON r (n);
                 CREATE VIEW r_v AS SELECT id FROM r;
                 CREATE FUNCTION r_f() RETURNS int LANGUAGE sql AS \$\$ SELECT 1 \$\$;
                 CREATE DOMAIN rd AS int CONSTRAINT rd_pos CHECK (VALUE > 0);
                 ALTER TABLE r ENABLE ROW LEVEL SECURITY;
                 CREATE POLICY r_p ON r USING (id > 0);";
        $comments = "COMMENT ON VIEW r_v IS 'v'; COMMENT ON COLUMN r_v.id IS 'vc'; COMMENT ON FUNCTION r_f() IS 'f';
                     COMMENT ON INDEX r_i IS 'i'; COMMENT ON CONSTRAINT r_pos ON r IS 'c'; COMMENT ON DOMAIN rd IS 'd';
                     COMMENT ON CONSTRAINT rd_pos ON DOMAIN rd IS 'dc'; COMMENT ON POLICY r_p ON r IS 'p';";
        $changed = str_replace(
            ['SELECT id FROM r', 'SELECT 1', '(n)', 'id > 0)', 'VALUE > 0', 'USING (id > 0)'],
            ['SELECT id, n FROM r', 'SELECT 2', '(n, id)', 'id > 1)', 'VALUE > 1', 'USING (id > 1)'],
            $base
        );
        $this->roundTrips('recreated', $changed . $comments, $base . $comments);
    }

    public function testACommentInAnotherSchema(): void
    {
        $schema = 'CREATE SCHEMA "App Data"; CREATE TABLE "App Data"."T" (n text);';
        $up = $this->roundTrips('schema', $schema . "COMMENT ON COLUMN \"App Data\".\"T\".n IS 'x';", $schema);
        $this->assertStringContainsString('COMMENT ON COLUMN "App Data"."T".n IS', $up);
    }

    // An extension's objects are its own; their comments are not ours to set.
    public function testAnExtensionsCommentsAreLeftAlone(): void
    {
        $this->assertNull($this->diff(
            $this->db('ext_s', 'CREATE EXTENSION pg_trgm;'),
            $this->db('ext_t', "CREATE EXTENSION pg_trgm; COMMENT ON FUNCTION similarity(text, text) IS 'changed';")
        ));
    }
}
