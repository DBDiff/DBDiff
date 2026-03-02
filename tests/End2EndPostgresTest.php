<?php

/**
 * End-to-End Test for DBDiff — PostgreSQL driver
 *
 * Skips automatically when:
 *   - pdo_pgsql PHP extension is not loaded
 *   - DB_HOST_POSTGRES environment variable is not set (i.e. not running in a
 *     Postgres-enabled Docker CLI container)
 *
 * Expected output files follow the naming convention:
 *   tests/end2end/migration_expected_pgsql_<major>
 *
 * To create / refresh the baseline run:
 *   DBDIFF_RECORD_MODE=true vendor/bin/phpunit --filter End2EndPostgresTest
 */
class End2EndPostgresTest extends PHPUnit\Framework\TestCase
{
    private $host;
    private $port     = 5432;
    private $user     = 'dbdiff';
    private $pass     = 'rootpass';
    private $db1      = 'diff1pgsql';
    private $db2      = 'diff2pgsql';
    private $defaultDb = 'diff1'; // database created by POSTGRES_DB in compose

    private $migration_actual   = 'migration_actual_pgsql';
    private $migration_expected = 'migration_expected_pgsql';

    /** @var \PDO Connection to the default database (used for CREATE/DROP DATABASE) */
    private $adminDb;

    protected function setUp(): void
    {
        // 1. Guard: PostgreSQL PDO extension required
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not loaded — skipping PostgreSQL end-to-end tests.');
        }

        // 2. Guard: only run inside a Postgres-enabled Docker CLI container
        $this->host = getenv('DB_HOST_POSTGRES') ?: null;
        if (!$this->host) {
            $this->markTestSkipped('DB_HOST_POSTGRES env var not set — skipping PostgreSQL end-to-end tests.');
        }

        $debug = getenv('DBDIFF_DEBUG') === 'true';

        if ($debug) {
            echo "\nDEBUG: Postgres host: {$this->host}:{$this->port}\n";
        }

        // 3. Connect to the default DB (to create test databases)
        $maxRetries = 3;
        $retryDelay = 2;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->adminDb = new PDO(
                    "pgsql:host={$this->host};port={$this->port};dbname={$this->defaultDb}",
                    $this->user,
                    $this->pass
                );
                $this->adminDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                if ($debug) {
                    echo "\nConnected to Postgres on attempt $attempt\n";
                }
                break;
            } catch (PDOException $e) {
                if ($attempt === $maxRetries) {
                    $this->fail("Failed to connect to Postgres after $maxRetries attempts: " . $e->getMessage());
                }
                sleep($retryDelay);
            }
        }

        // 4. Detect Postgres major version for versioned expected files
        $versionRow = $this->adminDb->query("SELECT current_setting('server_version_num') AS v")->fetch(PDO::FETCH_ASSOC);
        $versionNum   = (int) ($versionRow['v'] ?? 0);
        $majorVersion = (string) intdiv($versionNum, 10000);

        $this->migration_expected .= '_' . $majorVersion;
        $this->migration_actual   .= '_' . $majorVersion;

        if ($debug) {
            echo "\nPostgres server_version_num: $versionNum  (major: $majorVersion)\n";
        }

        // 5. Drop and recreate test databases
        // CREATE DATABASE cannot run inside a transaction; set autocommit explicitly.
        $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db1}");
        $this->adminDb->exec("DROP DATABASE IF EXISTS {$this->db2}");
        $this->adminDb->exec("CREATE DATABASE {$this->db1}");
        $this->adminDb->exec("CREATE DATABASE {$this->db2}");

        // 6. Apply fixtures — each database needs its own connection
        $this->applyFixture($this->db1, 'tests/end2end/db1-up-pgsql.sql');
        $this->applyFixture($this->db2, 'tests/end2end/db2-up-pgsql.sql');
    }

    /**
     * Connect to a specific Postgres database and execute the given SQL file.
     */
    private function applyFixture(string $dbName, string $filePath): void
    {
        $conn = new PDO(
            "pgsql:host={$this->host};port={$this->port};dbname={$dbName}",
            $this->user,
            $this->pass
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->exec(file_get_contents($filePath));
    }

    public function testAll(): void
    {
        // Simulate CLI arguments passed to DBDiff
        $GLOBALS['argv'] = [
            '',
            "--driver=pgsql",
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
            '--template=templates/simple-db-migrate.tmpl',
            '--type=all',
            '--include=all',
            '--nocomments',
            "--output=./tests/end2end/{$this->migration_actual}",
            "server1.{$this->db1}:server1.{$this->db2}",
        ];

        ob_start();
        try {
            $dbdiff = new DBDiff\DBDiff;
            $dbdiff->run();
        } finally {
            ob_end_clean();
        }

        $actualContent    = file_get_contents("./tests/end2end/{$this->migration_actual}");
        $expectedFilePath = "./tests/end2end/{$this->migration_expected}";

        if (($_ENV['DBDIFF_RECORD_MODE'] ?? 'false') === 'true') {
            file_put_contents($expectedFilePath, $actualContent);
            echo "\n📝 Recorded expected output for End2EndPostgresTest ({$this->migration_expected})\n";
            $this->addToAssertionCount(1);
        } else {
            if (!file_exists($expectedFilePath)) {
                $this->fail(
                    "Expected output file not found: $expectedFilePath\n" .
                    "Run with DBDIFF_RECORD_MODE=true to create it."
                );
            }
            $this->assertEquals(
                trim(file_get_contents($expectedFilePath)),
                trim($actualContent)
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->adminDb) {
            // Terminate connections before dropping databases
            foreach ([$this->db1, $this->db2] as $db) {
                $this->adminDb->exec(
                    "SELECT pg_terminate_backend(pid)
                       FROM pg_stat_activity
                      WHERE datname = '$db' AND pid <> pg_backend_pid()"
                );
                $this->adminDb->exec("DROP DATABASE IF EXISTS {$db}");
            }
        }

        $actualFile = "./tests/end2end/{$this->migration_actual}";
        if (file_exists($actualFile)) {
            unlink($actualFile);
        }
    }
}
