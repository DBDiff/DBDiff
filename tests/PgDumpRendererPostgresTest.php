<?php

declare(strict_types=1);

namespace Tests;

use DBDiff\DB\Adapters\PostgresAdapter;
use DBDiff\DB\Support\PgDumpRenderer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The pg_dump renderer, against a real database.
 *
 * These cannot be unit tests: the whole point is that PostgreSQL's own tooling
 * produces the DDL, so a mocked pg_dump would only confirm the mock. Each case
 * asserts on something the built-in renderer got wrong, since that is the
 * reason this path exists.
 *
 * Skips when pg_dump is absent — which is the same condition under which the
 * renderer itself steps aside, so a machine without it still runs the suite.
 */
class PgDumpRendererPostgresTest extends TestCase
{
    private ?Capsule $capsule = null;
    private PostgresAdapter $adapter;
    private string $database = 'dbdiff_pgdump_test';

    protected function setUp(): void
    {
        $host = getenv('DB_HOST_POSTGRES') ?: getenv('DB_HOST');
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES not set.');
        }
        if (!self::commandExists('pg_dump') || !self::commandExists('pg_restore')) {
            $this->markTestSkipped('pg_dump/pg_restore not on PATH.');
        }

        // The suite pins the renderer off for the fixture-based tests; this one
        // is about the renderer, so it opts back in.
        putenv('DBDIFF_PG_DUMP_RENDERER=');

        $port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $pass = getenv('DB_PASSWORD_POSTGRES') ?: 'rootpass';

        $admin = $this->connect($host, $port, $user, $pass, 'postgres');
        // A connection left open by the previous case blocks DROP DATABASE.
        $admin->statement(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
              WHERE datname = ? AND pid <> pg_backend_pid()",
            [$this->database]
        );
        $admin->statement("DROP DATABASE IF EXISTS {$this->database}");
        $admin->statement("CREATE DATABASE {$this->database}");
        $admin->disconnect();

        $this->capsule = null;
        $this->connection = $this->connect($host, $port, $user, $pass, $this->database);
        $this->adapter = new PostgresAdapter();
        PgDumpRenderer::reset();
    }

    protected function tearDown(): void
    {
        PgDumpRenderer::reset();
        putenv('DBDIFF_PG_DUMP_RENDERER=off');
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    private \Illuminate\Database\Connection $connection;

    private function connect(string $host, string $port, string $user, string $pass, string $db): \Illuminate\Database\Connection
    {
        $capsule = new Capsule();

        // DBDiff's own manager fetches associative rows; the adapters index
        // into them by column name, so a test connection must match or it
        // fails with "Cannot use object of type stdClass as array".
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, static function ($event): void {
            $event->statement->setFetchMode(\PDO::FETCH_ASSOC);
        });
        $capsule->setEventDispatcher($dispatcher);

        $capsule->addConnection([
            'driver' => 'pgsql', 'host' => $host, 'port' => $port,
            'database' => $db, 'username' => $user, 'password' => $pass,
            'charset' => 'utf8', 'schema' => 'public',
        ], $db . '_' . spl_object_id($this));

        return $capsule->getConnection($db . '_' . spl_object_id($this));
    }

    private static function commandExists(string $name): bool
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$name, '--version'], $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    /** Collation decides sort order, and the built-in renderer dropped it. */
    public function testRendersAnExplicitCollation(): void
    {
        $this->connection->statement('CREATE TABLE t (id int, name text COLLATE "C")');

        $ddl = $this->adapter->getCreateStatement($this->connection, 't');

        $this->assertStringContainsString('COLLATE', $ddl);
        $this->assertStringContainsString('"C"', $ddl);
    }

    /**
     * An identity column's sequence options live in a separate table-of-contents
     * entry named after the sequence, not the table. Matching on the table name
     * alone silently produced a plain identity counting from one.
     */
    public function testRendersIdentitySequenceOptions(): void
    {
        $this->connection->statement(
            'CREATE TABLE t (id bigint GENERATED ALWAYS AS IDENTITY (INCREMENT 10 START 100), n text)'
        );

        $ddl = $this->adapter->getCreateStatement($this->connection, 't');

        $this->assertStringContainsString('GENERATED ALWAYS AS IDENTITY', $ddl);
        $this->assertStringContainsString('INCREMENT BY 10', $ddl);
        $this->assertStringContainsString('START WITH 100', $ddl);
    }

    /** Indexes are their own entries, under their own names. */
    public function testRendersIndexesBelongingToTheTable(): void
    {
        $this->connection->statement('CREATE TABLE t (id int, b int)');
        $this->connection->statement('CREATE INDEX t_b_idx ON t (b)');

        $ddl = $this->adapter->getCreateStatement($this->connection, 't');

        $this->assertStringContainsString('CREATE INDEX t_b_idx', $ddl);
    }

    /** An unrelated table must not be dragged in by a shared listing. */
    public function testRendersOnlyTheRequestedTable(): void
    {
        $this->connection->statement('CREATE TABLE wanted (id int)');
        $this->connection->statement('CREATE TABLE other (id int)');

        $ddl = $this->adapter->getCreateStatement($this->connection, 'wanted');

        $this->assertStringContainsString('wanted', $ddl);
        $this->assertStringNotContainsString('other', $ddl);
    }

    /**
     * Callers append their own terminator, so the renderer must not leave one —
     * this produced `PRIMARY KEY (id);;` before it was fixed.
     */
    public function testDoesNotLeaveATrailingSemicolon(): void
    {
        $this->connection->statement('CREATE TABLE t (id int PRIMARY KEY)');

        $ddl = $this->adapter->getCreateStatement($this->connection, 't');

        $this->assertStringEndsNotWith(';', $ddl);
    }

    /** Ownership and psql meta-commands are not part of a migration. */
    public function testStripsOwnershipAndMetaCommands(): void
    {
        $this->connection->statement('CREATE TABLE t (id int)');

        $ddl = $this->adapter->getCreateStatement($this->connection, 't');

        $this->assertStringNotContainsString('OWNER TO', $ddl);
        $this->assertStringNotContainsString('\\restrict', $ddl);
        $this->assertStringNotContainsString('SET statement_timeout', $ddl);
    }

    /**
     * The off switch has to actually reach the built-in renderer, or the
     * fixture-based suites would silently depend on the machine.
     */
    public function testTheOffSwitchFallsBackToTheBuiltInRenderer(): void
    {
        $this->connection->statement('CREATE TABLE t (id int, name text COLLATE "C")');

        putenv('DBDIFF_PG_DUMP_RENDERER=off');
        PgDumpRenderer::reset();
        $builtIn = $this->adapter->getCreateStatement($this->connection, 't');

        // The built-in renderer quotes identifiers; pg_dump schema-qualifies them.
        $this->assertStringContainsString('"t"', $builtIn);
        $this->assertStringNotContainsString('public.t', $builtIn);
    }

    /**
     * A pg_dump older than the server cannot read it, so the renderer must
     * decline rather than emit a partial migration.
     */
    public function testDeclinesWhenPgDumpIsOlderThanTheServer(): void
    {
        $stub = sys_get_temp_dir() . '/fake_pg_dump_' . getmypid();
        file_put_contents($stub, "#!/bin/sh\necho 'pg_dump (PostgreSQL) 9.6.24'\n");
        chmod($stub, 0755);

        putenv('DBDIFF_PG_DUMP=' . $stub);
        PgDumpRenderer::reset();

        try {
            $this->assertFalse(PgDumpRenderer::isActive($this->connection));
            $this->assertStringContainsString('older than the server', (string) PgDumpRenderer::unavailableReason($this->connection));
        } finally {
            putenv('DBDIFF_PG_DUMP');
            @unlink($stub);
        }
    }
}
