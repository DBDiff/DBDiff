<?php

/**
 * Collations of the schema being compared are created, replaced and dropped.
 *
 * A column, a domain or an index can be collated by a collation of the
 * schema — a case-insensitive ICU one for Gurmukhi, say. They were not
 * diffed at all, so the CREATE TABLE that used one failed: 'collation
 * "gurmukhi_ci" for encoding "UTF8" does not exist'.
 */
class CollationsRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_coll';

    private const USES = "
        CREATE COLLATION gurmukhi_ci (provider = icu, locale = 'pa-u-ks-level2', deterministic = false);
        CREATE COLLATION bytes (provider = libc, locale = 'C');
        CREATE DOMAIN shabad_title AS text COLLATE gurmukhi_ci;
        CREATE TABLE shabads (id int PRIMARY KEY, title text COLLATE gurmukhi_ci, t2 shabad_title, code text COLLATE bytes);
        CREATE INDEX shabads_code ON shabads (code COLLATE bytes);
        INSERT INTO shabads VALUES (1, 'ਜਪੁ', 'ਜਪੁ', 'a');";

    public function testACollationIsCreatedBeforeWhatUsesItAndDroppedAfter(): void
    {
        $up = $this->assertRoundTrip('uses', self::USES,
            // The target has one the source lacks, so the UP drops it and the DOWN puts it back.
            "CREATE COLLATION only_here (provider = icu, locale = 'und'); CREATE TABLE other (id int);",
            fn(string $db) => $this->collations($db));

        $this->assertLessThan(strpos($up, 'CREATE DOMAIN'), strpos($up, 'CREATE COLLATION "gurmukhi_ci"'), $up);
        $this->assertLessThan(strpos($up, 'CREATE TABLE'), strpos($up, 'CREATE COLLATION "bytes"'), $up);
    }

    public function testAChangedCollationIsReplaced(): void
    {
        $this->assertRoundTrip('changed',
            "CREATE COLLATION folded (provider = icu, locale = 'pa-u-ks-level2', deterministic = false);",
            "CREATE COLLATION folded (provider = libc, locale = 'C');",
            fn(string $db) => $this->collations($db));
    }

    public function testRulesSurvive(): void
    {
        if ($this->serverVersion < 160000) {
            $this->markTestSkipped('ICU tailoring rules arrived in PostgreSQL 16.');
        }
        $this->assertRoundTrip('rules',
            "CREATE COLLATION \"tailored's\" (provider = icu, locale = 'und', rules = '&a < b');",
            '',
            fn(string $db) => $this->collations($db));
    }

    /** The schema's collations, compared on what they do, not how they are stored. */
    private function collations(string $db): array
    {
        return $this->connect($db)->query(
            "SELECT concat_ws(':', collname, collprovider::text, collisdeterministic::text, collcollate, collctype,
                    to_jsonb(c) ->> 'colllocale', to_jsonb(c) ->> 'colliculocale', to_jsonb(c) ->> 'collicurules')
               FROM pg_collation c WHERE collnamespace = 'public'::regnamespace ORDER BY collname"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
}
