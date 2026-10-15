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

    public function testDefaultPrivileges(): void
    {
        $roles = "DO \$\$ BEGIN CREATE ROLE dbdiff_cat_api NOLOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;
                  DO \$\$ BEGIN CREATE ROLE dbdiff_cat_svc NOLOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;";
        $acl = fn(string $db) => $this->rows($db,
            "SELECT pg_get_userbyid(defaclrole) AS role, defaclnamespace::regnamespace::text AS schema,
                    defaclobjtype::text AS kind, defaclacl::text AS acl
               FROM pg_default_acl ORDER BY 1, 2, 3");
        $up = $this->assertRoundTrip(
            'defacl',
            $roles . "
             ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO dbdiff_cat_api;
             ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO dbdiff_cat_svc WITH GRANT OPTION;
             ALTER DEFAULT PRIVILEGES REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
             ALTER DEFAULT PRIVILEGES GRANT EXECUTE ON FUNCTIONS TO dbdiff_cat_api;
             CREATE TABLE created_after (id int);",
            $roles . "
             ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT INSERT, SELECT ON TABLES TO dbdiff_cat_api;
             ALTER DEFAULT PRIVILEGES GRANT USAGE ON TYPES TO dbdiff_cat_svc;",
            fn(string $db) => array_merge($acl($db), $this->rows($db,
                "SELECT relacl::text FROM pg_class WHERE relname = 'created_after'"))
        );
        // Set before the table, so the table is created with them.
        $this->assertLessThan(strpos($up, 'CREATE TABLE'), strpos($up, 'ALTER DEFAULT PRIVILEGES'), $up);
    }

    public function testEventTriggers(): void
    {
        $fn = "CREATE FUNCTION audit_ddl() RETURNS event_trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE NOTICE 'ddl'; END \$\$;
               CREATE FUNCTION forbid_drop() RETURNS event_trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'no drops'; END \$\$;";
        $this->assertRoundTrip(
            'evt',
            $fn . "CREATE EVENT TRIGGER dbdiff_cat_audit ON ddl_command_end WHEN TAG IN ('CREATE TABLE', 'ALTER TABLE') EXECUTE FUNCTION audit_ddl();
                   CREATE EVENT TRIGGER dbdiff_cat_guard ON sql_drop EXECUTE FUNCTION forbid_drop();
                   ALTER EVENT TRIGGER dbdiff_cat_guard DISABLE;
                   CREATE TABLE t (id int);",
            $fn . "CREATE EVENT TRIGGER dbdiff_cat_audit ON ddl_command_end EXECUTE FUNCTION audit_ddl();
                   CREATE EVENT TRIGGER dbdiff_cat_gone ON ddl_command_start EXECUTE FUNCTION audit_ddl();",
            fn(string $db) => $this->rows($db,
                "SELECT evtname, evtevent, evtenabled::text, evttags::text FROM pg_event_trigger WHERE evtname LIKE 'dbdiff_cat_%' ORDER BY 1")
        );
    }

    public function testRules(): void
    {
        $tables = "CREATE TABLE orders (id int PRIMARY KEY, total int); CREATE TABLE audit (id int, note text);
                   CREATE VIEW recent AS SELECT id, total FROM orders;";
        $this->assertRoundTrip(
            'rules',
            $tables . "CREATE RULE keep_orders AS ON DELETE TO orders DO INSTEAD NOTHING;
                       CREATE RULE log_insert AS ON INSERT TO recent DO INSTEAD
                           (INSERT INTO orders VALUES (NEW.id, NEW.total); INSERT INTO audit VALUES (NEW.id, 'via view'));",
            $tables . "CREATE RULE keep_orders AS ON DELETE TO orders DO ALSO INSERT INTO audit VALUES (OLD.id, 'deleted');
                       CREATE RULE old_rule AS ON UPDATE TO orders DO INSTEAD NOTHING;",
            fn(string $db) => $this->rows($db, "SELECT tablename, rulename, definition FROM pg_rules WHERE schemaname = 'public' ORDER BY 1, 2")
        );
    }

    public function testAggregates(): void
    {
        $fns = "CREATE FUNCTION add_int(int, int) RETURNS int LANGUAGE sql IMMUTABLE AS 'SELECT \$1 + \$2';
                CREATE FUNCTION sub_int(int, int) RETURNS int LANGUAGE sql IMMUTABLE AS 'SELECT \$1 - \$2';
                CREATE FUNCTION halve(int) RETURNS numeric LANGUAGE sql IMMUTABLE AS 'SELECT \$1 / 2.0';";
        $this->assertRoundTrip(
            'agg',
            $fns . "CREATE AGGREGATE my_sum(int) (SFUNC = add_int, STYPE = int, INITCOND = '0', COMBINEFUNC = add_int, PARALLEL = SAFE,
                        MSFUNC = add_int, MINVFUNC = sub_int, MSTYPE = int, MINITCOND = '0');
                    CREATE AGGREGATE half_sum(int) (SFUNC = add_int, STYPE = int, FINALFUNC = halve, INITCOND = '0');
                    CREATE AGGREGATE my_max(int) (SFUNC = int4larger, STYPE = int, SORTOP = >);
                    CREATE TABLE t (n int); CREATE VIEW totals AS SELECT my_sum(n), half_sum(n) FROM t;",
            $fns . "CREATE AGGREGATE half_sum(int) (SFUNC = add_int, STYPE = int, FINALFUNC = halve);
                    CREATE AGGREGATE old_agg(int) (SFUNC = add_int, STYPE = int);",
            fn(string $db) => $this->rows($db,
                "SELECT p.oid::regprocedure::text AS agg, to_jsonb(a) - 'aggfnoid' - 'aggtransfn' - 'aggfinalfn' - 'aggcombinefn'
                        - 'aggmtransfn' - 'aggminvtransfn' - 'aggsortop' - 'aggtranstype' - 'aggmtranstype' AS spec,
                        a.aggtransfn::text, a.aggfinalfn::text, a.aggcombinefn::text, a.aggsortop::regoperator::text
                   FROM pg_aggregate a JOIN pg_proc p ON p.oid = a.aggfnoid
                  WHERE p.pronamespace = 'public'::regnamespace ORDER BY 1")
        );
    }

    public function testStatisticsObjects(): void
    {
        $this->assertRoundTrip(
            'stx',
            "CREATE TABLE addr (id int PRIMARY KEY, city text, zip text, country text);
             CREATE STATISTICS addr_city_zip (dependencies, ndistinct) ON city, zip FROM addr;
             ALTER STATISTICS addr_city_zip SET STATISTICS 500;
             CREATE STATISTICS addr_country (mcv) ON country, city FROM addr;",
            "CREATE TABLE addr (id int PRIMARY KEY, city text, zip text, country text);
             CREATE STATISTICS addr_city_zip (ndistinct) ON city, zip FROM addr;
             CREATE STATISTICS addr_old ON city, country FROM addr;",
            fn(string $db) => $this->rows($db,
                "SELECT stxname, pg_get_statisticsobjdef(oid) AS def, COALESCE((to_jsonb(s) ->> 'stxstattarget')::int, -1) AS target
                   FROM pg_statistic_ext s WHERE stxnamespace = 'public'::regnamespace ORDER BY 1")
        );
    }

    public function testTextSearchConfigurationsAndDictionaries(): void
    {
        $state = fn(string $db) => array_merge(
            $this->rows($db, "SELECT dictname, dicttemplate::regproc::text, dictinitoption FROM pg_ts_dict
                               WHERE dictnamespace = 'public'::regnamespace ORDER BY 1"),
            $this->rows($db, "SELECT c.cfgname, t.alias, string_agg(m.mapdict::regdictionary::text, ',' ORDER BY m.mapseqno) AS dicts
                                FROM pg_ts_config c JOIN pg_ts_config_map m ON m.mapcfg = c.oid
                                JOIN ts_token_type(c.cfgparser) t ON t.tokid = m.maptokentype
                               WHERE c.cfgnamespace = 'public'::regnamespace GROUP BY 1, 2 ORDER BY 1, 2"));
        $this->assertRoundTrip(
            'fts',
            "CREATE TEXT SEARCH DICTIONARY my_stem (TEMPLATE = snowball, language = 'english');
             CREATE TEXT SEARCH CONFIGURATION my_cfg (COPY = english);
             ALTER TEXT SEARCH CONFIGURATION my_cfg ALTER MAPPING FOR asciiword, word WITH my_stem;
             ALTER TEXT SEARCH CONFIGURATION my_cfg DROP MAPPING FOR email;
             CREATE TABLE docs (id int PRIMARY KEY, body text,
                 search tsvector GENERATED ALWAYS AS (to_tsvector('my_cfg', body)) STORED);
             CREATE INDEX docs_fts ON docs USING gin (to_tsvector('my_cfg'::regconfig, body));",
            // The target's my_cfg is used by an index, so it can only be changed in place.
            "CREATE TEXT SEARCH CONFIGURATION my_cfg (COPY = simple);
             CREATE TABLE docs (id int PRIMARY KEY, body text);
             CREATE INDEX docs_fts ON docs USING gin (to_tsvector('my_cfg'::regconfig, body));
             CREATE TEXT SEARCH DICTIONARY old_dict (TEMPLATE = simple, stopwords = english);",
            $state
        );
    }

    public function testCasts(): void
    {
        $types = "CREATE DOMAIN flag AS int; CREATE TYPE mood AS ENUM ('sad', 'happy');
                  CREATE FUNCTION flag_to_bool(flag) RETURNS boolean LANGUAGE sql IMMUTABLE AS 'SELECT \$1 <> 0';
                  CREATE FUNCTION mood_to_int(mood) RETURNS int LANGUAGE sql IMMUTABLE AS 'SELECT CASE \$1 WHEN ''sad'' THEN 0 ELSE 1 END';";
        $this->assertRoundTrip(
            'cast',
            $types . "CREATE CAST (flag AS boolean) WITH FUNCTION flag_to_bool(flag) AS ASSIGNMENT;
                      CREATE CAST (mood AS int) WITH FUNCTION mood_to_int(mood);
                      CREATE CAST (mood AS text) WITH INOUT AS IMPLICIT;
                      CREATE VIEW moods AS SELECT 'happy'::mood::int AS n;",
            $types . "CREATE CAST (flag AS boolean) WITH FUNCTION flag_to_bool(flag);
                      CREATE CAST (mood AS varchar) WITH INOUT;",
            fn(string $db) => $this->rows($db,
                "SELECT format_type(castsource, NULL) AS s, format_type(casttarget, NULL) AS t, castmethod::text, castcontext::text,
                        castfunc::regprocedure::text FROM pg_cast WHERE oid >= 16384 ORDER BY 1, 2")
        );
    }

    public function testForeignServersUserMappingsAndTables(): void
    {
        $base = "CREATE EXTENSION IF NOT EXISTS postgres_fdw;
                 DO \$\$ BEGIN CREATE ROLE dbdiff_cat_reader NOLOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;";
        $state = fn(string $db) => array_merge(
            $this->rows($db, "SELECT srvname, srvversion, (SELECT array_agg(o ORDER BY o) FROM unnest(srvoptions) o)::text AS options FROM pg_foreign_server ORDER BY 1"),
            $this->rows($db, "SELECT srvname, usename, (SELECT array_agg(o ORDER BY o) FROM unnest(umoptions) o)::text AS options FROM pg_user_mappings ORDER BY 1, 2"),
            $this->rows($db, "SELECT c.relname, s.srvname, (SELECT array_agg(o ORDER BY o) FROM unnest(f.ftoptions) o)::text AS options,
                                     (SELECT string_agg(a.attname || ' ' || format_type(a.atttypid, a.atttypmod)
                                             || coalesce(' ' || a.attfdwoptions::text, ''), ', ' ORDER BY a.attnum)
                                        FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attnum > 0) AS cols
                                FROM pg_foreign_table f JOIN pg_class c ON c.oid = f.ftrelid
                                JOIN pg_foreign_server s ON s.oid = f.ftserver ORDER BY 1"));

        // Both sides hold the same credential, so it does not decide anything.
        $migration = $this->diff(
            $this->db('fdw_s', $base . "
                CREATE SERVER warehouse VERSION '2' FOREIGN DATA WRAPPER postgres_fdw OPTIONS (host 'db.internal', dbname 'wh', port '5432');
                CREATE USER MAPPING FOR dbdiff_cat_reader SERVER warehouse OPTIONS (user 'reader', password 'hunter2');
                CREATE FOREIGN TABLE remote_orders (id int OPTIONS (column_name 'order_id') NOT NULL, total numeric(10,2))
                    SERVER warehouse OPTIONS (schema_name 'sales', table_name 'orders');"),
            $this->db('fdw_t', $base . "
                CREATE SERVER warehouse FOREIGN DATA WRAPPER postgres_fdw OPTIONS (host 'old.internal', dbname 'wh');
                CREATE USER MAPPING FOR dbdiff_cat_reader SERVER warehouse OPTIONS (user 'old', password 'other-secret');
                CREATE FOREIGN TABLE remote_orders (id int) SERVER warehouse OPTIONS (table_name 'orders');
                CREATE SERVER retired FOREIGN DATA WRAPPER postgres_fdw;")
        );
        $this->assertNotNull($migration);
        [$up, $down] = $migration;
        $this->assertStringNotContainsString('hunter2', $up . $down);
        $this->assertStringNotContainsString('other-secret', $up . $down);
        // Changed where it stands: a foreign table uses it.
        $this->assertStringNotContainsString('DROP SERVER IF EXISTS "warehouse"', $up);

        // Without credentials in play, the whole round trip.
        $this->assertRoundTrip(
            'fdw',
            $base . "CREATE SERVER warehouse VERSION '2' FOREIGN DATA WRAPPER postgres_fdw OPTIONS (host 'db.internal', dbname 'wh');
                     CREATE USER MAPPING FOR dbdiff_cat_reader SERVER warehouse OPTIONS (user 'reader');
                     CREATE FOREIGN TABLE remote_orders (id int OPTIONS (column_name 'order_id') NOT NULL, total numeric(10,2))
                         SERVER warehouse OPTIONS (schema_name 'sales', table_name 'orders');",
            $base . "CREATE SERVER warehouse VERSION '1' FOREIGN DATA WRAPPER postgres_fdw OPTIONS (host 'old.internal');
                     CREATE USER MAPPING FOR PUBLIC SERVER warehouse;
                     CREATE FOREIGN TABLE remote_orders (id int) SERVER warehouse;
                     CREATE SERVER retired FOREIGN DATA WRAPPER postgres_fdw;",
            $state
        );
    }
}
