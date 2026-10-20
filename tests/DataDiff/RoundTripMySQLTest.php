<?php

/**
 * A schema and data migration, UP and then DOWN, applied on MySQL.
 *
 * Two that failed on the server, though each statement was right on its own:
 *
 * - MySQL keeps an index for every foreign key and refuses to drop the last
 *   one it can use. Replacing a foreign key's index with a wider one dropped
 *   the old before adding the new: "Cannot drop index 'ix_customer': needed
 *   in a foreign key constraint". The DOWN did the same the other way.
 * - A row's UPDATE named every column that differed, including one only the
 *   other side has: the DOWN set `phone` after dropping it, "Unknown column".
 *
 * Verdicts are DBDiff's own: after the UP the target is identical to the
 * source, after the DOWN to its untouched copy. Runs in the MySQL jobs
 * (DB_HOST); skips without a server.
 */

use PHPUnit\Framework\TestCase;

class RoundTripMySQLTest extends TestCase
{
    private ?PDO $admin = null;
    private string $host = '';
    private string $port = '';
    private string $password = '';
    private array $created = [];

    private const BEFORE = "
        CREATE TABLE customers (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, email varchar(190) NOT NULL, name varchar(100) NOT NULL);
        CREATE TABLE orders (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, customer_id int unsigned NOT NULL, total decimal(12,2) NOT NULL,
          KEY ix_customer (customer_id), CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id));
        INSERT INTO customers (email, name) VALUES ('a@x.io', 'Ann'), ('b@x.io', 'Bo');
        INSERT INTO orders (customer_id, total) VALUES (1, 10), (2, 20);";

    private const AFTER = "
        ALTER TABLE customers ADD COLUMN phone varchar(30) NULL;
        UPDATE customers SET name = 'Ann Smith' WHERE id = 1;
        ALTER TABLE orders ADD INDEX ix_customer_total (customer_id, total);
        ALTER TABLE orders DROP INDEX ix_customer;";

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if (!$host || !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('DB_HOST not set.');
        }
        $this->host = $host;
        $this->port = getenv('DB_PORT') ?: '3306';
        $this->password = getenv('DB_ROOT_PASSWORD') ?: 'rootpass';
        $this->admin = new PDO("mysql:host={$this->host};port={$this->port};charset=utf8mb4", 'root', $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $db) {
            $this->admin?->exec("DROP DATABASE IF EXISTS `$db`");
        }
    }

    public function testAForeignKeysIndexReplacedAndAColumnAddedRoundTrip(): void
    {
        $source = $this->db('rt_source', self::BEFORE . self::AFTER);
        $target = $this->db('rt_target', self::BEFORE);
        $untouched = $this->db('rt_untouched', self::BEFORE);

        [$up, $down] = $this->migration($source, $target);
        $this->assertLessThan(strpos($up, 'DROP INDEX `ix_customer`'), strpos($up, 'ix_customer_total'), $up);

        $this->apply($target, $up);
        $this->assertSame([null, null], $this->migration($source, $target), 'the target still differs after the UP');

        $this->apply($target, $down);
        $this->assertSame([null, null], $this->migration($untouched, $target), 'the target differs from its old self after the DOWN');
    }

    private function db(string $name, string $sql): string
    {
        $db = "dbdiff_$name";
        $this->admin->exec("DROP DATABASE IF EXISTS `$db`");
        $this->admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        $this->created[] = $db;
        $this->apply($db, $sql);
        return $db;
    }

    /** The CLI's schema and data migration, [UP, DOWN], or [null, null] when the two are identical. */
    private function migration(string $source, string $target): array
    {
        $out = tempnam(sys_get_temp_dir(), 'dbdiff_rt_') . '.sql';
        $url = fn($db) => "mysql://root:" . rawurlencode($this->password) . "@{$this->host}:{$this->port}/$db";
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/dbdiff.php')
            . ' diff --type=all --include=both --nocomments --allow-destructive'
            . ' --server1-url=' . escapeshellarg($url($source)) . ' --server2-url=' . escapeshellarg($url($target))
            . ' --output=' . escapeshellarg($out) . ' 2>&1', $log, $status);
        $this->assertSame(0, $status, implode("\n", $log));
        if (!file_exists($out)) {
            return [null, null];
        }
        $parts = preg_split('/^-- =+ DOWN =+$/m', file_get_contents($out), 2);
        unlink($out);
        return [$parts[0], $parts[1] ?? ''];
    }

    private function apply(string $db, string $sql): void
    {
        $pdo = new PDO("mysql:host={$this->host};port={$this->port};dbname=$db;charset=utf8mb4", 'root', $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (preg_split('/;\s*$/m', preg_replace('/^--.*$/m', '', $sql)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
