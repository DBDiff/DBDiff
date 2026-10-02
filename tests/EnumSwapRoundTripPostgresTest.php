<?php

/**
 * Removing or reordering an enum's labels (issue #237), against a live server.
 *
 * There is no `DROP VALUE`: the columns move to a new type with the new
 * labels, which then takes the old one's name. Each case checks the round
 * trip (see PostgresRoundTripTestCase) and that the rows themselves survive.
 *
 * Reordering labels, a plain round trip, is in the shared corpus
 * (CorpusMigrationsRoundTripTest) with the other enum changes. These check
 * what is specific to DBDiff: the SQL it writes, and what it must carry along.
 */
class EnumSwapRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_enum';

    private const THREE = "CREATE TYPE st AS ENUM ('new', 'paid', 'shipped');";
    private const TWO   = "CREATE TYPE st AS ENUM ('new', 'shipped');";

    private const TABLE = "
        CREATE TABLE o (id int PRIMARY KEY, s st NOT NULL DEFAULT 'new', arr st[] DEFAULT '{new}', s2 st);
        INSERT INTO o VALUES (1, 'new', '{new,shipped}', 'shipped'), (2, 'shipped', NULL, 'new');
    ";

    /** The rows, which every swap must carry across unchanged. */
    private function data(): callable
    {
        return fn(string $db) => $this->rows($db, 'SELECT id, s::text, arr::text, s2::text FROM o ORDER BY id');
    }

    /** The type's comment and grants, which DROP TYPE takes with it. */
    private function typeMetadata(): callable
    {
        return fn(string $db) => $this->rows(
            $db,
            "SELECT typname, coalesce(obj_description(oid, 'pg_type'), '') AS comment,
                    coalesce(typacl::text, '') AS acl
               FROM pg_type WHERE typname = 'Order State' OR typname = 'st'"
        );
    }

    public function testRemovingALabelKeepsEveryRow(): void
    {
        $up = $this->assertRoundTrip('remove', self::TWO . self::TABLE, self::THREE . self::TABLE, $this->data());

        $this->assertStringContainsString('CREATE TYPE "st__new" AS ENUM (\'new\', \'shipped\');', $up);
        $this->assertStringContainsString('USING "arr"::text[]::"st__new"[]', $up);
        $this->assertStringContainsString('ALTER TYPE "st__new" RENAME TO "st";', $up);
        $this->assertStringNotContainsString('DROP TYPE IF EXISTS', $up);
    }

    public function testAddingALabelSwapsOnlyOnTheWayDown(): void
    {
        $up = $this->assertRoundTrip('add', self::THREE . self::TABLE, self::TWO . self::TABLE, $this->data());

        $this->assertStringContainsString("ADD VALUE IF NOT EXISTS 'paid'", $up);
        $this->assertStringNotContainsString('st__new', $up);
    }

    public function testEverythingThatReadsTheColumnsStandsAsideAndComesBack(): void
    {
        $readers = "
            CREATE VIEW v AS SELECT id, s FROM o WHERE s2 IS NOT NULL;
            CREATE VIEW v2 AS SELECT * FROM v;
            COMMENT ON VIEW v IS 'documented';
            CREATE MATERIALIZED VIEW mv AS SELECT s FROM o;
            CREATE INDEX mv_s ON mv (s);
            ALTER TABLE o ENABLE ROW LEVEL SECURITY;
            CREATE POLICY p ON o USING (s = 'new');
            CREATE FUNCTION tf() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NEW; END';
            CREATE TRIGGER tr BEFORE UPDATE ON o FOR EACH ROW WHEN (NEW.s = 'new') EXECUTE FUNCTION tf();
            ALTER TABLE o ADD COLUMN g boolean GENERATED ALWAYS AS (s = 'new') STORED;
            CREATE INDEX o_p ON o (id) WHERE s = 'shipped';
            ALTER TABLE o ADD CONSTRAINT o_ck CHECK (s <> 'shipped' OR s2 IS NOT NULL);
            COMMENT ON CONSTRAINT o_ck ON o IS 'shipped needs s2';
            ALTER TABLE o ADD CONSTRAINT o_u UNIQUE (s, id);
            CREATE TABLE r (id int, s st, FOREIGN KEY (s, id) REFERENCES o (s, id));
        ";
        $this->assertRoundTrip('readers', self::TWO . self::TABLE . $readers, self::THREE . self::TABLE . $readers, $this->data());
    }

    public function testAConstraintOrIndexNamingTheRemovedLabelIsLeftToItsOwnDiff(): void
    {
        $paid = "ALTER TABLE o ADD CONSTRAINT ck_paid CHECK (s <> 'paid');
                 CREATE INDEX o_paid ON o (id) WHERE s = 'paid';";

        // The UP removes the label and, with it, what names it.
        $up = $this->assertRoundTrip('labelup', self::TWO . self::TABLE, self::THREE . self::TABLE . $paid);
        $this->assertStringNotContainsString('ADD CONSTRAINT "ck_paid"', $up);
        // The swap drops it itself, and the constraint's own DROP tolerates
        // that: either can run first, as a tool applying changes one at a
        // time may order them.
        $this->assertStringContainsString('ALTER TABLE "o" DROP CONSTRAINT IF EXISTS "ck_paid";', $up);
        $this->assertSame(2, substr_count($up, 'DROP CONSTRAINT IF EXISTS "ck_paid"'));

        // The DOWN does: the source has the label and its check.
        $this->assertRoundTrip('labeldown', self::THREE . self::TABLE . $paid, self::TWO . self::TABLE);
    }

    public function testADefaultNamingTheRemovedLabelTakesTheSourcesDefault(): void
    {
        $this->assertRoundTrip(
            'default',
            self::TWO . "CREATE TABLE o (id int, s st DEFAULT 'new');",
            self::THREE . "CREATE TABLE o (id int, s st DEFAULT 'paid');"
        );
    }

    public function testAViewTheSourceChangesToNameTheLabelIsCreatedAfterIt(): void
    {
        $this->assertRoundTrip(
            'viewchg',
            self::TWO . self::TABLE . "CREATE VIEW v AS SELECT id, s FROM o WHERE s = 'new';",
            self::THREE . self::TABLE . "CREATE VIEW v AS SELECT id, s FROM o WHERE s = 'paid';
                                         CREATE VIEW w AS SELECT s FROM o;"
        );
    }

    public function testInheritedAndPartitionedColumnsFollowTheirParent(): void
    {
        $tables = "
            CREATE TABLE par (id int, s st DEFAULT 'new');
            CREATE TABLE kid (x int) INHERITS (par);
            ALTER TABLE kid ALTER s SET DEFAULT 'shipped';
            CREATE TABLE pq (id int, s st DEFAULT 'new') PARTITION BY RANGE (id);
            CREATE TABLE pq1 PARTITION OF pq FOR VALUES FROM (0) TO (10);
            CREATE INDEX ON pq (id) WHERE s = 'new';
        ";
        $this->assertRoundTrip('inherit', self::TWO . $tables, self::THREE . $tables);
    }

    public function testAQuotedTypeKeepsItsCommentAndGrants(): void
    {
        $table = "CREATE TABLE o (s \"Order State\" DEFAULT 'new');
                  COMMENT ON TYPE \"Order State\" IS 'the state';
                  REVOKE USAGE ON TYPE \"Order State\" FROM PUBLIC;
                  GRANT USAGE ON TYPE \"Order State\" TO pg_monitor;";
        $this->assertRoundTrip(
            'quoted',
            "CREATE TYPE \"Order State\" AS ENUM ('new', 'it''s');" . $table,
            "CREATE TYPE \"Order State\" AS ENUM ('new', 'paid', 'it''s');" . $table,
            $this->typeMetadata()
        );
    }

    /**
     * What a swap cannot carry is named in the migration, and PostgreSQL then
     * refuses it rather than anything being dropped.
     *
     * @dataProvider blockers
     */
    public function testWhatASwapCannotCarryIsNamedAndStopsTheMigration(string $case, string $extra, string $named): void
    {
        $source = $this->db("{$case}_s", self::TWO . self::TABLE . $extra);
        $target = $this->db("{$case}_t", self::THREE . self::TABLE . $extra);

        [$up] = $this->diff($source, $target);
        $this->assertStringContainsString('cannot be moved automatically', $up);
        $this->assertStringContainsString("--   $named", $up);

        try {
            $this->connect($target)->exec($up);
            $this->fail("$case: the migration should have been refused");
        } catch (PDOException $e) {
            $this->assertStringContainsString('cannot drop type st', $e->getMessage());
        }
    }

    public static function blockers(): array
    {
        return [
            'routine argument' => ['fn', "CREATE FUNCTION f(x st) RETURNS int LANGUAGE sql AS 'SELECT 1';", 'function f(st)'],
            'domain'           => ['dom', 'CREATE DOMAIN dst AS st;', 'type dst'],
            'literal in view'  => ['lit', "CREATE VIEW lv AS SELECT 'new'::st AS x;", 'view public.lv'],
            'partition key'    => ['pk', "CREATE TABLE pt (s st) PARTITION BY LIST (s);
                                          CREATE TABLE pt_n PARTITION OF pt FOR VALUES IN ('new');",
                                   'partition key of table pt'],
        ];
    }
}
