<?php

use DBDiff\DB\Adapters\PostgresAdapter;
use DBDiff\DB\Support\PgDumpRenderer;
use DBDiff\DB\Support\PostgresTableFacts;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The CREATE TABLE of a table only one side has, written from facts read for
 * all such tables at once (PostgresTableFacts).
 *
 * Prefetching exists only to make the same DDL cheaper, so what matters is
 * that it is the same DDL, on both renderers, for the tables the per-table
 * helpers exist for — partitions, INHERITS, owned sequences, unlogged tables,
 * replica identity — and that its query count does not grow with the number
 * of tables. And that nothing it read outlives the run.
 */
class NewTableFactsPostgresTest extends TestCase
{
    private string $database = 'dbdiff_new_table_facts';
    private Connection $connection;
    private PostgresAdapter $adapter;

    /** Ordinary tables, enough that a per-table query shows in the count. */
    private const PLAIN = 12;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: getenv('DB_HOST');
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }
        $port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $pass = getenv('DB_PASSWORD_POSTGRES') ?: 'rootpass';

        $admin = $this->connect($host, $port, $user, $pass, 'postgres');
        $admin->statement(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()",
            [$this->database]
        );
        $admin->statement("DROP DATABASE IF EXISTS {$this->database}");
        $admin->statement("CREATE DATABASE {$this->database}");
        $admin->disconnect();

        $this->connection = $this->connect($host, $port, $user, $pass, $this->database);
        $this->adapter = new PostgresAdapter();

        $plain = '';
        for ($i = 1; $i <= self::PLAIN; $i++) {
            $plain .= "CREATE TABLE t$i (id bigserial PRIMARY KEY, name text NOT NULL, email varchar(200) UNIQUE,
                          amount numeric(10,2) CHECK (amount >= 0), created timestamptz DEFAULT now());
                       CREATE INDEX t{$i}_name ON t$i (name);\n";
        }
        $this->connection->unprepared($plain . <<<'SQL'
            CREATE TABLE measurements (id int, at date NOT NULL, v int) PARTITION BY RANGE (at);
            CREATE TABLE measurements_2025 PARTITION OF measurements FOR VALUES FROM ('2025-01-01') TO ('2026-01-01');
            CREATE TABLE base_t (a int NOT NULL, b text);
            CREATE TABLE extra_t (c int);
            CREATE TABLE kid (d int) INHERITS (base_t, extra_t);
            CREATE SEQUENCE lone_seq;
            CREATE TABLE owns (n int DEFAULT nextval('lone_seq'), m int GENERATED ALWAYS AS IDENTITY);
            ALTER SEQUENCE lone_seq OWNED BY owns.n;
            CREATE UNLOGGED TABLE scratch (k int PRIMARY KEY, v text) WITH (fillfactor = 70);
            CREATE TABLE ident (k int NOT NULL, v text);
            CREATE UNIQUE INDEX ident_k ON ident (k);
            ALTER TABLE ident REPLICA IDENTITY USING INDEX ident_k;
            ALTER TABLE ident ALTER COLUMN v SET STORAGE EXTERNAL, ALTER COLUMN v SET STATISTICS 500;
            CREATE TABLE late_check (x int);
            ALTER TABLE late_check ADD CONSTRAINT x_pos CHECK (x > 0) NOT VALID;
            SQL);
        PgDumpRenderer::reset();
    }

    protected function tearDown(): void
    {
        PostgresTableFacts::clear();
        PgDumpRenderer::reset();
        putenv('DBDIFF_PG_DUMP_RENDERER=off');
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        $plain = array_map(fn(int $i) => "t$i", range(1, self::PLAIN));
        return array_merge($plain, ['measurements', 'measurements_2025', 'base_t', 'extra_t', 'kid', 'owns', 'scratch', 'ident', 'late_check']);
    }

    /** @return array<string, string> table => DDL */
    private function render(array $tables): array
    {
        $ddl = [];
        foreach ($tables as $table) {
            $ddl[$table] = $this->adapter->getCreateStatement($this->connection, $table);
        }
        return $ddl;
    }

    /** Queries this connection sends while $fn runs. */
    private function queries(callable $fn): int
    {
        $this->connection->enableQueryLog();
        $this->connection->flushQueryLog();
        $fn();
        $n = count($this->connection->getQueryLog());
        $this->connection->disableQueryLog();
        return $n;
    }

    public static function renderers(): array
    {
        return ['built-in renderer' => ['off'], 'pg_dump renderer' => ['']];
    }

    /** @dataProvider renderers */
    public function testPrefetchedTablesRenderExactlyAsBefore(string $renderer): void
    {
        putenv("DBDIFF_PG_DUMP_RENDERER=$renderer");
        if ($renderer === '' && PgDumpRenderer::unavailableReason($this->connection) !== null) {
            $this->markTestSkipped('pg_dump unavailable: ' . PgDumpRenderer::unavailableReason($this->connection));
        }
        $tables = $this->tables();

        $perTable = $this->render($tables);
        PostgresTableFacts::prefetchNewTables($this->adapter, $this->connection, $tables);
        $prefetched = $this->render($tables);

        $this->assertSame($perTable, $prefetched);
        // Not a comparison of two empty renders.
        $this->assertStringContainsString('INHERITS (base_t, extra_t)', $perTable['kid']);
        $this->assertStringContainsString('PARTITION BY RANGE', $perTable['measurements']);
        $this->assertStringContainsString('PARTITION OF', $perTable['measurements_2025']);
        $this->assertStringContainsString('UNLOGGED', $perTable['scratch']);
        if ($renderer === '') {
            $this->assertTrue(PgDumpRenderer::wasUsed(), 'pg_dump renderer did not run');
        }
    }

    /** @dataProvider renderers */
    public function testQueryCountDoesNotGrowWithTheNumberOfTables(string $renderer): void
    {
        putenv("DBDIFF_PG_DUMP_RENDERER=$renderer");
        if ($renderer === '' && PgDumpRenderer::unavailableReason($this->connection) !== null) {
            $this->markTestSkipped('pg_dump unavailable.');
        }
        $plain = array_map(fn(int $i) => "t$i", range(1, self::PLAIN));
        // The dump is taken once per connection; take it before counting.
        $this->render(['t1']);

        $count = function (array $tables): int {
            return $this->queries(function () use ($tables) {
                PostgresTableFacts::prefetchNewTables($this->adapter, $this->connection, $tables);
                $this->render($tables);
            });
        };
        $two = $count(array_slice($plain, 0, 2));
        $all = $count($plain);
        $one = $count(['t1']);
        PostgresTableFacts::clear();
        $unbatched = $this->queries(fn() => $this->render(['t1']));

        $this->assertSame($two, $all, 'Query count grew with the number of tables');
        $this->assertLessThanOrEqual($unbatched, $one, 'One new table costs more queries than without prefetching');
        $this->assertGreaterThan($all, $this->queries(fn() => $this->render($plain)), 'Without prefetching the count should grow');
    }

    public function testNothingReadOutlivesTheRun(): void
    {
        putenv('DBDIFF_PG_DUMP_RENDERER=off');
        PostgresTableFacts::prefetchNewTables($this->adapter, $this->connection, ['t1']);
        $this->assertStringNotContainsString('"extra"', $this->adapter->getCreateStatement($this->connection, 't1'));

        $this->connection->statement('ALTER TABLE t1 ADD COLUMN extra int');
        PostgresTableFacts::clear();

        $this->assertStringContainsString('"extra"', $this->adapter->getCreateStatement($this->connection, 't1'));
    }

    public function testATableNotNamedIsReadAsBefore(): void
    {
        putenv('DBDIFF_PG_DUMP_RENDERER=off');
        PostgresTableFacts::prefetchNewTables($this->adapter, $this->connection, ['t1']);

        $this->connection->statement('ALTER TABLE t2 ADD COLUMN extra int');

        $this->assertStringContainsString('"extra"', $this->adapter->getCreateStatement($this->connection, 't2'));
    }

    private function connect(string $host, string $port, string $user, string $pass, string $db): Connection
    {
        $capsule = new Capsule();
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, static function ($event): void {
            $event->statement->setFetchMode(\PDO::FETCH_ASSOC);
        });
        $capsule->setEventDispatcher($dispatcher);
        $capsule->addConnection([
            'driver' => 'pgsql', 'host' => $host, 'port' => $port,
            'database' => $db, 'username' => $user, 'password' => $pass,
            'charset' => 'utf8', 'schema' => 'public',
        ], $db . '_' . spl_object_id($this));

        return $capsule->getConnection($db . '_' . spl_object_id($this));
    }
}
