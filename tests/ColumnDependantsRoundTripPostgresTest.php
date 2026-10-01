<?php

use PHPUnit\Framework\TestCase;

/**
 * Migrations that must run, and must leave nothing behind, against a live
 * server (issues #226 and #229, and what reviewing them turned up).
 *
 * Every case builds a source and a target, generates the migration with the
 * real CLI, applies its UP to the target and diffs again — which must report
 * nothing — then applies its DOWN and compares the target with an untouched
 * copy of itself, which must also report nothing. A migration that is valid
 * SQL but that PostgreSQL refuses, or that quietly loses something the diff
 * does not compare, fails here rather than in someone's deploy.
 *
 * Grants, options and comments on views are checked directly as well: the
 * diff does not compare grants or comments, so "identical" alone would not
 * notice a recreated view that lost them.
 *
 * Skips automatically when pdo_pgsql is missing or DB_HOST_POSTGRES is unset.
 */
class ColumnDependantsRoundTripPostgresTest extends TestCase
{
    private ?PDO $admin = null;
    private string $host;
    private string $port;
    private string $user;
    private string $pass;
    private string $prefix = 'dbdiff_rt';
    private array $created = [];
    /** security_invoker where the server has it (15+), security_barrier before. */
    private string $viewOption;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }
        $this->host = $host;
        $this->port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $this->user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $this->pass = getenv('DB_PASS_POSTGRES') ?: 'rootpass';

        $this->admin = new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname=diff1",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $version = (int) $this->admin->query('SHOW server_version_num')->fetchColumn();
        $this->viewOption = $version >= 150000 ? 'security_invoker' : 'security_barrier';

        // Roles are cluster-wide, so they are created once and left in place.
        foreach (['dbdiff_rt_reader', 'dbdiff_rt_anon'] as $role) {
            $this->admin->exec(
                "DO \$\$ BEGIN CREATE ROLE $role NOLOGIN; EXCEPTION WHEN duplicate_object THEN NULL; END \$\$"
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $db) {
            $this->dropDb($db);
        }
        $this->admin = null;
    }

    private function dropDb(string $db): void
    {
        $this->admin->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
              WHERE datname = '$db' AND pid <> pg_backend_pid()"
        );
        $this->admin->exec("DROP DATABASE IF EXISTS $db");
    }

    private function db(string $name, string $sql): string
    {
        $db = "{$this->prefix}_$name";
        $this->dropDb($db);
        $this->admin->exec("CREATE DATABASE $db");
        $this->created[] = $db;
        if ($sql !== '') {
            $this->connect($db)->exec($sql);
        }
        return $db;
    }

    private function connect(string $db): PDO
    {
        return new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname=$db",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function url(string $db): string
    {
        return "postgres://{$this->user}:{$this->pass}@{$this->host}:{$this->port}/$db";
    }

    /**
     * The migration from `$source` to `$target` as [up, down], or null when
     * the CLI reports the two identical.
     *
     * Run as a separate process: the CLI keeps process-wide state (the
     * dialect, the parsed parameters), and each case needs a clean one.
     */
    private function diff(string $source, string $target): ?array
    {
        $out = tempnam(sys_get_temp_dir(), 'dbdiff_rt_') . '.sql';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/dbdiff.php')
            . ' diff --type=schema --nocomments --include=both --allow-destructive'
            . ' --server1-url=' . escapeshellarg($this->url($source))
            . ' --server2-url=' . escapeshellarg($this->url($target))
            . ' --output=' . escapeshellarg($out) . ' 2>&1';
        exec($cmd, $lines, $status);
        $log = implode("\n", $lines);

        $this->assertSame(0, $status, "dbdiff failed:\n$log");
        if (!file_exists($out)) {
            $this->assertStringContainsString('identical', $log);
            return null;
        }

        $sql = file_get_contents($out);
        unlink($out);
        $parts = preg_split('/^-- =+ DOWN =+$/m', $sql, 2);
        $up = $parts[0];
        $down = $parts[1] ?? '';
        return self::hasStatements($up) || self::hasStatements($down) ? [$up, $down] : null;
    }

    private static function hasStatements(string $sql): bool
    {
        return trim(preg_replace('/^--.*$/m', '', $sql)) !== '';
    }

    /**
     * View and materialised view metadata the diff does not itself compare:
     * options, grants and comments.
     */
    private function viewMetadata(string $db): array
    {
        return $this->connect($db)->query(
            "SELECT n.nspname || '.' || c.relname AS name,
                    coalesce(c.reloptions::text, '') AS options,
                    coalesce(c.relacl::text, '') AS acl,
                    coalesce(obj_description(c.oid, 'pg_class'), '') AS comment,
                    (SELECT count(*) FROM pg_trigger t WHERE t.tgrelid = c.oid AND NOT t.tgisinternal) AS triggers,
                    (SELECT count(*) FROM pg_index i WHERE i.indrelid = c.oid) AS indexes
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('v', 'm') AND n.nspname NOT IN ('pg_catalog', 'information_schema')
             ORDER BY 1"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate, apply UP, rediff; apply DOWN, compare with the original.
     *
     * @return string the UP, for further assertions
     */
    private function assertRoundTrip(string $case, string $sourceSql, string $targetSql): string
    {
        $source   = $this->db("{$case}_s", $sourceSql);
        $target   = $this->db("{$case}_t", $targetSql);
        $original = $this->db("{$case}_o", $targetSql);
        $sourceMeta = $this->viewMetadata($source);
        $targetMeta = $this->viewMetadata($target);

        $migration = $this->diff($source, $target);
        $this->assertNotNull($migration, "$case: expected a difference");
        [$up, $down] = $migration;

        $this->connect($target)->exec($up);
        $this->assertNull($this->diff($source, $target), "$case: UP left a difference behind:\n$up");
        $this->assertEquals($sourceMeta, $this->viewMetadata($target), "$case: UP lost view metadata:\n$up");

        $this->connect($target)->exec($down);
        $this->assertNull($this->diff($original, $target), "$case: DOWN did not restore the target:\n$down");
        $this->assertEquals($targetMeta, $this->viewMetadata($target), "$case: DOWN lost view metadata:\n$down");

        return $up;
    }

    /** Generate and apply the UP only, then diff again: nothing may remain. */
    private function assertUpConverges(string $case, string $sourceSql, string $targetSql): void
    {
        $source = $this->db("{$case}_s", $sourceSql);
        $target = $this->db("{$case}_t", $targetSql);

        $migration = $this->diff($source, $target);
        $this->assertNotNull($migration, "$case: expected a difference");
        $this->connect($target)->exec($migration[0]);
        $this->assertNull($this->diff($source, $target), "$case: UP left a difference behind:\n{$migration[0]}");
    }

    private const T_OLD = 'CREATE TABLE t (id int PRIMARY KEY, a numeric(10,2));';
    private const T_NEW = 'CREATE TABLE t (id int PRIMARY KEY, a numeric(12,2));';

    public function testARetypedColumnPutsItsViewsBackWhole(): void
    {
        $views = "
            ALTER DEFAULT PRIVILEGES GRANT ALL ON TABLES TO dbdiff_rt_anon;
            CREATE VIEW v WITH ({$this->viewOption} = true) AS SELECT id, a FROM t;
            REVOKE ALL ON v FROM dbdiff_rt_anon;
            GRANT SELECT ON v TO dbdiff_rt_reader;
            COMMENT ON VIEW v IS 'it''s documented';
            COMMENT ON COLUMN v.a IS 'amount';
            CREATE FUNCTION v_ins() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
            CREATE TRIGGER v_ins INSTEAD OF INSERT ON v FOR EACH ROW EXECUTE FUNCTION v_ins();
            CREATE MATERIALIZED VIEW mv AS SELECT sum(a) AS total FROM t;
            CREATE INDEX mv_total ON mv (total);
            CREATE VIEW v2 AS SELECT * FROM v;
        ";
        $up = $this->assertRoundTrip('whole', self::T_OLD . $views, self::T_NEW . $views);

        // Stated as well as measured: the regressions these guard were silent.
        $this->assertStringContainsString("WITH ({$this->viewOption}=true)", $up);
        $this->assertStringContainsString('REVOKE ALL ON "v" FROM dbdiff_rt_anon', $up);
    }

    public function testADefaultChangeLeavesTheViewsAlone(): void
    {
        $view = "CREATE VIEW v WITH ({$this->viewOption} = true) AS SELECT id, a FROM t;";
        $up = $this->assertRoundTrip(
            'default',
            'CREATE TABLE t (id int PRIMARY KEY, a numeric(10,2) DEFAULT 5);' . $view,
            'CREATE TABLE t (id int PRIMARY KEY, a numeric(10,2) DEFAULT 1);' . $view
        );

        $this->assertStringNotContainsString('DROP VIEW', $up);
        $this->assertStringNotContainsString(' TYPE ', $up);
    }

    public function testAViewTheSourceDroppedStaysDropped(): void
    {
        $this->assertRoundTrip('dropped', self::T_OLD, self::T_NEW . 'CREATE VIEW v AS SELECT id, a FROM t;');
    }

    public function testAViewTheSourceChangedTakesTheNewDefinition(): void
    {
        $this->assertRoundTrip(
            'changed',
            self::T_OLD . 'CREATE VIEW v AS SELECT id, a, 1 AS extra FROM t;',
            self::T_NEW . 'CREATE VIEW v AS SELECT id, a FROM t;'
        );
    }

    public function testAViewInAnotherSchemaIsFound(): void
    {
        $view = 'CREATE SCHEMA api; CREATE VIEW api.v AS SELECT id, a FROM public.t;';
        $this->assertRoundTrip('schema', self::T_OLD . $view, self::T_NEW . $view);
    }

    public function testPoliciesAndTriggerConditionsStandAside(): void
    {
        $deps = "
            ALTER TABLE t ENABLE ROW LEVEL SECURITY;
            CREATE POLICY positive ON t USING (a > 0);
            CREATE FUNCTION t_big() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RETURN NEW; END \$\$;
            CREATE TRIGGER t_big BEFORE UPDATE ON t FOR EACH ROW WHEN (NEW.a > 100) EXECUTE FUNCTION t_big();
        ";
        $this->assertRoundTrip('policy', self::T_OLD . $deps, self::T_NEW . $deps);
    }

    public function testTextToUuidWithAPolicyThatChangesWithIt(): void
    {
        // The commonest Supabase retype: the policy's expression is only
        // valid against the column's type on its own side.
        $this->assertRoundTrip(
            'uuid',
            "CREATE TABLE o (id int PRIMARY KEY, user_id uuid);
             INSERT INTO o VALUES (1, '6f1c4b8e-7d1c-4b4a-9a55-6c1f3c1d2e3f');
             ALTER TABLE o ENABLE ROW LEVEL SECURITY;
             CREATE POLICY own ON o USING (user_id = '6f1c4b8e-7d1c-4b4a-9a55-6c1f3c1d2e3f'::uuid);",
            "CREATE TABLE o (id int PRIMARY KEY, user_id text);
             INSERT INTO o VALUES (1, '6f1c4b8e-7d1c-4b4a-9a55-6c1f3c1d2e3f');
             ALTER TABLE o ENABLE ROW LEVEL SECURITY;
             CREATE POLICY own ON o USING (user_id = '6f1c4b8e-7d1c-4b4a-9a55-6c1f3c1d2e3f'::text);"
        );
    }

    public function testLoggedChangesFollowForeignKeys(): void
    {
        $this->assertRoundTrip(
            'logged',
            'CREATE TABLE p (id int PRIMARY KEY);
             CREATE TABLE c (id int PRIMARY KEY, pid int REFERENCES p);
             CREATE TABLE a (cid int REFERENCES c);',
            'CREATE UNLOGGED TABLE p (id int PRIMARY KEY);
             CREATE UNLOGGED TABLE c (id int PRIMARY KEY, pid int REFERENCES p);
             CREATE UNLOGGED TABLE a (cid int REFERENCES c);'
        );
    }

    public function testStorageParametersInAnotherOrderOrSpellingAreIdentical(): void
    {
        $source = $this->db('opts_s', 'CREATE TABLE t (id int);
            ALTER TABLE t SET (fillfactor = 70); ALTER TABLE t SET (autovacuum_enabled = off);');
        $target = $this->db('opts_t', 'CREATE TABLE t (id int);
            ALTER TABLE t SET (autovacuum_enabled = false); ALTER TABLE t SET (fillfactor = 70);');

        $this->assertNull($this->diff($source, $target));
    }

    public function testAStorageParameterChangeRoundTrips(): void
    {
        $this->assertRoundTrip(
            'opts',
            'CREATE TABLE t (id int) WITH (fillfactor = 70, autovacuum_enabled = off);',
            'CREATE TABLE t (id int) WITH (fillfactor = 90);'
        );
    }

    public function testAChangedPolicyCallingANewFunctionIsCreatedAfterIt(): void
    {
        // Routines are created after column changes in the UP, so the new
        // policy cannot go back at the column change — its own diff does it.
        $this->assertRoundTrip(
            'policyfn',
            "CREATE TABLE o (id int PRIMARY KEY, owner uuid);
             CREATE FUNCTION current_owner() RETURNS uuid LANGUAGE sql STABLE
               AS \$\$ SELECT '6f1c4b8e-7d1c-4b4a-9a55-6c1f3c1d2e3f'::uuid \$\$;
             ALTER TABLE o ENABLE ROW LEVEL SECURITY;
             CREATE POLICY own ON o USING (owner = current_owner());",
            "CREATE TABLE o (id int PRIMARY KEY, owner text);
             ALTER TABLE o ENABLE ROW LEVEL SECURITY;
             CREATE POLICY own ON o USING (owner = current_user);"
        );
    }

    public function testAChangedViewCallingANewFunctionIsCreatedAfterIt(): void
    {
        // UP only. Its DOWN is a known limitation: the column change reverts
        // the view after the DOWN has tried to drop the routine the source's
        // view still calls, and dropping the view any earlier is not safe
        // while other objects may depend on it.
        $this->assertUpConverges(
            'viewfn',
            self::T_OLD . "CREATE FUNCTION doubled(numeric) RETURNS numeric LANGUAGE sql IMMUTABLE
               AS \$\$ SELECT \$1 * 2 \$\$;
             CREATE VIEW v AS SELECT id, doubled(a) AS a2 FROM t;",
            self::T_NEW . 'CREATE VIEW v AS SELECT id, a FROM t;'
        );
    }

    // ── Issue #232: partitions and inheritance ───────────────────────────────

    private const PARTITIONS = 'PARTITION BY RANGE (id);
        CREATE TABLE p1 PARTITION OF p FOR VALUES FROM (0) TO (100);
        CREATE TABLE p2 PARTITION OF p FOR VALUES FROM (100) TO (200);';

    public function testAPartitionedColumnIsRetypedOnceThroughItsParent(): void
    {
        $up = $this->assertRoundTrip(
            'part',
            'CREATE TABLE p (id int, a numeric(12,2)) ' . self::PARTITIONS,
            'CREATE TABLE p (id int, a numeric(10,2)) ' . self::PARTITIONS
        );

        // Once, on the parent: a partition's own is refused.
        $this->assertSame(1, substr_count($up, 'TYPE numeric(12,2)'));
    }

    public function testAViewOnAPartitionStandsAsideForTheParentsChange(): void
    {
        $views = 'CREATE VIEW pv AS SELECT a FROM p; CREATE VIEW p1v AS SELECT a FROM p1;';
        $this->assertRoundTrip(
            'partview',
            'CREATE TABLE p (id int, a numeric(12,2)) ' . self::PARTITIONS . $views,
            'CREATE TABLE p (id int, a numeric(10,2)) ' . self::PARTITIONS . $views
        );
    }

    public function testAnInheritedColumnFollowsItsParent(): void
    {
        $this->assertRoundTrip(
            'inherit',
            'CREATE TABLE b (id int, a numeric(12,2)); CREATE TABLE c (x int) INHERITS (b);',
            'CREATE TABLE b (id int, a numeric(10,2)); CREATE TABLE c (x int) INHERITS (b);'
        );
    }

    public function testAPartitionLocalDefaultIsStillApplied(): void
    {
        $this->assertRoundTrip(
            'partdefault',
            'CREATE TABLE p (id int, a numeric(12,2) DEFAULT 1) ' . self::PARTITIONS
                . 'ALTER TABLE p1 ALTER COLUMN a SET DEFAULT 5;',
            'CREATE TABLE p (id int, a numeric(10,2) DEFAULT 1) ' . self::PARTITIONS
        );
    }

    // ── Issue #233: stored generated columns ─────────────────────────────────

    private const GENERATED_EXTRAS = "CREATE INDEX t_g ON t (g);
        ALTER TABLE t ADD CONSTRAINT g_nonneg CHECK (g >= 0);
        COMMENT ON COLUMN t.g IS 'doubled';
        GRANT SELECT (g) ON t TO dbdiff_rt_reader;
        CREATE VIEW gv AS SELECT id, g FROM t;";

    public function testRetypingAColumnAGeneratedColumnReads(): void
    {
        $up = $this->assertRoundTrip(
            'gen',
            'CREATE TABLE t (id int, a numeric(12,2), g numeric GENERATED ALWAYS AS (a * 2) STORED NOT NULL);'
                . self::GENERATED_EXTRAS,
            'CREATE TABLE t (id int, a numeric(10,2), g numeric GENERATED ALWAYS AS (a * 2) STORED NOT NULL);
             INSERT INTO t (id, a) VALUES (1, 3.5);' . self::GENERATED_EXTRAS
        );

        $this->assertStringContainsString('DROP COLUMN "g"', $up);
        $this->assertStringNotContainsString('CASCADE', $up, 'nothing may disappear silently');
    }

    public function testTheIndexesCommentAndGrantsOfAGeneratedColumnComeBack(): void
    {
        $source = $this->db('genmeta_s', 'CREATE TABLE t (id int, a numeric(12,2), g numeric GENERATED ALWAYS AS (a * 2) STORED);'
            . self::GENERATED_EXTRAS);
        $target = $this->db('genmeta_t', 'CREATE TABLE t (id int, a numeric(10,2), g numeric GENERATED ALWAYS AS (a * 2) STORED);'
            . self::GENERATED_EXTRAS);

        $this->connect($target)->exec($this->diff($source, $target)[0]);

        $meta = $this->connect($target)->query(
            "SELECT (SELECT count(*) FROM pg_indexes WHERE indexname = 't_g') AS idx,
                    (SELECT count(*) FROM pg_constraint WHERE conname = 'g_nonneg') AS con,
                    col_description('t'::regclass, (SELECT attnum FROM pg_attribute WHERE attrelid = 't'::regclass AND attname = 'g')) AS comment,
                    has_column_privilege('dbdiff_rt_reader', 't', 'g', 'SELECT') AS grant_kept"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(['idx' => 1, 'con' => 1, 'comment' => 'doubled', 'grant_kept' => true], $meta);
    }

    public function testAGeneratedColumnsOwnExpressionChangeIsApplied(): void
    {
        // Before, only a stray DROP NOT NULL was emitted and the new
        // expression never reached the target.
        $this->assertRoundTrip(
            'genexpr',
            'CREATE TABLE t (id int, a numeric(10,2), g numeric GENERATED ALWAYS AS (a * 3) STORED);'
                . self::GENERATED_EXTRAS,
            'CREATE TABLE t (id int, a numeric(10,2), g numeric GENERATED ALWAYS AS (a * 2) STORED);
             INSERT INTO t (id, a) VALUES (1, 2);' . self::GENERATED_EXTRAS
        );
    }

    public function testAGeneratedColumnChangingWithTheColumnItReads(): void
    {
        // Before, its ADD was emitted ahead of its DROP.
        $this->assertRoundTrip(
            'genboth',
            'CREATE TABLE t (id int, a numeric(12,2), g numeric GENERATED ALWAYS AS (a * 3) STORED);',
            'CREATE TABLE t (id int, a numeric(10,2), g numeric GENERATED ALWAYS AS (a * 2) STORED);'
        );
    }

    public function testAGeneratedColumnTheSourceRemovesOrAdds(): void
    {
        $this->assertRoundTrip(
            'genremoved',
            'CREATE TABLE t (id int, a numeric(12,2));',
            'CREATE TABLE t (id int, a numeric(10,2), g numeric GENERATED ALWAYS AS (a * 2) STORED);'
        );
        $this->assertRoundTrip(
            'genadded',
            'CREATE TABLE t (id int, a numeric(12,2), g numeric GENERATED ALWAYS AS (a * 2) STORED);',
            'CREATE TABLE t (id int, a numeric(10,2));'
        );
    }

    public function testAGeneratedColumnReadingTwoRetypedColumns(): void
    {
        // The regression suite's gtest27. In one bracket per column, x came
        // back after a was retyped and before b was, and b's change was
        // refused. Changes linked by a generated column are one bracket.
        $up = $this->assertRoundTrip(
            'gentwo',
            'CREATE TABLE gtest27 (a bigint, b bigint, x bigint GENERATED ALWAYS AS ((a + b) * 2) STORED);',
            'CREATE TABLE gtest27 (a int, b int, x int GENERATED ALWAYS AS ((a + b) * 2) STORED);
             INSERT INTO gtest27 (a, b) VALUES (3, 4);'
        );

        $this->assertSame(1, substr_count($up, 'DROP COLUMN "x"'));
        $this->assertSame(1, substr_count($up, 'ADD COLUMN "x"'));
        $this->assertLessThan(strpos($up, 'ADD COLUMN "x"'), strpos($up, 'ALTER COLUMN "b" TYPE'));
    }

    public function testGeneratedColumnsChainingThreeRetypedColumns(): void
    {
        $views = 'CREATE VIEW v AS SELECT x, y FROM t;';
        $this->assertRoundTrip(
            'genchain',
            'CREATE TABLE t (a bigint, b bigint, c bigint, x bigint GENERATED ALWAYS AS (a + b) STORED,
                             y bigint GENERATED ALWAYS AS (b + c) STORED);' . $views,
            'CREATE TABLE t (a int, b int, c int, x bigint GENERATED ALWAYS AS (a + b) STORED,
                             y bigint GENERATED ALWAYS AS (b + c) STORED);' . $views
        );
    }
}
