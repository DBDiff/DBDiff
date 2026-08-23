<?php

require_once __DIR__ . '/AbstractComprehensiveTest.php';

/**
 * PostgreSQL implementation of the comprehensive test suite.
 * All test methods live in AbstractComprehensiveTest.
 *
 * Skips automatically when:
 *   - pdo_pgsql is not loaded
 *   - DB_HOST_POSTGRES is not set (not running inside a Postgres CLI container)
 */
class DBDiffComprehensivePostgresTest extends AbstractComprehensiveTest
{
    private $host;
    private $port      = 5432;
    private $user      = 'dbdiff';
    private $pass      = 'rootpass';
    private $defaultDb = 'diff1'; // pre-created by POSTGRES_DB in compose

    /** @var \PDO Connection used for DDL (CREATE / DROP DATABASE) */
    private $adminDb;
    /** @var int Detected Postgres major version */
    private $pgMajorVersion;

    // ── Abstract method implementations ───────────────────────────────────

    protected function connectAndBootstrap(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded — skipping Postgres comprehensive tests.');
        }

        $this->host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$this->host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set — skipping Postgres comprehensive tests.');
        }

        $this->db1 = 'dbdiff_comp1';
        $this->db2 = 'dbdiff_comp2';

        $this->adminDb = $this->connectWithRetry(
            "pgsql:host={$this->host};port={$this->port};dbname={$this->defaultDb}",
            $this->user,
            $this->pass
        );

        // Detect major version early so getVersionSuffix() works during the test
        $row                  = $this->adminDb->query(
            "SELECT current_setting('server_version_num') AS v"
        )->fetch(PDO::FETCH_ASSOC);
        $this->pgMajorVersion = intdiv((int) ($row['v'] ?? 0), 10000);

        $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db1}");
        $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db2}");
        $this->adminDb->exec("CREATE DATABASE {$this->db1}");
        $this->adminDb->exec("CREATE DATABASE {$this->db2}");
    }

    protected function getVersionSuffix(): string
    {
        return 'pgsql_' . $this->pgMajorVersion;
    }

    protected function loadFixture(string $fixtureName): void
    {
        foreach (['db1' => $this->db1, 'db2' => $this->db2] as $key => $dbName) {
            $file = "tests/fixtures/{$fixtureName}/{$key}-pgsql.sql";
            if (!file_exists($file)) {
                $this->fail("Postgres fixture not found: $file");
            }
            $pdo = new PDO(
                "pgsql:host={$this->host};port={$this->port};dbname={$dbName}",
                $this->user,
                $this->pass
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec(file_get_contents($file));
        }
    }

    protected function driverArgs(): array
    {
        return [
            '--driver=pgsql',
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
        ];
    }

    protected function dbInputArg(): string
    {
        return "server1.{$this->db1}:server1.{$this->db2}";
    }

    protected function tableInputArg(string $table): ?string
    {
        return "server1.{$this->db1}.{$table}:server1.{$this->db2}.{$table}";
    }

    protected function getServerConfig(): array
    {
        return [
            'user'     => $this->user,
            'password' => $this->pass,
            'host'     => $this->host,
            'port'     => $this->port,
        ];
    }

    protected function configDefaults(): array
    {
        return [
            'driver'     => 'pgsql',
            'type'       => 'all',
            'include'    => 'all',
            'nocomments' => true,
        ];
    }

    /**
     * Partial indexes, expression indexes, and multi-column index changes.
     * PostgreSQL-specific: MySQL and SQLite don't support partial indexes.
     */
    public function testPartialIndexChanges(): void
    {
        $this->loadFixture('partial_indexes');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=schema', '--include=all', '--nocomments', $this->dbInputArg()]
        ));
        $this->assertExpectedOutput('partial_indexes', $output);

        $this->assertNotEmpty(
            trim($output),
            'Partial index changes must produce a non-empty diff'
        );
        $this->assertStringContainsString(
            'events',
            $output,
            'Diff should reference the events table'
        );
    }

    /**
     * A generated migration has to be valid SQL, not merely the expected text.
     *
     * Every other test here compares output against tests/expected/*.txt, so a
     * migration PostgreSQL refuses to run still passes as long as the text is
     * stable. Not one of the 114 expected files contains a CREATE TABLE, so
     * creating a table that exists only on the source was entirely uncovered —
     * and it emitted the primary key twice, once bare and once as a named
     * constraint:
     *
     *   CREATE TABLE "t" ("id" bigint NOT NULL, PRIMARY KEY ("id"),
     *                     CONSTRAINT "t_pkey" PRIMARY KEY ("id"));
     *   ERROR: multiple primary keys for table "t" are not allowed
     *
     * This replays the migration into the target and asserts it applies, which
     * is the only assertion that catches invalid DDL.
     */
    public function testGeneratedCreateTableIsValidSql(): void
    {
        $db1 = $this->connectTo($this->db1);
        $db1->exec(
            'CREATE TABLE replay_target (
                id     bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                email  text NOT NULL,
                CONSTRAINT replay_target_email_uniq UNIQUE (email)
            )'
        );

        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=schema', '--include=up', '--nocomments', $this->dbInputArg()]
        ));

        $this->assertStringContainsString('CREATE TABLE', $output, 'Diff should create the missing table');
        $this->assertSame(
            1,
            preg_match_all('/PRIMARY KEY/i', $output),
            "The primary key must be emitted exactly once, got:\n$output"
        );

        // The real assertion: PostgreSQL accepts what we generated.
        $db2 = $this->connectTo($this->db2);
        $db2->exec($this->stripMigrationMarkers($output));

        $exists = $db2->query(
            "SELECT count(*) FROM information_schema.tables
              WHERE table_schema = 'public' AND table_name = 'replay_target'"
        )->fetchColumn();
        $this->assertEquals(1, $exists, 'Replayed migration should create the table');
    }

    /**
     * A serial column carries DEFAULT nextval('<table>_<col>_seq'), but that
     * sequence is owned by the table and was never emitted by the migration,
     * so replaying the CREATE TABLE failed with:
     *
     *   ERROR: relation "books_id_seq" does not exist
     *
     * Writing the column back as serial re-creates the owned sequence
     * implicitly. Asserting on the resulting default (rather than the DDL text)
     * proves the column round-trips to the same thing it started as.
     */
    public function testGeneratedSerialColumnIsValidSql(): void
    {
        $this->connectTo($this->db1)->exec(
            'CREATE TABLE serial_target (
                id     serial PRIMARY KEY,
                big    bigserial,
                small  smallserial,
                title  text
            )'
        );

        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=schema', '--include=up', '--nocomments', $this->dbInputArg()]
        ));

        $db2 = $this->connectTo($this->db2);
        $db2->exec($this->stripMigrationMarkers($output));

        $defaults = $db2->query(
            "SELECT column_name, column_default FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'serial_target'
                AND column_name IN ('id','big','small')
              ORDER BY column_name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(
            [
                'big'   => "nextval('serial_target_big_seq'::regclass)",
                'id'    => "nextval('serial_target_id_seq'::regclass)",
                'small' => "nextval('serial_target_small_seq'::regclass)",
            ],
            $defaults,
            'Serial columns must round-trip to their own sequence'
        );
    }

    /** Connect to one of the scratch databases with exceptions enabled. */
    private function connectTo(string $dbName): PDO
    {
        $pdo = new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname={$dbName}",
            $this->user,
            $this->pass
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * Drop the "#---------- UP ----------" banners so the migration body can be
     * executed as-is. Both marker styles appear depending on --nocomments.
     */
    private function stripMigrationMarkers(string $sql): string
    {
        return preg_replace('/^\s*(#|--).*$/m', '', $sql);
    }

    protected function tearDownDatabases(): void
    {
        if (!$this->adminDb) {
            return;
        }
        // Terminate active connections before dropping
        foreach ([$this->db1, $this->db2] as $db) {
            $this->adminDb->exec(
                "SELECT pg_terminate_backend(pid) " .
                "FROM pg_stat_activity " .
                "WHERE datname = '$db' AND pid <> pg_backend_pid()"
            );
            $this->adminDb->exec("DROP DATABASE IF EXISTS $db");
        }
    }
}
