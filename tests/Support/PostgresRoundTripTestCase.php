<?php

use PHPUnit\Framework\TestCase;

/**
 * Migrations checked by running them against a live PostgreSQL server.
 *
 * Every case builds a source and a target, generates the migration with the
 * real CLI, applies its UP to the target and diffs again — which must report
 * nothing — then applies its DOWN and compares the target with an untouched
 * copy of itself, which must also report nothing. A migration that is valid
 * SQL but that PostgreSQL refuses, or that quietly loses something the diff
 * does not compare, fails here rather than in someone's deploy.
 *
 * Grants, options and comments on views are checked directly as well: the
 * diff does not compare grants or comments, so "identical" alone would not
 * notice a recreated view that lost them.
 *
 * Skips automatically when pdo_pgsql is missing or DB_HOST_POSTGRES is unset.
 */
abstract class PostgresRoundTripTestCase extends TestCase
{
    protected ?PDO $admin = null;
    protected string $host;
    protected string $port;
    protected string $user;
    protected string $pass;
    /** Scratch databases are named `<prefix>_<case>_{s,t,o}`. */
    protected string $prefix = 'dbdiff_rt';
    private array $created = [];
    /** The server's version, as `server_version_num`. */
    protected int $serverVersion = 0;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }
        $this->host = $host;
        $this->port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $this->user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $this->pass = getenv('DB_PASS_POSTGRES') ?: 'rootpass';

        $this->admin = new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname=diff1",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->serverVersion = (int) $this->admin->query('SHOW server_version_num')->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $db) {
            $this->dropDb($db);
        }
        $this->admin = null;
    }

    protected function dropDb(string $db): void
    {
        $this->admin->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
              WHERE datname = '$db' AND pid <> pg_backend_pid()"
        );
        $this->admin->exec("DROP DATABASE IF EXISTS $db");
    }

    protected function db(string $name, string $sql): string
    {
        $db = "{$this->prefix}_$name";
        $this->dropDb($db);
        $this->admin->exec("CREATE DATABASE $db");
        $this->created[] = $db;
        if ($sql !== '') {
            $this->connect($db)->exec($sql);
        }
        return $db;
    }

    protected function connect(string $db): PDO
    {
        return new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname=$db",
            $this->user,
            $this->pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function url(string $db): string
    {
        return "postgres://{$this->user}:{$this->pass}@{$this->host}:{$this->port}/$db";
    }

    /**
     * The migration from `$source` to `$target` as [up, down], or null when
     * the CLI reports the two identical. `$type` is the CLI's --type.
     *
     * Run as a separate process: the CLI keeps process-wide state (the
     * dialect, the parsed parameters), and each case needs a clean one.
     */
    protected function diff(string $source, string $target, string $type = 'schema'): ?array
    {
        $out = tempnam(sys_get_temp_dir(), $this->prefix . '_') . '.sql';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/dbdiff.php')
            . ' diff --type=' . escapeshellarg($type) . ' --nocomments --include=both --allow-destructive'
            . ' --server1-url=' . escapeshellarg($this->url($source))
            . ' --server2-url=' . escapeshellarg($this->url($target))
            . ' --output=' . escapeshellarg($out) . ' 2>&1';
        exec($cmd, $lines, $status);
        $log = implode("\n", $lines);

        $this->assertSame(0, $status, "dbdiff failed:\n$log");
        if (!file_exists($out)) {
            $this->assertStringContainsString('identical', $log);
            return null;
        }

        $sql = file_get_contents($out);
        unlink($out);
        $parts = preg_split('/^-- =+ DOWN =+$/m', $sql, 2);
        $up = $parts[0];
        $down = $parts[1] ?? '';
        return self::hasStatements($up) || self::hasStatements($down) ? [$up, $down] : null;
    }

    private static function hasStatements(string $sql): bool
    {
        return trim(preg_replace('/^--.*$/m', '', $sql)) !== '';
    }

    /**
     * View and materialised view metadata the diff does not itself compare:
     * options, grants and comments.
     */
    protected function viewMetadata(string $db): array
    {
        return $this->connect($db)->query(
            "SELECT n.nspname || '.' || c.relname AS name,
                    coalesce(c.reloptions::text, '') AS options,
                    coalesce(c.relacl::text, '') AS acl,
                    coalesce(obj_description(c.oid, 'pg_class'), '') AS comment,
                    (SELECT count(*) FROM pg_trigger t WHERE t.tgrelid = c.oid AND NOT t.tgisinternal) AS triggers,
                    (SELECT count(*) FROM pg_index i WHERE i.indrelid = c.oid) AS indexes
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('v', 'm') AND n.nspname NOT IN ('pg_catalog', 'information_schema')
             ORDER BY 1"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate, apply UP, rediff; apply DOWN, compare with the original.
     *
     * `$state`, given a database name, returns what else must survive that
     * the diff does not compare — data, a sequence's position, a type's
     * grants. After the UP the target must match the source on it; after
     * the DOWN, its own original.
     *
     * @return string the UP, for further assertions
     */
    protected function assertRoundTrip(string $case, string $sourceSql, string $targetSql, ?callable $state = null): string
    {
        $state ??= fn(string $db) => [];
        $source   = $this->db("{$case}_s", $sourceSql);
        $target   = $this->db("{$case}_t", $targetSql);
        $original = $this->db("{$case}_o", $targetSql);
        $sourceMeta = $this->viewMetadata($source);
        $targetMeta = $this->viewMetadata($target);
        $sourceState = $state($source);
        $targetState = $state($target);

        $migration = $this->diff($source, $target);
        $this->assertNotNull($migration, "$case: expected a difference");
        [$up, $down] = $migration;

        $this->apply($target, $up);
        $this->assertNull($this->diff($source, $target), "$case: UP left a difference behind:\n$up");
        $this->assertEquals($sourceMeta, $this->viewMetadata($target), "$case: UP lost view metadata:\n$up");
        $this->assertEquals($sourceState, $state($target), "$case: UP did not reach the source's state:\n$up");

        $this->apply($target, $down);
        $this->assertNull($this->diff($original, $target), "$case: DOWN did not restore the target:\n$down");
        $this->assertEquals($targetMeta, $this->viewMetadata($target), "$case: DOWN lost view metadata:\n$down");
        $this->assertEquals($targetState, $state($target), "$case: DOWN did not restore the target's state:\n$down");

        return $up;
    }

    /**
     * Apply a migration as one transaction, the way DBDiff's runner does:
     * enum label additions first, each committed, since a label added in a
     * transaction cannot be used before it commits.
     */
    protected function apply(string $db, string $sql): void
    {
        $pdo = $this->connect($db);
        $rest = [];
        foreach (explode("\n", $sql) as $line) {
            if (\DBDiff\SQLGen\DiffToSQL\AlterEnumSQL::isValueAddition($line)) {
                $pdo->exec($line);
            } else {
                $rest[] = $line;
            }
        }
        $rest = implode("\n", $rest);
        if (self::hasStatements($rest)) {
            $pdo->exec($rest);
        }
    }

    /** One query's rows, for a `$state` callable. */
    protected function rows(string $db, string $sql): array
    {
        return $this->connect($db)->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Generate and apply the UP only, then diff again: nothing may remain. */
    protected function assertUpConverges(string $case, string $sourceSql, string $targetSql): void
    {
        $source = $this->db("{$case}_s", $sourceSql);
        $target = $this->db("{$case}_t", $targetSql);

        $migration = $this->diff($source, $target);
        $this->assertNotNull($migration, "$case: expected a difference");
        $this->apply($target, $migration[0]);
        $this->assertNull($this->diff($source, $target), "$case: UP left a difference behind:\n{$migration[0]}");
    }
}
