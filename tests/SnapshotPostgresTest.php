<?php

use DBDiff\DB\DBManager;
use DBDiff\DB\Support\PostgresRenderers;
use DBDiff\DB\Support\PostgresSnapshot;

/**
 * Each side of a diff is read as of the instant it was connected to.
 *
 * A table made on the source after the diff connected, while it reads, is
 * not seen by any of its reads — including those made over the connection
 * opened again for another schema. Without the snapshot it is.
 */
class SnapshotPostgresTest extends PostgresRoundTripTestCase
{
    protected string $prefix = 'dbdiff_snap';

    private function manager(string $source, string $target, bool $snapshot = true, ?string $sourceSnapshot = null): DBManager
    {
        $server = ['user' => $this->user, 'password' => $this->pass, 'host' => $this->host, 'port' => $this->port];
        $params = new \stdClass;
        $params->driver   = 'pgsql';
        $params->snapshot = $snapshot;
        $params->sourceSnapshot = $sourceSnapshot;
        $params->server1  = $server;
        $params->server2  = $server;
        $params->input    = [
            'source' => ['server' => 'server1', 'db' => $source],
            'target' => ['server' => 'server2', 'db' => $target],
        ];
        $manager = new DBManager;
        $manager->connect($params);
        return $manager;
    }

    public function testATableMadeWhileTheDiffReadsIsNotSeen(): void
    {
        $source = $this->db('s', 'CREATE TABLE before_connect (id int);');
        $target = $this->db('t', '');
        $manager = $this->manager($source, $target);
        $this->assertTrue($manager->readsSnapshot('source'));

        $this->connect($source)->exec('CREATE TABLE after_connect (id int);');

        $this->assertSame(['before_connect'], $manager->getTables('source'));
    }

    public function testWithoutTheSnapshotItIs(): void
    {
        $source = $this->db('ns', 'CREATE TABLE before_connect (id int);');
        $target = $this->db('nt', '');
        $manager = $this->manager($source, $target, false);
        $this->assertFalse($manager->readsSnapshot('source'));

        $this->connect($source)->exec('CREATE TABLE after_connect (id int);');

        $this->assertEqualsCanonicalizing(['before_connect', 'after_connect'], $manager->getTables('source'));
    }

    public function testTheConnectionForAnotherSchemaReadsAsOfTheSameInstant(): void
    {
        $source = $this->db('ss', 'CREATE SCHEMA app; CREATE TABLE app.kept (id int);');
        $target = $this->db('st', 'CREATE SCHEMA app;');
        $manager = $this->manager($source, $target);

        $this->connect($source)->exec('CREATE TABLE app.added (id int);');
        $manager->useSchema('app');

        $this->assertTrue($manager->readsSnapshot('source'));
        $this->assertSame(['kept'], $manager->getTables('source'));
    }

    public function testAFailedReadOrARenderingLeavesTheSnapshotReadable(): void
    {
        $source = $this->db('fs', 'CREATE TABLE t (id int);');
        $target = $this->db('ft', '');
        $manager = $this->manager($source, $target);
        $connection = $manager->getDB('source');

        try {
            PostgresSnapshot::attempt($connection, fn() => $connection->select('SELECT * FROM no_such_table'));
            $this->fail('the read should have failed');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no_such_table', $e->getMessage());
        }
        $this->assertTrue(PostgresRenderers::canRender($connection));
        $this->connect($source)->exec('CREATE TABLE later (id int);');

        $this->assertTrue($connection->getPdo()->inTransaction());
        $this->assertSame(['t'], $manager->getTables('source'));
        $this->assertSame(0, (int) $connection->selectOne("SELECT count(*) AS n FROM pg_class WHERE relname = 'dbdiff_render_probe'")['n']);
    }

    public function testASnapshotAnotherSessionHoldsIsReadAsOf(): void
    {
        $source = $this->db('gs', 'CREATE TABLE exported_before (id int);');
        $target = $this->db('gt', '');
        $holder = $this->connect($source);
        $holder->exec('BEGIN ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $id = $holder->query('SELECT pg_export_snapshot()')->fetchColumn();
        $this->connect($source)->exec('CREATE TABLE made_after (id int);');

        $manager = $this->manager($source, $target, true, $id);

        $this->assertTrue($manager->readsSnapshot('source'));
        $this->assertSame(['exported_before'], $manager->getTables('source'));
        $holder->exec('ROLLBACK');
    }
}
