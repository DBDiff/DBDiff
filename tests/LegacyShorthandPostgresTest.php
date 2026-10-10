<?php

/**
 * `dbdiff server1.db1:server2.db2`, the 2.x invocation without `diff`,
 * produces the same migration as `dbdiff diff …`.
 */
class LegacyShorthandPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_lsh';

    private function cli(array $args): array
    {
        $out = tempnam(sys_get_temp_dir(), 'lsh_') . '.sql';
        $server = "{$this->user}:{$this->pass}@{$this->host}:{$this->port}";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/dbdiff.php') . ' '
            . implode(' ', array_map('escapeshellarg', $args))
            . ' --driver=pgsql --nocomments --server1=' . escapeshellarg($server) . ' --server2=' . escapeshellarg($server)
            . ' --output=' . escapeshellarg($out) . ' 2>&1';
        exec($cmd, $lines, $status);
        $sql = is_file($out) ? file_get_contents($out) : '';
        @unlink($out);
        return [$status, $sql, implode("\n", $lines)];
    }

    public function testTheLegacyFormDiffsLikeTheDiffCommand(): void
    {
        $source = $this->db('s', 'CREATE TABLE t (id int PRIMARY KEY, note text);');
        $target = $this->db('t', 'CREATE TABLE t (id int PRIMARY KEY);');

        [$legacyStatus, $legacy, $log] = $this->cli(["server1.$source:server2.$target"]);
        [$diffStatus, $diff] = $this->cli(['diff', "server1.$source:server2.$target"]);

        $this->assertSame(0, $legacyStatus, $log);
        $this->assertStringContainsString('ADD COLUMN "note"', $legacy, $log);
        $this->assertSame($diff, $legacy);
        $this->assertSame(0, $diffStatus);
    }
}
