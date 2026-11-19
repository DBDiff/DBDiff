<?php

use PHPUnit\Framework\TestCase;

/**
 * SQLite values keep their storage class through a data diff applied, UP
 * and DOWN, through the CLI.
 *
 * Every value was written quoted: an integer in a column with no declared
 * type came back as text, a STRICT table's REAL column refused 'Infinity',
 * and blobs and text holding a NUL were raw bytes inside a string literal.
 */
class SQLiteValueTypesTest extends TestCase
{
    private array $files = [];

    private const TABLES = 'CREATE TABLE mixed (id integer PRIMARY KEY, v);'
        . ' CREATE TABLE strict_t (id integer PRIMARY KEY, n integer, r real, t text, b blob) STRICT;';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension not loaded.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testValuesKeepTheirStorageClass(): void
    {
        $source = $this->db(self::TABLES . <<<'SQL'
            INSERT INTO mixed VALUES (1, 9223372036854775807), (2, -9223372036854775808), (3, 1.5e308), (4, 'text'),
              (5, X'00FF27'), (6, NULL), (7, '0012'), (8, 12), (9, CAST(X'610062' AS TEXT)), (10, -0.0), (11, 9e999), (12, 2.5);
            INSERT INTO strict_t VALUES (1, 9223372036854775807, 9e999, 'x'');DROP TABLE mixed;--', X'0001'), (2, 0, -9e999, 'é', X'');
            SQL);
        $target = $this->db(self::TABLES . "INSERT INTO mixed VALUES (1, 1), (8, 'old'), (13, 'gone'); INSERT INTO strict_t VALUES (2, 5, 1.0, 'old', X'FF');");
        $before = $this->contents($target);

        [$up, $down] = $this->migration($source, $target);

        $this->pdo($target)->exec($up);
        $this->assertSame($this->contents($source), $this->contents($target), "UP makes the target the source:\n$up");
        $this->pdo($target)->exec($down);
        $this->assertSame($before, $this->contents($target), "DOWN restores the target:\n$down");
    }

    /** A new database file holding `$sql`. The CLI splits its input on dots, so the path has none. */
    private function db(string $sql): string
    {
        $file = tempnam(sys_get_temp_dir(), 'dbdiffsvt');
        $this->files[] = $file;
        $this->pdo($file)->exec($sql);
        return $file;
    }

    private function pdo(string $file): PDO
    {
        return new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{string, string} the CLI's UP and DOWN */
    private function migration(string $source, string $target): array
    {
        $out = tempnam(sys_get_temp_dir(), 'dbdiffsvtout');
        $this->files[] = $out;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/dbdiff.php')
            . ' diff --driver=sqlite --type=all --include=both --nocomments --allow-destructive'
            . ' --output=' . escapeshellarg($out) . ' ' . escapeshellarg("server1.$source:server1.$target") . ' 2>&1', $log, $status);
        $this->assertSame(0, $status, implode("\n", $log));
        $parts = preg_split('/^-- =+ DOWN =+$/m', file_get_contents($out), 2);
        return [preg_replace('/^-- =+ UP =+$/m', '', $parts[0]), $parts[1] ?? ''];
    }

    /** Every value with its storage class, as SQLite itself writes them. */
    private function contents(string $file): array
    {
        $pdo = $this->pdo($file);
        return [
            'mixed'    => $pdo->query('SELECT id, typeof(v), quote(v) FROM mixed ORDER BY id')->fetchAll(PDO::FETCH_NUM),
            'strict_t' => $pdo->query('SELECT id, quote(n), quote(r), quote(t), quote(b) FROM strict_t ORDER BY id')->fetchAll(PDO::FETCH_NUM),
        ];
    }
}
