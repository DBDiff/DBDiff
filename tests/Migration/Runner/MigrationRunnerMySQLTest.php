<?php

use DBDiff\Migration\Config\MigrationConfig;
use DBDiff\Migration\Runner\MigrationFile;
use DBDiff\Migration\Runner\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * `migration:up` against a real MySQL server (the MySQL and Dolt jobs set
 * DB_HOST): a routine or trigger body is one statement however many
 * semicolons it holds, written as DBDiff writes it or for the mysql client
 * with DELIMITER lines.
 */
class MigrationRunnerMySQLTest extends TestCase
{
    private PDO $admin;
    private string $db;
    private string $dir;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if (!$host || !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('DB_HOST not set.');
        }
        $this->admin = new PDO("mysql:host={$host};port=" . (getenv('DB_PORT') ?: '3306'),
            'root', getenv('DB_ROOT_PASSWORD') ?: 'rootpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db = 'dbdiff_runner_' . substr(uniqid(), -8);
        $this->admin->exec("CREATE DATABASE `{$this->db}`");
        $this->dir = sys_get_temp_dir() . '/dbdiff_runner_mysql_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->admin->exec("DROP DATABASE IF EXISTS `{$this->db}`");
            array_map('unlink', glob("{$this->dir}/*"));
            rmdir($this->dir);
        }
    }

    /** @return array<int, array> */
    private function up(string $sql): array
    {
        $file = MigrationFile::scaffold($this->dir, 'bodies', '20261101000000');
        file_put_contents($file->upPath, $sql);
        return (new MigrationRunner(new MigrationConfig(null, [
            'driver' => 'mysql', 'host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT') ?: '3306',
            'name' => $this->db, 'user' => 'root', 'password' => getenv('DB_ROOT_PASSWORD') ?: 'rootpass',
            'migrations_dir' => $this->dir,
        ])))->up();
    }

    /** One column of one table in the test database. */
    private function column(string $column, string $table): array
    {
        return $this->admin->query("SELECT $column FROM `{$this->db}`.$table")->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * MySQL commits implicitly at any DDL statement, so the runner's own
     * commit found no transaction and reported an applied migration as failed.
     */
    public function testPlainDdlAppliesAndRollsBack(): void
    {
        $file = MigrationFile::scaffold($this->dir, 'plain', '20261101000000');
        file_put_contents($file->upPath, "CREATE TABLE a (id int);\nINSERT INTO a VALUES (1);");
        file_put_contents($file->downPath, 'DROP TABLE a;');
        $runner = new MigrationRunner(new MigrationConfig(null, [
            'driver' => 'mysql', 'host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT') ?: '3306',
            'name' => $this->db, 'user' => 'root', 'password' => getenv('DB_ROOT_PASSWORD') ?: 'rootpass',
            'migrations_dir' => $this->dir,
        ]));

        $up = $runner->up();
        $this->assertSame('applied', $up[0]['status'], (string) ($up[0]['error'] ?? ''));
        $this->assertSame(['1'], array_map('strval', $this->column('id', 'a')));

        $down = $runner->down();
        $this->assertSame('rolled_back', $down[0]['status'], (string) ($down[0]['error'] ?? ''));
        $this->assertSame([], $this->admin->query("SHOW TABLES FROM `{$this->db}` LIKE 'a'")->fetchAll());
    }

    public function testRoutineAndTriggerBodiesAsDBDiffWritesThem(): void
    {
        $results = $this->up(<<<'SQL'
            CREATE TABLE notes (id int, body varchar(100));
            CREATE TRIGGER notes_stamp BEFORE INSERT ON notes FOR EACH ROW
            BEGIN
              IF NEW.body IS NULL THEN SET NEW.body = 'empty; filled'; END IF;
              SET NEW.id = COALESCE(NEW.id, 0);
            END;
            CREATE PROCEDURE fill(IN n INT)
            BEGIN
              DECLARE i INT DEFAULT 0;
              WHILE i < n DO
                SET i = i + 1;
                IF i = 1 THEN INSERT INTO notes VALUES (1, 'one; first'); ELSE INSERT INTO notes (id) VALUES (n); END IF;
              END WHILE;
            END;
            CALL fill(2);
            SQL);

        $this->assertSame('applied', $results[0]['status'], (string) ($results[0]['error'] ?? ''));
        $this->assertSame(['one; first', 'empty; filled'], $this->column('body', 'notes ORDER BY id'));
    }

    public function testAFileWrittenForTheMysqlClientWithDelimiterLines(): void
    {
        $results = $this->up(<<<'SQL'
            CREATE TABLE notes (id int, body varchar(100));

            DELIMITER $$

            CREATE PROCEDURE note(IN n INT)
            BEGIN
              INSERT INTO notes VALUES (n, CONCAT('n=', n, ';'));
            END$$

            DELIMITER ;

            CALL note(1);
            SQL);

        $this->assertSame('applied', $results[0]['status'], (string) ($results[0]['error'] ?? ''));
        $this->assertSame(['n=1;'], $this->column('body', 'notes'));
    }
}
