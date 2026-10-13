<?php

/**
 * A changed view that other objects read.
 *
 * PostgreSQL refuses to drop a view another view or a policy depends on, so a
 * changed view that was dropped and created again failed the whole
 * migration. Each case here must apply, converge and revert.
 *
 * See PostgresRoundTripTestCase for how each case is checked.
 */
class ViewReplacementRoundTripPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_vr';

    private const ITEMS = 'CREATE TABLE items (id int PRIMARY KEY, colour text, grp int);';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin->exec(
            "DO \$\$ BEGIN CREATE ROLE dbdiff_vr_reader NOLOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$"
        );
    }

    public function testAChangedFilterUnderAnotherViewIsReplacedInPlace(): void
    {
        $up = $this->assertRoundTrip(
            'filter',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id, colour FROM items WHERE grp = 2;
                           CREATE VIEW on_top AS SELECT * FROM base_v;
                           CREATE TABLE new_on_source (id int PRIMARY KEY);',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id, colour FROM items WHERE grp = 1;
                           CREATE VIEW on_top AS SELECT * FROM base_v;'
        );

        $this->assertStringContainsString('CREATE OR REPLACE VIEW "base_v"', $up);
        $this->assertStringNotContainsString('DROP VIEW', $up);
    }

    public function testAnAddedColumnIsReplacedInPlaceAndRemovedAroundItsDependants(): void
    {
        // Up, the view gains a column: in place. Down it loses it again,
        // which CREATE OR REPLACE cannot do.
        [$up, $down] = $this->migrationOf(
            'added',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id, colour, grp FROM items;
                           CREATE VIEW on_top AS SELECT id FROM base_v;
                           GRANT INSERT ON base_v TO dbdiff_vr_reader;',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id, colour FROM items;
                           CREATE VIEW on_top AS SELECT id FROM base_v;
                           GRANT SELECT ON base_v TO dbdiff_vr_reader;'
        );

        $this->assertStringContainsString('CREATE OR REPLACE VIEW "base_v"', $up);
        $this->assertStringContainsString('DROP VIEW IF EXISTS "on_top"', $down);
    }

    public function testARenamedColumnPutsEveryDependantBackWhole(): void
    {
        $function = 'CREATE FUNCTION base_ins() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;';
        $around = static fn(string $base, string $col) => self::ITEMS . $function . "
            CREATE TABLE notes (id int PRIMARY KEY, item_id int);
            ALTER TABLE notes ENABLE ROW LEVEL SECURITY;
            $base
            CREATE TRIGGER base_ins INSTEAD OF INSERT ON base_v FOR EACH ROW EXECUTE FUNCTION base_ins();
            COMMENT ON VIEW base_v IS 'base';
            CREATE VIEW a_top AS SELECT id FROM base_v;
            CREATE VIEW m_mid AS SELECT id, $col FROM base_v;
            CREATE VIEW deeper AS SELECT id FROM a_top;
            GRANT SELECT ON a_top TO dbdiff_vr_reader;
            COMMENT ON VIEW a_top IS 'top';
            COMMENT ON COLUMN a_top.id IS 'the id';
            CREATE MATERIALIZED VIEW mv AS SELECT id FROM base_v;
            CREATE INDEX mv_id ON mv (id);
            CREATE POLICY reads_base ON notes FOR SELECT USING (item_id IN (SELECT id FROM base_v));
        ";
        $this->assertRoundTrip(
            'renamed',
            $around('CREATE VIEW base_v AS SELECT id, colour AS color FROM items WHERE grp = 2;', 'color'),
            $around('CREATE VIEW base_v AS SELECT id, colour FROM items WHERE grp = 1;', 'colour')
        );
    }

    public function testADependantSortingFirstByNameIsChangedAfterTheViewItReads(): void
    {
        // a_top reads z_base, and both change in a way that drops them.
        $this->assertRoundTrip(
            'order',
            self::ITEMS . 'CREATE VIEW z_base AS SELECT id, colour AS color FROM items;
                           CREATE VIEW a_top AS SELECT id, color AS c FROM z_base;',
            self::ITEMS . 'CREATE VIEW z_base AS SELECT id, colour FROM items;
                           CREATE VIEW a_top AS SELECT id, colour FROM z_base;'
        );
    }

    public function testAViewOverAChangedMaterialisedView(): void
    {
        $this->assertRoundTrip(
            'matview',
            self::ITEMS . 'CREATE MATERIALIZED VIEW mv AS SELECT id FROM items WHERE grp = 2;
                           CREATE VIEW on_mv AS SELECT * FROM mv;
                           GRANT SELECT ON on_mv TO dbdiff_vr_reader;',
            self::ITEMS . 'CREATE MATERIALIZED VIEW mv AS SELECT id FROM items WHERE grp = 1;
                           CREATE VIEW on_mv AS SELECT * FROM mv;
                           GRANT SELECT ON on_mv TO dbdiff_vr_reader;'
        );
    }

    public function testAChangedViewOverARetypedColumn(): void
    {
        // The column change drops both views; the changed one puts back
        // what reads it, once it exists.
        $this->assertRoundTrip(
            'retyped',
            'CREATE TABLE items (id int PRIMARY KEY, colour text, grp bigint);
             CREATE VIEW base_v AS SELECT id, colour FROM items WHERE grp = 2;
             CREATE VIEW on_top AS SELECT * FROM base_v;',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id, colour FROM items WHERE grp = 1;
                           CREATE VIEW on_top AS SELECT * FROM base_v;'
        );
    }

    public function testAViewTheChangeStopsReadingAndANewOneThatStarts(): void
    {
        $this->assertRoundTrip(
            'moved',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id AS key FROM items;
                           CREATE VIEW was_reader AS SELECT id FROM items;
                           CREATE VIEW new_reader AS SELECT key FROM base_v;',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id FROM items;
                           CREATE VIEW was_reader AS SELECT id FROM base_v;'
        );
    }

    public function testNewAndChangedPoliciesAndTriggersAroundAReplacedView(): void
    {
        $common = self::ITEMS . 'CREATE TABLE notes (id int PRIMARY KEY, item_id int);
            ALTER TABLE notes ENABLE ROW LEVEL SECURITY;
            CREATE FUNCTION f() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;';
        $this->assertRoundTrip(
            'around',
            $common . 'CREATE VIEW base_v AS SELECT id AS key FROM items;
                CREATE TRIGGER kept INSTEAD OF INSERT ON base_v FOR EACH ROW EXECUTE FUNCTION f();
                CREATE TRIGGER added INSTEAD OF DELETE ON base_v FOR EACH ROW EXECUTE FUNCTION f();
                CREATE POLICY changed ON notes USING (item_id IN (SELECT key FROM base_v));
                CREATE POLICY added ON notes FOR UPDATE USING (item_id IN (SELECT key FROM base_v));
                CREATE POLICY kept ON notes FOR INSERT WITH CHECK (EXISTS (SELECT 1 FROM base_v));
                COMMENT ON POLICY kept ON notes IS \'kept policy\';
                COMMENT ON TRIGGER kept ON base_v IS \'kept trigger\';',
            $common . 'CREATE VIEW base_v AS SELECT id FROM items;
                CREATE TRIGGER kept INSTEAD OF INSERT ON base_v FOR EACH ROW EXECUTE FUNCTION f();
                CREATE TRIGGER dropped INSTEAD OF UPDATE ON base_v FOR EACH ROW EXECUTE FUNCTION f();
                CREATE POLICY changed ON notes USING (item_id IN (SELECT id FROM base_v WHERE id > 0));
                CREATE POLICY dropped ON notes FOR DELETE USING (item_id IN (SELECT id FROM base_v));
                CREATE POLICY kept ON notes FOR INSERT WITH CHECK (EXISTS (SELECT 1 FROM base_v));
                COMMENT ON POLICY kept ON notes IS \'kept policy\';
                COMMENT ON TRIGGER kept ON base_v IS \'kept trigger\';',
            fn(string $db) => $this->rows($db, "SELECT obj_description(oid, 'pg_policy') AS c FROM pg_policy WHERE polname = 'kept'
                UNION ALL SELECT obj_description(oid, 'pg_trigger') FROM pg_trigger WHERE tgname = 'kept'")
        );
    }

    public function testAChangedDependantTheReplacementDropsIsCreatedWhole(): void
    {
        // on_top's own change could be made in place, but base_v's
        // replacement has dropped it by then.
        $this->assertRoundTrip(
            'forced',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id AS key, colour FROM items;
                           CREATE VIEW on_top AS SELECT colour FROM base_v WHERE colour <> \'x\';
                           GRANT SELECT ON on_top TO dbdiff_vr_reader;
                           COMMENT ON VIEW on_top IS \'kept\';',
            self::ITEMS . 'CREATE VIEW base_v AS SELECT id, colour FROM items;
                           CREATE VIEW on_top AS SELECT colour FROM base_v;
                           GRANT SELECT ON on_top TO dbdiff_vr_reader;
                           COMMENT ON VIEW on_top IS \'kept\';'
        );
    }

    public function testADependantTheSourceDropsAndOneInAnotherSchema(): void
    {
        $this->schemas = 'public,api';
        $this->assertRoundTrip(
            'schemas',
            'CREATE SCHEMA api;' . self::ITEMS . 'CREATE VIEW base_v AS SELECT id AS key FROM items;
                           CREATE VIEW api.reader AS SELECT key FROM public.base_v;',
            'CREATE SCHEMA api;' . self::ITEMS . 'CREATE VIEW base_v AS SELECT id FROM items;
                           CREATE VIEW api.reader AS SELECT id FROM public.base_v;
                           CREATE VIEW gone AS SELECT id FROM base_v;'
        );
    }

    /** Round-trip as assertRoundTrip does, returning both directions. */
    private function migrationOf(string $case, string $sourceSql, string $targetSql): array
    {
        $this->assertRoundTrip($case, $sourceSql, $targetSql);
        return $this->diff($this->db("{$case}_s2", $sourceSql), $this->db("{$case}_t2", $targetSql));
    }
}
