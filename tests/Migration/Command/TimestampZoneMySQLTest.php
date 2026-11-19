<?php

use DBDiff\Migration\Command\DiffCommand;
use DBDiff\Migration\Runner\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * MySQL TIMESTAMP values read on a server in one time zone and applied in
 * another (the MySQL jobs set DB_HOST). DBDiff read them in the server's zone
 * and wrote that wall-clock text, so a session in another zone stored a
 * different instant. See MySqlTimestamps.
 */
class TimestampZoneMySQLTest extends TestCase
{
    private PDO $admin;
    private string $host;
    private string $port;
    private string $pass;
    private string $zone;
    /** @var string[] */
    private array $dbs = [];
    private string $dir;

    private const TABLE = 'CREATE TABLE m (id int PRIMARY KEY, ts timestamp(6) NULL, dt datetime(6), note varchar(10));';
    private const ROWS = "INSERT INTO m VALUES (1, '2026-04-03 10:00:00', '2026-04-03 10:00:00', 'a'),
        (2, '2038-01-19 03:14:07.999999', '9999-12-31 23:59:59.999999', 'b'), (3, NULL, NULL, 'c');";

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if (!$host || !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('DB_HOST not set.');
        }
        if (getenv('DBDIFF_ENGINE') === 'dolt') {
            // As the data tests in DBDiffComprehensiveTest: Dolt refuses the
            // data diff of a table with a date or time column
            // ("Incorrect datetime value: ''"), with or without this change.
            $this->markTestSkipped('Dolt cannot diff the rows of a table with a date or time column.');
        }
        $this->host = $host;
        $this->port = getenv('DB_PORT') ?: '3306';
        $this->pass = getenv('DB_ROOT_PASSWORD') ?: 'rootpass';
        $this->admin = new PDO("mysql:host={$this->host};port={$this->port}", 'root', $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->zone = (string) $this->admin->query('SELECT @@GLOBAL.time_zone')->fetchColumn();
        // The server DBDiff reads is far from UTC; the session applying the SQL is not.
        $this->admin->exec("SET GLOBAL time_zone = '+13:45'");
        $this->dir = sys_get_temp_dir() . '/dbdiff_tz_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (!isset($this->admin)) {
            return;
        }
        $this->admin->exec('SET GLOBAL time_zone = ' . $this->admin->quote($this->zone));
        foreach ($this->dbs as $db) {
            $this->admin->exec("DROP DATABASE IF EXISTS `$db`");
        }
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
    }

    /** A session in UTC, whatever the server's zone. */
    private function utc(string $db): PDO
    {
        $pdo = new PDO("mysql:host={$this->host};port={$this->port};dbname=$db", 'root', $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    private function database(string $role, string $sql): string
    {
        $db = 'dbdiff_tz_' . $role . '_' . substr(uniqid(), -6);
        $this->dbs[] = $db;
        $this->admin->exec("CREATE DATABASE `$db`");
        $pdo = $this->utc($db);
        foreach (SqlStatementSplitter::split($sql, 'mysql') as $statement) {
            $pdo->exec($statement);
        }
        return $db;
    }

    private function diff(string $source, string $target, string $type): string
    {
        $app = new Application;
        $app->add(new DiffCommand);
        $tester = new CommandTester($app->find('diff'));
        $out = "{$this->dir}/" . uniqid() . '.sql';
        $url = fn (string $db) => "mysql://root:{$this->pass}@{$this->host}:{$this->port}/$db";
        $code = $tester->execute(['--server1-url' => $url($source), '--server2-url' => $url($target),
            '--output' => $out, '--nocomments' => true, '--type' => $type, '--include' => 'up']);
        $this->assertSame(0, $code, $tester->getDisplay());
        return is_file($out) ? file_get_contents($out) : '';
    }

    /** The rows as instants: what a TIMESTAMP means, whatever zone prints it. */
    private function contents(string $db): array
    {
        return $this->utc($db)->query('SELECT id, UNIX_TIMESTAMP(ts), dt, note FROM m ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    }

    public function testTimestampsKeepTheirInstantInAnotherZone(): void
    {
        $source = $this->database('src', self::TABLE . self::ROWS);
        $target = $this->database('tgt', self::TABLE);

        $sql = $this->diff($source, $target, 'data');
        $this->assertStringContainsString("SET @DBDIFF_TIME_ZONE = @@SESSION.time_zone;\nSET time_zone = '+00:00';\nINSERT", $sql);

        $pdo = $this->utc($target);
        $pdo->exec("SET time_zone = '-08:00'");
        foreach (SqlStatementSplitter::split($sql, 'mysql') as $statement) {
            $pdo->exec($statement);
        }
        $this->assertSame('-08:00', $pdo->query('SELECT @@SESSION.time_zone')->fetchColumn(), 'the session gets its zone back');
        $this->assertSame($this->contents($source), $this->contents($target), $sql);
    }

    /** The same instants are identical, however the server prints them. */
    public function testTheSameInstantsAreIdentical(): void
    {
        $source = $this->database('ss', self::TABLE . self::ROWS);
        $target = $this->database('st', self::TABLE . self::ROWS);

        $this->assertSame('', $this->diff($source, $target, 'data'));
    }

    /** Without a TIMESTAMP column in what it writes, a migration is as it was. */
    public function testNoTimestampNoZoneChange(): void
    {
        $table = 'CREATE TABLE n (id int PRIMARY KEY, dt datetime);';
        $source = $this->database('ns', $table . "INSERT INTO n VALUES (1, '2026-04-03 10:00:00');");

        $sql = $this->diff($source, $this->database('nt', $table), 'all');
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringNotContainsString('time_zone', $sql);
    }
}
