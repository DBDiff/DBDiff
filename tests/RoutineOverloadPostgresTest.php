<?php

use DBDiff\DB\Adapters\PostgresAdapter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Live-server regression tests for issue #187.
 *
 * getRoutines() keyed its map on proname, so overloaded functions overwrote
 * each other: N-1 overloads were invisible to the diff, and which one survived
 * depended on row order — which differs between databases. Two identical
 * schemas reported drift, and the migration dropped every overload.
 *
 * The same applied to triggers, whose names are unique per table, not per
 * schema.
 *
 * Skips automatically without pdo_pgsql or DB_HOST_POSTGRES.
 */
class RoutineOverloadPostgresTest extends TestCase
{
    private string $db = 'dbdiff_overloads';
    private ?PDO $adminDb = null;
    private $capsule;
    private $connection;
    private PostgresAdapter $adapter;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }

        $this->adminDb = new PDO(
            "pgsql:host=$host;port=5432;dbname=diff1",
            'dbdiff',
            'rootpass',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->dropScratchDb();
        $this->adminDb->exec("CREATE DATABASE {$this->db}");

        $capsule = new Capsule;
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        });
        $capsule->setEventDispatcher($dispatcher);
        $capsule->addConnection([
            'driver' => 'pgsql', 'host' => $host, 'port' => 5432,
            'database' => $this->db, 'username' => 'dbdiff', 'password' => 'rootpass',
            'charset' => 'utf8', 'schema' => 'public',
        ], 'ovl');

        $this->capsule    = $capsule;
        $this->connection = $capsule->getConnection('ovl');
        $this->adapter    = new PostgresAdapter();

        $this->connection->unprepared(<<<'SQL'
            CREATE FUNCTION cosine_distance(a int,    b int)    RETURNS int    LANGUAGE sql AS $$ SELECT 1 $$;
            CREATE FUNCTION cosine_distance(a text,   b text)   RETURNS text   LANGUAGE sql AS $$ SELECT 'x' $$;
            CREATE FUNCTION cosine_distance(a bigint, b bigint) RETURNS bigint LANGUAGE sql AS $$ SELECT 2::bigint $$;
            CREATE FUNCTION solo() RETURNS int LANGUAGE sql AS $$ SELECT 7 $$;

            CREATE TABLE t1 (id int);
            CREATE TABLE t2 (id int);
            CREATE FUNCTION trig_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$;
            CREATE TRIGGER shared_trig BEFORE INSERT ON t1 FOR EACH ROW EXECUTE FUNCTION trig_fn();
            CREATE TRIGGER shared_trig BEFORE INSERT ON t2 FOR EACH ROW EXECUTE FUNCTION trig_fn();
        SQL);
    }

    protected function tearDown(): void
    {
        if ($this->adminDb === null) {
            return;
        }
        if ($this->connection !== null) {
            $this->connection->disconnect();
            $this->connection = null;
        }
        $this->capsule = null;
        $this->dropScratchDb();
        $this->adminDb = null;
    }

    private function dropScratchDb(): void
    {
        try {
            $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db} WITH (FORCE)");
        } catch (PDOException $e) {
            $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db}");
        }
    }

    public function testEveryOverloadIsRetained(): void
    {
        // Keyed on proname, three overloads collapsed to one entry.
        $routines = $this->adapter->getRoutines($this->connection);
        $overloads = array_filter(
            array_keys($routines),
            fn($k) => str_starts_with($k, 'cosine_distance')
        );
        $this->assertCount(3, $overloads);
    }

    public function testKeysAreSignaturesNotBareNames(): void
    {
        $routines = $this->adapter->getRoutines($this->connection);
        $this->assertArrayHasKey('cosine_distance(integer,integer)', $routines);
        $this->assertArrayHasKey('cosine_distance(text,text)', $routines);
        $this->assertArrayHasKey('cosine_distance(bigint,bigint)', $routines);
        $this->assertArrayNotHasKey('cosine_distance', $routines);
    }

    public function testEachOverloadKeepsItsOwnDefinition(): void
    {
        $routines = $this->adapter->getRoutines($this->connection);
        $this->assertStringContainsString('bigint', $routines['cosine_distance(bigint,bigint)']);
        $this->assertStringContainsString('text', $routines['cosine_distance(text,text)']);
    }

    public function testNonOverloadedRoutineStillAppears(): void
    {
        $routines = $this->adapter->getRoutines($this->connection);
        $this->assertArrayHasKey('solo()', $routines);
    }

    public function testRoutineOrderIsDeterministic(): void
    {
        // ORDER BY proname left overload ties unordered, which is what made the
        // surviving definition differ between databases.
        $first  = array_keys($this->adapter->getRoutines($this->connection));
        $second = array_keys($this->adapter->getRoutines($this->connection));
        $this->assertSame($first, $second);
        $sorted = $first;
        sort($sorted);
        $this->assertSame($sorted, $first);
    }

    public function testSameNamedTriggersOnDifferentTablesAreBothKept(): void
    {
        // tgname is unique per table, not per schema — keyed on the name alone,
        // one of these was silently lost.
        $triggers = $this->adapter->getTriggers($this->connection);
        $this->assertArrayHasKey('t1.shared_trig', $triggers);
        $this->assertArrayHasKey('t2.shared_trig', $triggers);
        $this->assertSame('shared_trig', $triggers['t1.shared_trig']['name']);
        $this->assertSame('t2', $triggers['t2.shared_trig']['table']);
    }
}
