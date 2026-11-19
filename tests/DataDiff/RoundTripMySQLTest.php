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

    /**
     * Names holding a backtick: the data diff wrapped each in backticks
     * without doubling the ones inside it, which broke its query.
     */
    public function testNamesHoldingBackticksRoundTrip(): void
    {
        $this->skipOnDolt();
        $table = "CREATE TABLE `we``ird` (`i``d` int PRIMARY KEY, `x``y` varchar(20), `a;b` int);";
        $source = $this->db('qn_source', $table . "
            ALTER TABLE `we``ird` ADD COLUMN `n``ew` varchar(20) NULL;
            CREATE TABLE `only``here` (`k``ey` varchar(20) PRIMARY KEY);
            INSERT INTO `only``here` VALUES ('a`b');
            INSERT INTO `we``ird` VALUES (1, 'one', 1, NULL), (2, 'two`', 2, NULL), (4, 'four', NULL, NULL);");
        $target = $this->db('qn_target', $table . "
            INSERT INTO `we``ird` VALUES (1, 'changed', 1), (3, 'gone', 3), (4, 'four', NULL);");
        $untouched = $this->db('qn_untouched', $table . "
            INSERT INTO `we``ird` VALUES (1, 'changed', 1), (3, 'gone', 3), (4, 'four', NULL);");

        [$up, $down] = $this->migration($source, $target);
        $this->apply($target, $up);
        $this->assertSame([null, null], $this->migration($source, $target), "the target still differs after the UP:\n$up");

        $this->apply($target, $down);
        $this->assertSame([null, null], $this->migration($untouched, $target), "the target differs from its old self after the DOWN:\n$down");
    }

    /**
     * BIT values: they were written as quoted decimals, '5', which MySQL
     * reads as the bytes of the text — "Data too long" in strict mode, a
     * different value otherwise.
     */
    public function testBitValuesRoundTrip(): void
    {
        $this->skipOnDolt();
        $table = 'CREATE TABLE flags (id int PRIMARY KEY, b3 bit(3), b64 bit(64));';
        $rows = "INSERT INTO flags VALUES (1, 0, 0), (2, b'1', NULL), (3, b'11', 1);";
        $source = $this->db('bit_source', $table . "
            INSERT INTO flags VALUES (1, b'101', 18446744073709551615), (2, b'0', b'1010'), (4, b'111', 9223372036854775808);
            CREATE TABLE more_flags (id int PRIMARY KEY, b bit(8));
            INSERT INTO more_flags VALUES (1, b'10000001');");
        $target = $this->db('bit_target', $table . $rows);
        $untouched = $this->db('bit_untouched', $table . $rows);

        [$up, $down] = $this->migration($source, $target);
        $this->apply($target, $up);
        $this->assertSame([null, null], $this->migration($source, $target), "the target still differs after the UP:\n$up");

        $this->apply($target, $down);
        $this->assertSame([null, null], $this->migration($untouched, $target), "the target differs from its old self after the DOWN:\n$down");
    }

    /**
     * Dolt reads CONVERT(<integer column> USING utf8mb4) as 0, so a row only
     * one side has, read that way, gets the wrong key. DBDiffComprehensiveTest
     * skips its data diffs on Dolt too.
     */
    private function skipOnDolt(): void
    {
        if (getenv('DBDIFF_ENGINE') === 'dolt') {
            $this->markTestSkipped('Dolt: CONVERT(<integer> USING utf8mb4) reads as 0, so rows only one side has are not diffed correctly');
        }
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
