<?php

use DBDiff\DB\Adapters\PostgresAdapter;
use DBDiff\DB\Support\PostgresObjectKinds;
use DBDiff\DB\Support\PostgresSchemaHelper;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Objects an extension owns are not user objects (issue #221).
 *
 * `CREATE EXTENSION pg_trgm` installs 31 functions and a type into `public`.
 * Read as ordinary user objects, an extension present on one side only produced
 * a `CREATE OR REPLACE FUNCTION` for each member, C-language ones included —
 * which a non-superuser cannot create, so the apply died with
 * `permission denied for language c` and, being one transaction, rolled back
 * the entire migration. As a superuser it "succeeded" and left the members
 * owned by nobody, after which `CREATE EXTENSION pg_trgm` itself failed with
 * `function "set_limit" already exists with same argument types`.
 *
 * The tests come in pairs on purpose: each asserts that extension members are
 * excluded *and* that an ordinary object of the same kind is still returned.
 * An exclusion that swallowed real objects would be a worse bug than the one it
 * fixed, and would look identical from the outside — a clean diff.
 *
 * Skips automatically when pdo_pgsql is missing, DB_HOST_POSTGRES is unset, or
 * the server has no pg_trgm available to install.
 */
class ExtensionMembersPostgresTest extends TestCase
{
    private string $db = 'dbdiff_extension_members';
    private ?PDO $adminDb = null;
    private $connection;
    private PostgresAdapter $adapter;

    private function dropScratchDb(): void
    {
        try {
            $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db} WITH (FORCE)");
        } catch (PDOException $e) {
            $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db}");
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }

        $port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $pass = getenv('DB_PASS_POSTGRES') ?: 'rootpass';

        $this->adminDb = new PDO(
            "pgsql:host=$host;port=$port;dbname=diff1",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->dropScratchDb();
        $this->adminDb->exec("CREATE DATABASE {$this->db}");

        $capsule    = new Capsule;
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        });
        $capsule->setEventDispatcher($dispatcher);
        $capsule->addConnection([
            'driver'   => 'pgsql',
            'host'     => $host,
            'port'     => $port,
            'database' => $this->db,
            'username' => $user,
            'password' => $pass,
            'charset'  => 'utf8',
            'schema'   => 'public',
        ], 'ext');

        $this->connection = $capsule->getConnection('ext');
        $this->adapter    = new PostgresAdapter();

        try {
            $this->connection->unprepared('CREATE EXTENSION pg_trgm');
        } catch (\Throwable $e) {
            $this->markTestSkipped('pg_trgm is not available on this server.');
        }

        // One ordinary object of each kind an extension can also own, so the
        // exclusion can be shown to be selective rather than broad.
        $this->connection->unprepared(<<<'SQL'
            CREATE TABLE mine_table (id integer PRIMARY KEY, note text);
            CREATE VIEW mine_view AS SELECT id FROM mine_table;
            CREATE SEQUENCE mine_seq;
            CREATE TYPE mine_enum AS ENUM ('a', 'b');
            CREATE TYPE mine_composite AS (x integer, y text);
            CREATE DOMAIN mine_domain AS text;
            CREATE MATERIALIZED VIEW mine_matview AS SELECT id FROM mine_table;
            CREATE FUNCTION mine_fn(a integer) RETURNS integer
              LANGUAGE sql IMMUTABLE AS $$ SELECT a + 1 $$;
        SQL);
    }

    protected function tearDown(): void
    {
        if ($this->adminDb) {
            $this->dropScratchDb();
            $this->adminDb = null;
        }
    }

    // ── Functions: the reported case ──────────────────────────────────────────

    public function testExcludesExtensionFunctions(): void
    {
        $routines = $this->adapter->getRoutines($this->connection);
        $names    = implode("\n", array_keys($routines));

        // pg_trgm's own, including the C ones a non-superuser cannot create.
        $this->assertStringNotContainsString('similarity(', $names);
        $this->assertStringNotContainsString('set_limit(', $names);
        $this->assertStringNotContainsString('show_trgm(', $names);

        foreach ($routines as $definition) {
            $this->assertStringNotContainsString(
                '$libdir/pg_trgm',
                $definition,
                'no extension member should reach the diff'
            );
        }
    }

    public function testStillReturnsOrdinaryFunctions(): void
    {
        $names = implode("\n", array_keys($this->adapter->getRoutines($this->connection)));
        $this->assertStringContainsString('mine_fn(integer)', $names);
    }

    public function testNoLanguageCFunctionSurvives(): void
    {
        // The failure mode in one assertion: `permission denied for language c`
        // came from emitting these at all.
        foreach ($this->adapter->getRoutines($this->connection) as $name => $definition) {
            $this->assertStringNotContainsString(
                'LANGUAGE c',
                $definition,
                "$name is a C function, which only an extension should own"
            );
        }
    }

    // ── The other kinds an extension can own ──────────────────────────────────

    public function testExcludesExtensionTypesButKeepsOwn(): void
    {
        $enums = $this->adapter->getEnums($this->connection);
        $this->assertArrayHasKey('mine_enum', $enums);

        $composites = PostgresObjectKinds::compositeTypes($this->connection);
        $this->assertArrayHasKey('mine_composite', $composites);

        $domains = PostgresObjectKinds::domains($this->connection);
        $this->assertArrayHasKey('mine_domain', $domains);
    }

    public function testKeepsOwnRelationsOfEveryKind(): void
    {
        $this->assertContains('mine_table', $this->adapter->getTables($this->connection));
        $this->assertArrayHasKey('mine_view', $this->adapter->getViews($this->connection));
        $this->assertArrayHasKey('mine_seq', PostgresObjectKinds::sequences($this->connection));
        $this->assertArrayHasKey(
            'mine_matview',
            PostgresObjectKinds::materializedViews($this->connection)
        );
    }

    /**
     * A table an extension owns must not be diffed either — PostGIS ships
     * `spatial_ref_sys` this way. pg_trgm owns no relations, so this asserts the
     * predicate against the catalog directly rather than against pg_trgm.
     */
    public function testTheRelationExclusionMatchesWhatTheCatalogSays(): void
    {
        $sql = 'SELECT count(*)::int AS n FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = \'public\' AND c.relkind IN (\'r\', \'p\')
                   AND NOT ('
             . PostgresSchemaHelper::notExtensionMember('pg_class', 'c.oid') . ')';

        $owned = $this->connection->select($sql)[0]['n'];
        $tables = $this->adapter->getTables($this->connection);

        // Whatever the server says is extension-owned, none of it is returned.
        $this->assertSame(
            count($tables),
            $this->connection->select(
                'SELECT count(*)::int AS n FROM pg_class c
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = \'public\' AND c.relkind IN (\'r\', \'p\')
                    AND ' . PostgresSchemaHelper::notExtensionMember('pg_class', 'c.oid')
            )[0]['n'],
            "getTables must return exactly the non-extension tables ($owned extension-owned here)"
        );
    }

    // ── The whole diff, which is what the report was about ────────────────────

    public function testADatabaseWithAnExtensionDiffsCleanlyAgainstOneWithout(): void
    {
        // The reported scenario: extension on one side only. Every object the
        // adapter reports for this database must be one this test created, so a
        // diff against a database holding the same user objects is empty.
        $reported = array_merge(
            array_keys($this->adapter->getRoutines($this->connection)),
            array_keys($this->adapter->getViews($this->connection)),
            array_keys($this->adapter->getEnums($this->connection)),
            $this->adapter->getTables($this->connection)
        );

        foreach ($reported as $name) {
            $this->assertStringStartsWith(
                'mine_',
                $name,
                "$name comes from pg_trgm and should not be reported"
            );
        }
    }
}
