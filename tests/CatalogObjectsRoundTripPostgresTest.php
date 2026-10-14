<?php

/**
 * Object kinds read straight from the catalog — see PostgresCatalogObjects.
 * Each is created where only the source has it, dropped where only the
 * target does, replaced where they differ, and the DOWN puts the target back.
 *
 * See PostgresRoundTripTestCase for how each case is checked.
 */
class CatalogObjectsRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_cat';

    public function testRangeTypes(): void
    {
        $up = $this->assertRoundTrip(
            'range',
            "CREATE FUNCTION f8_diff(float8, float8) RETURNS float8 LANGUAGE sql IMMUTABLE AS 'SELECT \$1 - \$2';
             CREATE TYPE floatrange AS RANGE (subtype = float8, subtype_diff = f8_diff);
             CREATE TYPE text_span AS RANGE (subtype = text, collation = \"C\", multirange_type_name = text_spans);
             CREATE TABLE booking (id int PRIMARY KEY, span floatrange, letters text_span);
             INSERT INTO booking VALUES (1, '[1.5,2.5)', '[a,c)');",
            "CREATE TYPE old_range AS RANGE (subtype = int4);
             CREATE TYPE text_span AS RANGE (subtype = text);",
            fn(string $db) => $this->rows($db,
                "SELECT t.typname, format_type(r.rngsubtype, NULL) AS subtype, r.rngsubdiff::text AS diff,
                        r.rngcollation::regcollation::text AS coll, m.typname AS multirange
                   FROM pg_range r JOIN pg_type t ON t.oid = r.rngtypid JOIN pg_type m ON m.oid = r.rngmultitypid
                  WHERE t.typnamespace = 'public'::regnamespace ORDER BY 1")
        );

        // The constructors PostgreSQL makes with the type are not copied as functions.
        $this->assertStringNotContainsString('CREATE OR REPLACE FUNCTION public.floatrange', $up);
    }

    public function testReplicaIdentityAndStatisticsTargets(): void
    {
        $settings = fn(string $db) => $this->rows($db,
            "SELECT c.relname, c.relreplident::text AS identity,
                    (SELECT ic.relname FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid
                      WHERE i.indrelid = c.oid AND i.indisreplident) AS identity_index,
                    (SELECT string_agg(a.attname || '=' || a.attstattarget, ',' ORDER BY a.attname) FROM pg_attribute a
                      WHERE a.attrelid = c.oid AND a.attnum > 0 AND COALESCE(a.attstattarget, -1) >= 0) AS statistics
               FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r' ORDER BY 1");
        $this->assertRoundTrip(
            'replident',
            "CREATE TABLE messages (id int PRIMARY KEY, body text, room int);
             ALTER TABLE messages REPLICA IDENTITY FULL;
             ALTER TABLE messages ALTER COLUMN room SET STATISTICS 500;
             CREATE TABLE keyed (id int NOT NULL, code text NOT NULL);
             CREATE UNIQUE INDEX keyed_code ON keyed (code);
             ALTER TABLE keyed REPLICA IDENTITY USING INDEX keyed_code;
             CREATE TABLE added (id int PRIMARY KEY, v int);
             ALTER TABLE added REPLICA IDENTITY FULL;
             ALTER TABLE added ALTER COLUMN v SET STATISTICS 250;",
            "CREATE TABLE messages (id int PRIMARY KEY, body text, room int);
             ALTER TABLE messages ALTER COLUMN body SET STATISTICS 50;
             CREATE TABLE keyed (id int NOT NULL, code text NOT NULL);",
            $settings
        );
    }
}
