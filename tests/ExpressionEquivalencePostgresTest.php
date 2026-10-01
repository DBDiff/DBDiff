<?php

/**
 * The same definition rendered twice is not a change (issue #234).
 *
 * `status IN ('draft', 'active')` on a varchar column renders one way as
 * written and another once recreated from that rendering — which is what a
 * dump, a restore or a generated migration does. Every kind that holds an
 * expression was affected — domain CHECKs and generated columns included. Each case builds the target from a pg_dump-style
 * rendering of the source and requires "identical"; and changes the list on
 * the target and requires it still to be reported, so equivalence never hides
 * a real difference.
 *
 * Skips automatically when pdo_pgsql is missing or DB_HOST_POSTGRES is unset.
 */
class ExpressionEquivalencePostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_eq';

    /** Each kind written as a developer would, then as it renders. */
    private const AS_WRITTEN = [
        'check'   => "CREATE TABLE t (status varchar(20), n int, CONSTRAINT c CHECK (status IN ('draft', 'active')));",
        'index'   => "CREATE TABLE t (status varchar(20), n int); CREATE INDEX i ON t (n) WHERE status IN ('draft', 'active');",
        'policy'  => "CREATE TABLE t (status varchar(20)); ALTER TABLE t ENABLE ROW LEVEL SECURITY;
                      CREATE POLICY p ON t USING (status IN ('draft', 'active'));",
        'view'    => "CREATE TABLE t (status varchar(20)); CREATE VIEW v AS SELECT * FROM t WHERE status IN ('draft', 'active');",
        'matview' => "CREATE TABLE t (status varchar(20)); CREATE MATERIALIZED VIEW m AS SELECT * FROM t WHERE status IN ('draft', 'active');",
        'trigger' => "CREATE TABLE t (status varchar(20));
                      CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
                      CREATE TRIGGER tr BEFORE UPDATE ON t FOR EACH ROW WHEN (NEW.status IN ('draft', 'active')) EXECUTE FUNCTION tf();",
        'domain'    => "CREATE DOMAIN d AS varchar(20) CHECK (VALUE IN ('draft', 'active')); CREATE TABLE t (status d);",
        'generated' => "CREATE TABLE t (status varchar(20), ok boolean GENERATED ALWAYS AS (status IN ('draft', 'active')) STORED);",
    ];

    /** The same objects as they render — how a dump or a migration recreates them. */
    private const AS_RENDERED = [
        'check'   => "CREATE TABLE t (status varchar(20), n int, CONSTRAINT c CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]))));",
        'index'   => "CREATE TABLE t (status varchar(20), n int); CREATE INDEX i ON t (n) WHERE ((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]));",
        'policy'  => "CREATE TABLE t (status varchar(20)); ALTER TABLE t ENABLE ROW LEVEL SECURITY;
                      CREATE POLICY p ON t USING (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[])));",
        'view'    => "CREATE TABLE t (status varchar(20)); CREATE VIEW v AS SELECT t.status FROM t WHERE ((t.status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]));",
        'matview' => "CREATE TABLE t (status varchar(20)); CREATE MATERIALIZED VIEW m AS SELECT t.status FROM t WHERE ((t.status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]));",
        'trigger' => "CREATE TABLE t (status varchar(20));
                      CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
                      CREATE TRIGGER tr BEFORE UPDATE ON t FOR EACH ROW WHEN (((new.status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]))) EXECUTE FUNCTION tf();",
        'domain'    => "CREATE DOMAIN d AS varchar(20) CHECK (((VALUE)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]))); CREATE TABLE t (status d);",
        'generated' => "CREATE TABLE t (status varchar(20), ok boolean GENERATED ALWAYS AS (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying])::text[]))) STORED);",
    ];

    /** The UP from `$source` to `$target`, or null when they are identical. */
    private function up(string $source, string $target): ?string
    {
        return $this->diff($source, $target)[0] ?? null;
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return array_combine(array_keys(self::AS_WRITTEN), array_map(fn($k) => [$k], array_keys(self::AS_WRITTEN)));
    }

    /** @dataProvider kinds */
    public function testARenderedCopyIsIdentical(string $kind): void
    {
        $source = $this->db("{$kind}_s", self::AS_WRITTEN[$kind]);
        $target = $this->db("{$kind}_t", self::AS_RENDERED[$kind]);

        $this->assertNull($this->up($source, $target), "$kind: the same definition rendered twice was reported as a change");
    }

    /** @dataProvider kinds */
    public function testARealChangeIsStillReported(string $kind): void
    {
        $source = $this->db("{$kind}_rs", self::AS_WRITTEN[$kind]);
        $target = $this->db("{$kind}_rt", str_replace("'active'", "'archived'", self::AS_RENDERED[$kind]));

        $this->assertNotNull($this->up($source, $target), "$kind: a changed list was hidden as equivalent");
    }
}
