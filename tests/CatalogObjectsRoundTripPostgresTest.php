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
}
