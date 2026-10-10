<?php

use PHPUnit\Framework\TestCase;

/**
 * 2.x's command line, on SQLite files, end to end: no server needed.
 *
 * - `server1./path/v1.db:server1./path/v2.db`, the README's own example, read
 *   `v1.db` as database `v1` and table `db`.
 * - `--nocomments=true`, a config file's `type`/`include`, and `--template`
 *   were ignored or rejected.
 * - PHP 8.2+ printed "Creation of dynamic property" deprecations.
 */
class TwoXCliSqliteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }
        $this->dir = sys_get_temp_dir() . '/dbdiff_2x_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (['src.db' => "INSERT INTO users VALUES (1,'a@x','A'),(2,'b@x','B'),(3,'c@x',NULL)",
                  'tgt.db' => "INSERT INTO users VALUES (1,'a@x',NULL),(2,'bb@x','B'),(4,'d@x',NULL)"] as $file => $rows) {
            $db = new \PDO("sqlite:{$this->dir}/$file");
            $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT)');
            $db->exec($rows);
        }
        copy(dirname(__DIR__, 2) . '/templates/simple-db-migrate.tmpl', "{$this->dir}/simple.tmpl");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/{,.}*", GLOB_BRACE) ?: []);
        @rmdir($this->dir);
    }

    /** @return array{0: int, 1: string, 2: string} exit code, migration.sql, console */
    private function cli(string $args): array
    {
        $cmd = 'cd ' . escapeshellarg($this->dir) . ' && ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=1 '
            . escapeshellarg(dirname(__DIR__, 2) . '/dbdiff.php') . " $args 2>&1";
        exec($cmd, $lines, $status);
        $out = "{$this->dir}/migration.sql";
        $sql = is_file($out) ? file_get_contents($out) : '';
        @unlink($out);
        return [$status, $sql, implode("\n", $lines)];
    }

    private function input(): string
    {
        return "server1.{$this->dir}/src.db:server1.{$this->dir}/tgt.db";
    }

    public function testTheReadmeFormWithDottedPathsWorks(): void
    {
        [$status, $sql, $log] = $this->cli('--driver=sqlite --type=data ' . $this->input());
        $this->assertSame(0, $status, $log);
        $this->assertStringContainsString("INSERT INTO \"users\"", $sql, $log);
        $this->assertStringNotContainsString('Deprecated', $log);
    }

    public function testNocommentsTakesTheTwoXValue(): void
    {
        [$status, $sql, $log] = $this->cli('--driver=sqlite --type=data --nocomments=true ' . $this->input());
        $this->assertSame(0, $status, $log);
        $this->assertStringNotContainsString('-- DBDiff migration', $sql);
        $this->assertStringContainsString('-- ==================== UP ====================', $sql, 'the structure stays');
    }

    public function testTheConfigFileDecidesTypeAndInclude(): void
    {
        file_put_contents("{$this->dir}/.dbdiff", "type: data\ninclude: all\n");
        [$status, $sql, $log] = $this->cli('--driver=sqlite ' . $this->input());
        $this->assertSame(0, $status, $log);
        $this->assertStringContainsString('DOWN', $sql, 'include: all from .dbdiff');
        $this->assertMatchesRegularExpression('/^(INSERT|UPDATE|DELETE) /m', $sql, 'type: data from .dbdiff');

        // A flag still wins over the file.
        [, $schemaOnly] = $this->cli('--driver=sqlite --type=schema --include=up ' . $this->input());
        $this->assertStringNotContainsString('DOWN', $schemaOnly);
    }

    public function testATemplateShapesTheOutput(): void
    {
        [$status, $sql, $log] = $this->cli('--driver=sqlite --type=data --include=all --template=simple.tmpl ' . $this->input());
        $this->assertSame(0, $status, $log);
        $this->assertStringContainsString('SQL_UP = u"""', $sql);
        $this->assertStringContainsString('SQL_DOWN = u"""', $sql);
    }
}
