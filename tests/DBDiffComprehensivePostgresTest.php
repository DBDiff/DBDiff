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
