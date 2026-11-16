<?php

use PHPUnit\Framework\TestCase;

/**
 * A column the source added and filled in, on SQLite, diffed through the CLI
 * and applied: rows both sides hold take the source's values for it. See
 * NewColumnValuesRoundTripPostgresTest.
 */
class NewColumnValuesSQLiteTest extends TestCase
{
    private array $files = [];

    private const TABLE = "CREATE TABLE t (id integer PRIMARY KEY, name text); INSERT INTO t VALUES (1, 'one'), (2, 'two'), (3, 'three');";

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

    public function testANewColumnsValuesComeWithIt(): void
    {
        $source = $this->db(self::TABLE . <<<'SQL'
            ALTER TABLE t ADD COLUMN status text DEFAULT 'active';
            UPDATE t SET status = 'archived' WHERE id = 1;
            UPDATE t SET status = NULL WHERE id = 3;
            INSERT INTO t VALUES (4, 'four', 'new');
            SQL);
        $target = $this->db(self::TABLE);
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
        $file = tempnam(sys_get_temp_dir(), 'dbdiffncv');
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
        $out = tempnam(sys_get_temp_dir(), 'dbdiffncvout');
        $this->files[] = $out;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/dbdiff.php')
            . ' diff --driver=sqlite --type=all --include=both --nocomments --allow-destructive'
            . ' --output=' . escapeshellarg($out) . ' ' . escapeshellarg("server1.$source:server1.$target") . ' 2>&1', $log, $status);
        $this->assertSame(0, $status, implode("\n", $log));
        $parts = preg_split('/^-- =+ DOWN =+$/m', file_get_contents($out), 2);
        return [preg_replace('/^-- =+ UP =+$/m', '', $parts[0]), $parts[1] ?? ''];
    }

    /** Every table's columns and rows. */
    private function contents(string $file): array
    {
        $pdo = $this->pdo($file);
        $out = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $quoted = '"' . str_replace('"', '""', $table) . '"';
            $out[$table] = $pdo->query("SELECT * FROM $quoted ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }
}
