<?php

use DBDiff\DB\Adapters\PostgresAdapter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The pre-scan hash, against two real databases (issue #189).
 *
 * The pre-scan hashes every table on both sides and skips the ones whose
 * hashes match. That makes the hash a correctness feature, not just a speed
 * one, and it had a fault at each end:
 *
 *  1. It hashed `information_schema.table_constraints.constraint_name`. On
 *     PostgreSQL 17 and earlier, every NOT NULL column appears there as a CHECK
 *     constraint named `<namespace oid>_<table oid>_<column number>_not_null`.
 *     Table OIDs differ between databases, so two identical tables hashed
 *     differently and nothing was skipped — for the only comparison the
 *     pre-scan exists to speed up, two databases. It appeared to work only
 *     when both sides were the same database, which is how benchmarks are run.
 *
 *  2. The hash covered less than the comparison does, so a table it skipped
 *     could differ in a way nobody was told about: a column's collation, a
 *     foreign key's target, and — since the fix for #215 made the emitted type
 *     depend on the modifier — a bare `timestamptz` against a `timestamptz(6)`.
 *
 * Fixing either alone makes the other worse, which is why both are here. The
 * matrix test is the one that matters: for every single-attribute change, the
 * hash must disagree if the comparison would emit DDL. A hash that is merely
 * "more complete than before" invites the same bug back.
 *
 * Skips automatically when pdo_pgsql is missing or DB_HOST_POSTGRES is unset.
 */
class SchemaHashPostgresTest extends TestCase
{
    private const DB_A = 'dbdiff_hash_a';
    private const DB_B = 'dbdiff_hash_b';

    private ?PDO $adminDb = null;
    private $connA;
    private $connB;
    private PostgresAdapter $adapter;

    private function dropDb(string $name): void
    {
        try {
            $this->adminDb->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
        } catch (PDOException $e) {
            $this->adminDb->exec("DROP DATABASE IF EXISTS $name");
        }
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded.');
        }
        $host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set.');
        }

        $port = getenv('DB_PORT_POSTGRES') ?: '5432';
        $user = getenv('DB_USER_POSTGRES') ?: 'dbdiff';
        $pass = getenv('DB_PASS_POSTGRES') ?: 'rootpass';

        $this->adminDb = new PDO(
            "pgsql:host=$host;port=$port;dbname=diff1",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $capsule    = new Capsule;
        $dispatcher = new Dispatcher();
        $dispatcher->listen(StatementPrepared::class, function ($event) {
            $event->statement->setFetchMode(PDO::FETCH_ASSOC);
        });
        $capsule->setEventDispatcher($dispatcher);

        foreach ([self::DB_A => 'hash_a', self::DB_B => 'hash_b'] as $db => $name) {
            $this->dropDb($db);
            $this->adminDb->exec("CREATE DATABASE $db");
            $capsule->addConnection([
                'driver'   => 'pgsql',
                'host'     => $host,
                'port'     => $port,
                'database' => $db,
                'username' => $user,
                'password' => $pass,
                'charset'  => 'utf8',
                'schema'   => 'public',
            ], $name);
        }

        $this->connA   = $capsule->getConnection('hash_a');
        $this->connB   = $capsule->getConnection('hash_b');
        $this->adapter = new PostgresAdapter();
    }

    protected function tearDown(): void
    {
        if ($this->adminDb) {
            $this->dropDb(self::DB_A);
            $this->dropDb(self::DB_B);
            $this->adminDb = null;
        }
    }

    /** Build both databases, then return the two hash maps. */
    private function hashes(string $sqlA, string $sqlB): array
    {
        $this->connA->unprepared($sqlA);
        $this->connB->unprepared($sqlB);

        return [
            $this->adapter->getSchemaHashMap($this->connA),
            $this->adapter->getSchemaHashMap($this->connB),
        ];
    }

    // ── 1. Identical tables must hash identically across databases ────────────

    public function testIdenticalTablesWithAPrimaryKeyHashTheSameInTwoDatabases(): void
    {
        // The reported case. A primary-key column is NOT NULL, which is what
        // dragged the OID-derived constraint name into the hash, so on
        // PostgreSQL <= 17 this was the common table that never matched.
        $ddl = 'CREATE TABLE pk_one (id integer PRIMARY KEY, v text);';
        [$a, $b] = $this->hashes($ddl, $ddl);

        $this->assertSame($a['pk_one'], $b['pk_one']);
    }

    public function testIdenticalTablesOfEveryShapeHashTheSameInTwoDatabases(): void
    {
        $ddl = <<<'SQL'
            CREATE TABLE parent (id integer PRIMARY KEY);
            CREATE TABLE plain (a integer, b text);
            CREATE TABLE notnull_only (a integer NOT NULL);
            CREATE TABLE with_unique (id integer PRIMARY KEY, code text UNIQUE);
            CREATE TABLE with_fk (id integer PRIMARY KEY, pid integer REFERENCES parent (id));
            CREATE TABLE with_check (id integer PRIMARY KEY, n integer CHECK (n > 0));
            CREATE TABLE with_identity (id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY);
            CREATE TABLE with_generated (a integer, b integer GENERATED ALWAYS AS (a * 2) STORED);
            CREATE INDEX plain_a_idx ON plain (a);
        SQL;
        [$a, $b] = $this->hashes($ddl, $ddl);

        $this->assertNotEmpty($a);
        foreach ($a as $table => $hash) {
            $this->assertSame($hash, $b[$table] ?? null, "$table hashed differently");
        }
    }

    // ── 2. Anything the comparison detects must change the hash ───────────────

    /**
     * Each case is a single-attribute difference the full comparison reports.
     * The hash has to disagree for all of them, or the pre-scan skips the table
     * and the difference is lost with no warning.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function detectableChangeProvider(): array
    {
        return [
            'unlogged persistence' => [
                'CREATE TABLE t (c integer);',
                'CREATE UNLOGGED TABLE t (c integer);',
            ],
            'table storage parameters' => [
                'CREATE TABLE t (c integer);',
                'CREATE TABLE t (c integer) WITH (fillfactor = 70);',
            ],
            // The two named in the issue.
            'column collation' => [
                'CREATE TABLE t (c text);',
                'CREATE TABLE t (c text COLLATE "C");',
            ],
            'foreign key target' => [
                'CREATE TABLE pa (id integer PRIMARY KEY); CREATE TABLE pb (id integer PRIMARY KEY);'
                    . 'CREATE TABLE t (pid integer REFERENCES pa (id));',
                'CREATE TABLE pa (id integer PRIMARY KEY); CREATE TABLE pb (id integer PRIMARY KEY);'
                    . 'CREATE TABLE t (pid integer REFERENCES pb (id));',
            ],
            // Found while fixing this: information_schema reports
            // datetime_precision 6 for both, so the hash could not tell them
            // apart, while the comparison has since #215.
            'timestamp modifier' => [
                'CREATE TABLE t (c timestamptz);',
                'CREATE TABLE t (c timestamptz(6));',
            ],
            'time modifier' => [
                'CREATE TABLE t (c time);',
                'CREATE TABLE t (c time(3));',
            ],
            // Guards for what already worked, so a rewrite cannot quietly drop
            // them.
            'column type'        => ['CREATE TABLE t (c integer);', 'CREATE TABLE t (c bigint);'],
            'varchar length'     => ['CREATE TABLE t (c varchar(50));', 'CREATE TABLE t (c varchar(100));'],
            'numeric scale'      => ['CREATE TABLE t (c numeric(10,2));', 'CREATE TABLE t (c numeric(10,4));'],
            'nullability'        => ['CREATE TABLE t (c integer);', 'CREATE TABLE t (c integer NOT NULL);'],
            'default'            => ['CREATE TABLE t (c integer);', 'CREATE TABLE t (c integer DEFAULT 1);'],
            'new column'         => ['CREATE TABLE t (a integer);', 'CREATE TABLE t (a integer, b text);'],
            'column order'       => ['CREATE TABLE t (a integer, b text);', 'CREATE TABLE t (b text, a integer);'],
            'index'              => [
                'CREATE TABLE t (a integer);',
                'CREATE TABLE t (a integer); CREATE INDEX t_a_idx ON t (a);',
            ],
            'unique constraint'  => [
                'CREATE TABLE t (a integer);',
                'CREATE TABLE t (a integer UNIQUE);',
            ],
            'check constraint'   => [
                'CREATE TABLE t (a integer);',
                'CREATE TABLE t (a integer CHECK (a > 0));',
            ],
            'foreign key rule'   => [
                'CREATE TABLE pa (id integer PRIMARY KEY); CREATE TABLE t (pid integer REFERENCES pa (id));',
                'CREATE TABLE pa (id integer PRIMARY KEY); CREATE TABLE t (pid integer REFERENCES pa (id) ON DELETE CASCADE);',
            ],
            'identity'           => [
                'CREATE TABLE t (id integer PRIMARY KEY);',
                'CREATE TABLE t (id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY);',
            ],
            'generated column'   => [
                'CREATE TABLE t (a integer, b integer);',
                'CREATE TABLE t (a integer, b integer GENERATED ALWAYS AS (a * 2) STORED);',
            ],
            'storage'            => [
                'CREATE TABLE t (c text);',
                'CREATE TABLE t (c text); ALTER TABLE t ALTER COLUMN c SET STORAGE EXTERNAL;',
            ],
        ];
    }

    /** @dataProvider detectableChangeProvider */
    public function testTheHashNoticesADetectableChange(string $sqlA, string $sqlB): void
    {
        [$a, $b] = $this->hashes($sqlA, $sqlB);

        $this->assertNotSame(
            $a['t'],
            $b['t'],
            'the hash matches, so the pre-scan would skip this table and lose the change'
        );
    }

    /**
     * The same table, unchanged, in each of those scenarios: the hash must
     * still match, or the pre-scan skips nothing and the fix has only traded
     * correctness for cost.
     *
     * @dataProvider detectableChangeProvider
     */
    public function testUnrelatedTablesStillMatch(string $sqlA, string $_sqlB): void
    {
        $extra = ' CREATE TABLE untouched (id integer PRIMARY KEY, label text NOT NULL);';
        [$a, $b] = $this->hashes($sqlA . $extra, $sqlA . $extra);

        $this->assertSame($a['untouched'], $b['untouched']);
    }
}
