<?php

use DBDiff\Migration\Command\DiffCommand;
use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Runner\MigrationFile;
use DBDiff\Migration\Runner\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `diff --delimiter` (#287): a migration with a multi-statement procedure,
 * trigger and function, against real MySQL (the MySQL jobs set DB_HOST),
 * applied by the mysql client — what the option is for — and by DBDiff's
 * own runner, which reads the DELIMITER lines too.
 */
class DelimiterOutputMySQLTest extends TestCase
{
    private PDO $admin;
    private string $host;
    private string $port;
    private string $pass;
    /** @var string[] */
    private array $dbs = [];
    private string $dir;

    private const SOURCE = <<<'SQL'
        CREATE TABLE notes (id int PRIMARY KEY, body varchar(100));
        CREATE TRIGGER notes_fill BEFORE INSERT ON notes FOR EACH ROW
        BEGIN
          IF NEW.body IS NULL THEN SET NEW.body = 'empty; filled'; END IF;
        END;
        CREATE PROCEDURE add_notes(IN n INT)
        BEGIN
          DECLARE i INT DEFAULT 0;
          WHILE i < n DO
            SET i = i + 1;
            INSERT INTO notes VALUES (i, CASE WHEN i = 1 THEN 'first; one' ELSE NULL END);
          END WHILE;
        END;
        CREATE FUNCTION label(n INT) RETURNS varchar(20) DETERMINISTIC
        BEGIN
          RETURN CONCAT('n=', n, ';');
        END;
        SQL;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if (!$host || !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('DB_HOST not set.');
        }
        $this->host = $host;
        $this->port = getenv('DB_PORT') ?: '3306';
        $this->pass = getenv('DB_ROOT_PASSWORD') ?: 'rootpass';
        $this->admin = new PDO("mysql:host={$this->host};port={$this->port}", 'root', $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->dir = sys_get_temp_dir() . '/dbdiff_delim_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->dbs as $db) {
            $this->admin->exec("DROP DATABASE IF EXISTS `$db`");
        }
        if (isset($this->dir)) {
            array_map('unlink', glob("{$this->dir}/*"));
            rmdir($this->dir);
        }
    }

    private function database(string $role, string $sql): string
    {
        $db = 'dbdiff_delim_' . $role . '_' . substr(uniqid(), -6);
        $this->dbs[] = $db;
        $this->admin->exec("CREATE DATABASE `$db`");
        $pdo = new PDO("mysql:host={$this->host};port={$this->port};dbname=$db", 'root', $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (\DBDiff\Migration\Runner\SqlStatementSplitter::split($sql, 'mysql') as $statement) {
            $pdo->exec($statement);
        }
        return $db;
    }

    private function diff(string $source, string $target, array $extra): array
    {
        $app = new Application;
        $app->add(new DiffCommand);
        $tester = new CommandTester($app->find('diff'));
        $out = "{$this->dir}/" . uniqid() . '.sql';
        $url = fn (string $db) => "mysql://root:{$this->pass}@{$this->host}:{$this->port}/$db";
        $code = $tester->execute(['--server1-url' => $url($source), '--server2-url' => $url($target),
            '--output' => $out, '--nocomments' => true] + $extra);
        return [$code, is_file($out) ? file_get_contents($out) : '', $tester->getDisplay()];
    }

    private function assertWorks(string $db): void
    {
        $pdo = new PDO("mysql:host={$this->host};port={$this->port};dbname=$db", 'root', $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CALL add_notes(2)');
        $this->assertSame(['first; one', 'empty; filled'],
            $pdo->query('SELECT body FROM notes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame('n=7;', $pdo->query('SELECT label(7)')->fetchColumn());
    }

    public function testTheMigrationRunsInTheMysqlClientAndThroughDBDiff(): void
    {
        $source = $this->database('src', self::SOURCE);
        $empty  = 'CREATE TABLE notes (id int PRIMARY KEY, body varchar(100));';

        [$code, $sql, $display] = $this->diff($source, $this->database('tgt', $empty), ['--delimiter' => null]);
        $this->assertSame(0, $code, $display);
        $this->assertStringContainsString("DELIMITER \$\$\nCREATE PROCEDURE", $sql);
        $this->assertSame(3, substr_count($sql, "DELIMITER \$\$\n"), $sql);
        $this->assertSame(3, substr_count($sql, "DELIMITER ;\n"), $sql);

        // The mysql client: what #287 asked for. CI's runners have one.
        exec('command -v mysql', $found, $missing);
        if ($missing) {
            if (getenv('CI')) {
                $this->fail('No mysql client on the CI runner: the check #287 needs did not run.');
            }
        } else {
            $client = $this->database('client', $empty);
            $cmd = sprintf('mysql --protocol=TCP -h %s -P %s -uroot -p%s %s < %s 2>&1',
                escapeshellarg($this->host), escapeshellarg($this->port), escapeshellarg($this->pass),
                escapeshellarg($client), escapeshellarg($file = "{$this->dir}/client.sql"));
            file_put_contents($file, $sql);
            exec($cmd, $lines, $status);
            $this->assertSame(0, $status, implode("\n", $lines));
            $this->assertWorks($client);
        }

        // DBDiff's own runner, from the same file.
        $runnerDb = $this->database('runner', $empty);
        $migrations = "{$this->dir}/migrations";
        mkdir($migrations);
        $file = MigrationFile::scaffold($migrations, 'routines', '20261101000000');
        file_put_contents($file->upPath, $sql);
        $results = (new MigrationRunner(new MigrationConfig(null, [
            'driver' => 'mysql', 'host' => $this->host, 'port' => $this->port, 'name' => $runnerDb,
            'user' => 'root', 'password' => $this->pass, 'migrations_dir' => $migrations,
        ])))->up();
        array_map('unlink', glob("$migrations/*"));
        rmdir($migrations);
        $this->assertSame('applied', $results[0]['status'], (string) ($results[0]['error'] ?? ''));
        $this->assertWorks($runnerDb);
    }

    public function testWithoutTheOptionStatementsEndInSemicolons(): void
    {
        $source = $this->database('src', self::SOURCE);
        [$code, $sql] = $this->diff($source, $this->database('tgt', 'CREATE TABLE notes (id int PRIMARY KEY, body varchar(100));'), []);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('DELIMITER', $sql);
    }

    public function testATokenWithASpaceIsRefused(): void
    {
        $app = new Application;
        $app->add(new DiffCommand);
        $tester = new CommandTester($app->find('diff'));
        $code = $tester->execute(['input' => 'a.b:c.d', '--delimiter' => '$ $']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('--delimiter needs a token', $tester->getDisplay());
    }
}
