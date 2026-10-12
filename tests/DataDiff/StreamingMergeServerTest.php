<?php

/**
 * The hash pass reads both tables a batch at a time, so a data diff's memory
 * does not grow with the size of the table.
 *
 * PDO's pgsql and mysql drivers buffer a whole result before the first
 * fetch(), so the "streaming" merge used to hold every row's key and hash at
 * once: about 90 bytes a row a side, enough to exceed the default 1G memory
 * limit at a few million rows. PostgreSQL now reads a large table through a
 * cursor and MySQL unbuffered; both must give exactly the diff they did.
 *
 * Runs against the PostgreSQL and MySQL servers the integration jobs provide
 * (DB_HOST_POSTGRES, DB_HOST), and skips a driver whose server is not set.
 */

use DBDiff\DB\Data\StreamingMergeDiff;
use DBDiff\Diff\DeleteData;
use DBDiff\Diff\InsertData;
use DBDiff\Diff\UpdateData;
use DBDiff\Params\DefaultParams;
use DBDiff\Params\ParamsFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class StreamingMergeServerTest extends TestCase
{
    /** Spans three of RowStream's batches, so batch boundaries are crossed. */
    private const ROWS = 12_345;

    private const COLUMNS = ['id', 'name', 'amount', 'note'];

    private ?PDO $admin = null;
    private string $driver = '';
    private array $created = [];
    private array $config = [];
    private Capsule $capsule;

    protected function setUp(): void
    {
        ParamsFactory::set(new DefaultParams);
        $this->capsule = new Capsule;
        // As DBManager does: DBDiff's connections fetch arrays.
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, fn($e) => $e->statement->setFetchMode(PDO::FETCH_ASSOC));
        $this->capsule->setEventDispatcher($dispatcher);
    }

    protected function tearDown(): void
    {
        foreach ($this->capsule->getDatabaseManager()->getConnections() as $conn) {
            $conn->disconnect();
        }
        foreach ($this->created as $db) {
            $this->driver === 'pgsql'
                ? $this->admin->exec("DROP DATABASE IF EXISTS $db WITH (FORCE)")
                : $this->admin->exec("DROP DATABASE IF EXISTS `$db`");
        }
        $this->created = [];
        $this->admin = null;
        ParamsFactory::reset();
    }

    public static function drivers(): array
    {
        return ['pgsql' => ['pgsql'], 'mysql' => ['mysql']];
    }

    /** @dataProvider drivers */
    public function testABatchedReadGivesTheSameDiffAsTheChangesMade(string $driver): void
    {
        $this->server($driver);
        [$src, $tgt] = $this->pair('rows', self::ROWS);
        // Changes either side of each batch boundary, and at both ends.
        $this->exec($tgt, "UPDATE t SET name = 'changed' WHERE id IN (1, 4999, 5000, 5001, 10000, 12345)");
        $this->exec($tgt, "UPDATE t SET note = NULL WHERE id IN (2500, 7501)");
        $this->exec($tgt, "DELETE FROM t WHERE id IN (3, 5002, 9999)");
        $this->exec($tgt, "INSERT INTO t (id, name, amount, note) VALUES (20000, 'extra', 1, 'x'), (5000500, 'extra', 2, NULL)");

        [$inserts, $deletes, $updates] = $this->classify($this->merge($src, $tgt));

        $this->assertSame([3, 5002, 9999], $inserts, 'rows only the source has');
        $this->assertSame([20000, 5000500], $deletes, 'rows only the target has');
        $this->assertSame([1, 2500, 4999, 5000, 5001, 7501, 10000, 12345], $updates, 'rows that differ');
    }

    /**
     * PostgreSQL reads a table it expects to be small with a buffered query,
     * which is faster, and anything else through a cursor. Both must agree.
     */
    public function testPostgresBufferedAndCursorReadsAgree(): void
    {
        $this->server('pgsql');
        [$src, $tgt] = $this->pair('modes', self::ROWS);
        $this->exec($tgt, "UPDATE t SET amount = amount + 1 WHERE id % 1000 = 0");
        $this->exec($tgt, "DELETE FROM t WHERE id % 1500 = 7");

        // Never analysed: no row estimate, so a cursor.
        $cursor = $this->classify($this->merge($src, $tgt));

        $this->exec($src, 'ANALYZE t');
        $this->exec($tgt, 'ANALYZE t');
        $buffered = $this->classify($this->merge($src, $tgt));

        $this->assertSame($cursor, $buffered);
        $this->assertCount(12, $cursor[2], 'updated rows');
    }

    /** @dataProvider drivers */
    public function testTheConnectionsAreLeftAsTheyWereFound(string $driver): void
    {
        $this->server($driver);
        [$src, $tgt] = $this->pair('state', self::ROWS);
        $buffered = $driver === 'mysql' ? $src->getPdo()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;

        $this->merge($src, $tgt);

        foreach (['source' => $src, 'target' => $tgt] as $side => $conn) {
            $this->assertFalse($conn->getPdo()->inTransaction(), "$side left in a transaction");
            // The connection still answers: no cursor or unbuffered result left open on it.
            $this->assertSame(self::ROWS, (int) array_values((array) $conn->selectOne('SELECT count(*) AS n FROM t'))[0]);
            if ($driver === 'mysql') {
                $this->assertSame($buffered, $conn->getPdo()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY), "$side buffering");
            }
        }
    }

    /**
     * The point of all this. Measured as the process's peak resident memory,
     * because libpq and libmysqlclient hold a buffered result outside PHP's
     * own allocator, where memory_get_peak_usage() never sees it.
     *
     * @dataProvider drivers
     */
    public function testMemoryDoesNotGrowWithTheTable(string $driver): void
    {
        if (!is_writable('/proc/self/clear_refs')) {
            $this->markTestSkipped('needs Linux /proc to reset and read peak memory');
        }
        $this->server($driver);
        $this->skipOnDolt();
        $rows = 400_000;
        [$src, $tgt] = $this->pair('memory', $rows);

        file_put_contents('/proc/self/clear_refs', '5'); // reset the peak to now
        $before = self::rssKb('VmRSS');
        $diff = $this->merge($src, $tgt);
        $grew = (self::rssKb('VmHWM') - $before) / 1024;

        $this->assertSame([], $diff, 'identical tables');
        // Buffered, both sides' results came to about 70 MB here.
        $this->assertLessThan(25, $grew, sprintf('peak memory grew %.1f MB over %d rows a side', $grew, $rows));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function merge(Connection $src, Connection $tgt): array
    {
        return (new StreamingMergeDiff($src, $tgt, $this->driver))->getDiff('t', ['id'], self::COLUMNS, self::COLUMNS);
    }

    /** @return array{0: int[], 1: int[], 2: int[]} inserted, deleted and updated ids, sorted */
    private function classify(array $diffs): array
    {
        $ids = function (string $class) use ($diffs): array {
            $ids = array_map(fn($d) => (int) $d->diff['keys']['id'], array_filter($diffs, fn($d) => $d instanceof $class));
            sort($ids);
            return array_values($ids);
        };
        return [$ids(InsertData::class), $ids(DeleteData::class), $ids(UpdateData::class)];
    }

    private function server(string $driver): void
    {
        $this->driver = $driver;
        if ($driver === 'pgsql') {
            $host = getenv('DB_HOST_POSTGRES');
            if (!$host || !extension_loaded('pdo_pgsql')) {
                $this->markTestSkipped('DB_HOST_POSTGRES not set.');
            }
            $this->config = [
                'driver' => 'pgsql', 'host' => $host, 'port' => getenv('DB_PORT_POSTGRES') ?: '5432',
                'username' => getenv('DB_USER_POSTGRES') ?: 'dbdiff', 'password' => getenv('DB_PASS_POSTGRES') ?: 'rootpass',
            ];
            $this->admin = new PDO("pgsql:host={$host};port={$this->config['port']};dbname=diff1",
                $this->config['username'], $this->config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            return;
        }
        $host = getenv('DB_HOST');
        if (!$host || !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('DB_HOST not set.');
        }
        $this->config = [
            'driver' => 'mysql', 'host' => $host, 'port' => getenv('DB_PORT') ?: '3306',
            'username' => 'root', 'password' => getenv('DB_ROOT_PASSWORD') ?: 'rootpass',
        ];
        $this->admin = new PDO("mysql:host={$host};port={$this->config['port']}",
            'root', $this->config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }


    /** Two databases holding the same `$rows`-row table t. @return Connection[] */
    private function pair(string $case, int $rows): array
    {
        $conns = [];
        foreach (['s', 't'] as $side) {
            $db = "dbdiff_sm_{$case}_{$side}";
            if ($this->driver === 'pgsql') {
                $this->admin->exec("DROP DATABASE IF EXISTS $db WITH (FORCE)");
                $this->admin->exec("CREATE DATABASE $db");
            } else {
                $this->admin->exec("DROP DATABASE IF EXISTS `$db`");
                $this->admin->exec("CREATE DATABASE `$db`");
            }
            $this->created[] = $db;
            $this->capsule->addConnection($this->config + ['database' => $db], $db);
            $conn = $this->capsule->getConnection($db);
            if ($this->driver === 'pgsql') {
                // No autovacuum: the row estimate stays absent until a test analyses.
                $conn->unprepared("CREATE TABLE t (id int PRIMARY KEY, name text, amount numeric(10,2), note text) WITH (autovacuum_enabled = false);
                    INSERT INTO t SELECT g, 'name-' || g, g / 7.0, CASE WHEN g % 3 = 0 THEN NULL ELSE 'n' || g END FROM generate_series(1, $rows) g;");
            } else {
                $conn->unprepared("CREATE TABLE t (id int PRIMARY KEY, name varchar(64), amount decimal(10,2), note varchar(64));
                    INSERT INTO t SELECT n, CONCAT('name-', n), n / 7.0, IF(n % 3 = 0, NULL, CONCAT('n', n)) FROM " . self::mysqlSeries($rows) . ";");
            }
            $conns[] = $conn;
        }
        return $conns;
    }

    /**
     * `n` from 1 to `$rows`, as a derived table. A cross join of digits
     * rather than a recursive CTE: Dolt ignores cte_max_recursion_depth and
     * stops a recursive CTE long before the rows the memory tests need.
     */
    private static function mysqlSeries(int $rows): string
    {
        $digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
            . ' UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $places = max(1, (int) ceil(log10($rows + 1)));
        $terms = $from = [];
        for ($i = 0; $i < $places; $i++) {
            $terms[] = "d$i.d * " . (10 ** $i);
            $from[]  = "$digits d$i";
        }
        return '(SELECT ' . implode(' + ', $terms) . ' + 1 AS n FROM ' . implode(' CROSS JOIN ', $from)
            . ' HAVING n <= ' . $rows . ') series';
    }

    /** Dolt speaks MySQL's protocol; a 400k-row load there is slow and proves nothing new. */
    private function skipOnDolt(): void
    {
        try {
            $this->admin->query('SELECT dolt_version()');
        } catch (\PDOException) {
            return;
        }
        $this->markTestSkipped('memory is measured against MySQL; Dolt shares the code path');
    }

    private function exec(Connection $conn, string $sql): void
    {
        $conn->unprepared($sql);
    }

    private static function rssKb(string $field): int
    {
        preg_match("/^$field:\s+(\d+)/m", file_get_contents('/proc/self/status'), $m);
        return (int) $m[1];
    }
}
