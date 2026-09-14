<?php

use DBDiff\DB\Adapters\PostgresAdapter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Introspection of the object kinds DBDiff previously did not model, against a
 * live PostgreSQL server: standalone sequences, composite types, domains,
 * materialised views, and row level security with its policies.
 *
 * Two properties are asserted, and they fail in opposite directions.
 *
 * Round-trip: the DDL each getter emits, applied to an empty database, must
 * introspect back to exactly the same DDL. That catches a rendering that omits
 * or malforms part of an object — and it is version-aware for free, because the
 * server itself rejects or normalises what it is given. It found a real bug:
 * PostgreSQL 17 records a domain's NOT NULL as a named constraint as well as in
 * typnotnull, so reading every constraint row emitted the nullability twice.
 *
 * No over-reporting: PostgreSQL creates a sequence for every serial and
 * identity column, and a composite type for every table, view and materialised
 * view. Those belong to their owner and must not appear as objects in their own
 * right. This direction is invisible to a schema comparison — both sides would
 * carry the same spurious object — so it is asserted directly here.
 *
 * Skips automatically when:
 *   - pdo_pgsql is not loaded
 *   - DB_HOST_POSTGRES is not set
 */
class PostgresObjectKindsTest extends TestCase
{
    private string $db = 'dbdiff_object_kinds';
    private string $roundTripDb = 'dbdiff_object_kinds_rt';
    private ?PDO $adminDb = null;
    private $capsule;
    private $connection;
    private PostgresAdapter $adapter;
    private string $host;
    private int $port = 5432;
    private string $user = 'dbdiff';
    private string $pass = 'rootpass';

    private function dropDb(string $name): void
    {
        try {
            $this->adminDb->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
        } catch (PDOException $e) {
            $this->adminDb->exec("DROP DATABASE IF EXISTS $name");
        }
    }

    private function connect(string $database, string $name)
    {
        $capsule    = new Capsule;
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        });
        $capsule->setEventDispatcher($dispatcher);
        $capsule->addConnection([
            'driver'   => 'pgsql',
            'host'     => $this->host,
            'port'     => $this->port,
            'database' => $database,
            'username' => $this->user,
            'password' => $this->pass,
            'charset'  => 'utf8',
            'schema'   => 'public',
        ], $name);
        $this->capsule = $capsule;

        return $capsule->getConnection($name);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded — skipping object kind tests.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set — skipping object kind tests.');
        }
        $this->host = $host;

        $this->adminDb = new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname=diff1",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->dropDb($this->db);
        $this->dropDb($this->roundTripDb);
        $this->adminDb->exec("CREATE DATABASE {$this->db}");

        $this->connection = $this->connect($this->db, 'kinds');
        $this->adapter    = new PostgresAdapter();

        $this->connection->unprepared(<<<'SQL'
            CREATE TYPE o_addr AS (street text, city text);
            CREATE DOMAIN o_pos AS integer CONSTRAINT o_pos_positive CHECK (VALUE > 0);
            CREATE DOMAIN o_code AS text NOT NULL DEFAULT 'zz'
                CONSTRAINT o_code_len CHECK (length(VALUE) = 2);

            CREATE SEQUENCE o_seq START 100 INCREMENT 5
                MINVALUE 100 MAXVALUE 100000 CYCLE;
            CREATE SEQUENCE o_small AS smallint INCREMENT 2 MAXVALUE 300;

            -- Owners of implicit objects: a serial and an identity column each
            -- bring a sequence, and every relation brings a composite type.
            CREATE TABLE o_ser (id serial PRIMARY KEY, n text);
            CREATE TABLE o_ident (id bigint GENERATED ALWAYS AS IDENTITY, n text);

            CREATE TABLE o_mt (id int PRIMARY KEY, n int);
            CREATE MATERIALIZED VIEW o_mv AS SELECT id, n FROM o_mt WHERE n > 0;
            CREATE MATERIALIZED VIEW o_mv_empty AS SELECT id FROM o_mt WITH NO DATA;
            CREATE VIEW o_v AS SELECT id FROM o_mt;

            CREATE TABLE o_sec (id int PRIMARY KEY, owner text);
            ALTER TABLE o_sec ENABLE ROW LEVEL SECURITY;
            ALTER TABLE o_sec FORCE ROW LEVEL SECURITY;
            CREATE POLICY o_sec_read ON o_sec FOR SELECT USING (true);
            CREATE POLICY o_sec_write ON o_sec AS RESTRICTIVE FOR UPDATE TO dbdiff
                USING (owner = current_user) WITH CHECK (owner <> 'root');

            CREATE TABLE o_plain (id int);
SQL);
    }

    protected function tearDown(): void
    {
        if ($this->adminDb) {
            $this->capsule = null;
            $this->connection = null;
            $this->dropDb($this->db);
            $this->dropDb($this->roundTripDb);
        }
    }

    // ── No over-reporting ─────────────────────────────────────────────────

    public function testSequencesExcludeSerialAndIdentityOwnedOnes(): void
    {
        $sequences = $this->adapter->getSequences($this->connection);

        $this->assertSame(['o_seq', 'o_small'], array_keys($sequences));
        // o_ser_id_seq depends on its column with deptype 'a', o_ident's with
        // deptype 'i'. Both are the column's, not objects of their own.
        $this->assertArrayNotHasKey('o_ser_id_seq', $sequences);
        $this->assertArrayNotHasKey('o_ident_id_seq', $sequences);
    }

    public function testCompositeTypesExcludeRelationRowTypes(): void
    {
        $types = $this->adapter->getCompositeTypes($this->connection);

        $this->assertSame(['o_addr'], array_keys($types));
        foreach (['o_mt', 'o_sec', 'o_plain', 'o_v', 'o_mv', 'o_ser', 'o_ident'] as $relation) {
            $this->assertArrayNotHasKey(
                $relation,
                $types,
                "the row type of relation $relation must not be reported as a composite type"
            );
        }
    }

    public function testDomainsAreNotReportedAsCompositeTypes(): void
    {
        $types = $this->adapter->getCompositeTypes($this->connection);
        $this->assertArrayNotHasKey('o_pos', $types);
        $this->assertArrayNotHasKey('o_code', $types);
    }

    public function testMaterializedViewsAreSeparateFromViews(): void
    {
        $views    = $this->adapter->getViews($this->connection);
        $matviews = $this->adapter->getMaterializedViews($this->connection);

        $this->assertArrayHasKey('o_v', $views);
        $this->assertArrayNotHasKey('o_mv', $views, 'a matview must not appear as an ordinary view');
        $this->assertSame(['o_mv', 'o_mv_empty'], array_keys($matviews));
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    public function testSequenceRendersEveryOption(): void
    {
        $sequences = $this->adapter->getSequences($this->connection);

        // Every option is explicit: MINVALUE and MAXVALUE defaults follow the
        // type, so a bigint sequence recreated as an integer one would differ.
        $this->assertSame(
            'CREATE SEQUENCE "o_seq" AS bigint INCREMENT BY 5 MINVALUE 100 '
            . 'MAXVALUE 100000 START WITH 100 CACHE 1 CYCLE',
            $sequences['o_seq']
        );
        $this->assertStringContainsString('AS smallint', $sequences['o_small']);
        $this->assertStringContainsString('NO CYCLE', $sequences['o_small']);
    }

    public function testCompositeTypeRendersItsAttributes(): void
    {
        $types = $this->adapter->getCompositeTypes($this->connection);
        $this->assertSame('CREATE TYPE "o_addr" AS (street text, city text)', $types['o_addr']);
    }

    public function testDomainRendersConstraintsDefaultAndNullability(): void
    {
        $domains = $this->adapter->getDomains($this->connection);

        $this->assertSame(
            'CREATE DOMAIN "o_pos" AS integer CONSTRAINT o_pos_positive CHECK ((VALUE > 0))',
            $domains['o_pos']
        );
        // Named so that several CHECKs are not renamed on every recreation.
        $this->assertStringContainsString('CONSTRAINT o_code_len', $domains['o_code']);
        $this->assertStringContainsString('NOT NULL', $domains['o_code']);
        $this->assertStringContainsString("DEFAULT 'zz'", $domains['o_code']);
        // PG 17+ also records NOT NULL as a constraint row; it must not be
        // emitted twice.
        $this->assertSame(1, substr_count($domains['o_code'], 'NOT NULL'));
    }

    public function testMaterializedViewRendersPopulationState(): void
    {
        $matviews = $this->adapter->getMaterializedViews($this->connection);

        $this->assertStringStartsWith('CREATE MATERIALIZED VIEW "o_mv" AS', $matviews['o_mv']);
        $this->assertStringNotContainsString('WITH NO DATA', $matviews['o_mv']);
        // Without this a CREATE would run the query and populate the matview.
        $this->assertStringEndsWith('WITH NO DATA', $matviews['o_mv_empty']);
    }

    public function testPoliciesAreKeyedByTableAndName(): void
    {
        $policies = $this->adapter->getPolicies($this->connection);

        $this->assertSame(['o_sec.o_sec_read', 'o_sec.o_sec_write'], array_keys($policies));
        $this->assertSame('o_sec', $policies['o_sec.o_sec_read']['table']);
        $this->assertSame(
            'CREATE POLICY "o_sec_read" ON "o_sec" FOR SELECT USING (true)',
            $policies['o_sec.o_sec_read']['definition']
        );

        $restrictive = $policies['o_sec.o_sec_write']['definition'];
        $this->assertStringContainsString('AS RESTRICTIVE', $restrictive);
        $this->assertStringContainsString('FOR UPDATE', $restrictive);
        $this->assertStringContainsString('TO dbdiff', $restrictive);
        $this->assertStringContainsString('USING (', $restrictive);
        $this->assertStringContainsString('WITH CHECK (', $restrictive);
    }

    public function testRowSecurityFlagsAreReadPerTable(): void
    {
        $rls = $this->adapter->getRowSecurity($this->connection);

        $this->assertSame(['enabled' => true, 'forced' => true], $rls['o_sec']);
        $this->assertSame(['enabled' => false, 'forced' => false], $rls['o_plain']);
        // A matview is not a table and carries no RLS flags.
        $this->assertArrayNotHasKey('o_mv', $rls);
    }

    // ── Round-trip ────────────────────────────────────────────────────────

    /**
     * Apply the emitted DDL to an empty database and introspect it again. The
     * server is the judge: anything malformed fails to apply, and anything
     * omitted comes back different.
     */
    public function testEmittedDdlRoundTripsThroughTheServer(): void
    {
        $adapter = $this->adapter;
        $source  = $this->connection;

        $sequences = $adapter->getSequences($source);
        $types     = $adapter->getCompositeTypes($source);
        $domains   = $adapter->getDomains($source);
        $matviews  = $adapter->getMaterializedViews($source);
        $policies  = $adapter->getPolicies($source);

        $this->adminDb->exec("CREATE DATABASE {$this->roundTripDb}");
        $target = $this->connect($this->roundTripDb, 'kinds_rt');

        // The tables a matview and a policy need, then the objects themselves.
        $target->unprepared(<<<'SQL'
            CREATE TABLE o_mt (id int PRIMARY KEY, n int);
            CREATE TABLE o_sec (id int PRIMARY KEY, owner text);
            ALTER TABLE o_sec ENABLE ROW LEVEL SECURITY;
            ALTER TABLE o_sec FORCE ROW LEVEL SECURITY;
SQL);

        foreach ([$types, $domains, $sequences, $matviews] as $group) {
            foreach ($group as $ddl) {
                $target->unprepared($ddl . ';');
            }
        }
        foreach ($policies as $policy) {
            $target->unprepared($policy['definition'] . ';');
        }

        $this->assertSame($sequences, $adapter->getSequences($target));
        $this->assertSame($types, $adapter->getCompositeTypes($target));
        $this->assertSame($domains, $adapter->getDomains($target));
        $this->assertSame($matviews, $adapter->getMaterializedViews($target));
        $this->assertSame($policies, $adapter->getPolicies($target));
        $this->assertSame(
            $adapter->getRowSecurity($source)['o_sec'],
            $adapter->getRowSecurity($target)['o_sec']
        );
    }
}
