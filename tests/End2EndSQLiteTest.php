<?php

/**
 * End-to-End Test for DBDiff — SQLite driver
 *
 * SQLite is file-based so this test requires no Docker service and runs
 * inside any container that has pdo_sqlite loaded (all containers built
 * from the updated Dockerfile will have it).
 *
 * Skips automatically when pdo_sqlite is not available.
 *
 * File paths must contain no dots (other than the leading server1./path
 * separator parsed by CLIGetter) so we use extension-free temp paths.
 *
 * Expected output files follow the naming convention:
 *   tests/end2end/migration_expected_sqlite
 *
 * To create / refresh the baseline run:
 *   DBDIFF_RECORD_MODE=true vendor/bin/phpunit --filter End2EndSQLiteTest
 */
class End2EndSQLiteTest extends PHPUnit\Framework\TestCase
{
    // Temp SQLite database file paths — NO dots in the filename (see parseInput)
    private $srcFile = '/tmp/dbdiff_e2e_src';
    private $tgtFile = '/tmp/dbdiff_e2e_tgt';

    private $migration_actual   = 'migration_actual_sqlite';
    private $migration_expected = 'migration_expected_sqlite';

    protected function setUp(): void
    {
        // Guard: SQLite PDO extension required
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension not loaded — skipping SQLite end-to-end tests.');
        }

        // Clean up any leftover temp files from a previous run
        foreach ([$this->srcFile, $this->tgtFile] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }

        // Create source database and apply fixture
        $this->applyFixture($this->srcFile, 'tests/end2end/db1-up-sqlite.sql');

        // Create target database and apply fixture
        $this->applyFixture($this->tgtFile, 'tests/end2end/db2-up-sqlite.sql');
    }

    /**
     * Create a SQLite database at the given path and execute the SQL fixture.
     */
    private function applyFixture(string $dbPath, string $sqlFile): void
    {
        $conn = new PDO("sqlite:$dbPath");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Strip all comment lines from the SQL before splitting on ';'.
        // This avoids the problem where the first split-chunk starts with
        // a comment header and the whole CREATE TABLE gets discarded.
        $sql = file_get_contents($sqlFile);
        $sql = implode("\n", array_filter(
            explode("\n", $sql),
            fn($line) => !preg_match('/^\s*--/', $line)
        ));

        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== ''
        );
        foreach ($statements as $stmt) {
            $conn->exec($stmt);
        }
    }

    public function testAll(): void
    {
        // DBDiff CLI args for SQLite:
        // • No --server1 / --server2 required; the DB path is embedded in the
        //   comparison argument as the "db" component (everything after the first dot).
        // • Paths MUST be free of dots to avoid splitting issues in parseInput().
        $GLOBALS['argv'] = [
            '',
            '--driver=sqlite',
            '--template=templates/simple-db-migrate.tmpl',
            '--type=all',
            '--include=all',
            '--nocomments',
            "--output=./tests/end2end/{$this->migration_actual}",
            "server1.{$this->srcFile}:server2.{$this->tgtFile}",
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
            echo "\n📝 Recorded expected output for End2EndSQLiteTest ({$this->migration_expected})\n";
            $this->addToAssertionCount(1);
        } else {
            if (!file_exists($expectedFilePath)) {
                $this->markTestSkipped(
                    "No baseline file found: $expectedFilePath — "
                    . 'run with DBDIFF_RECORD_MODE=true to create it.'
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
        // Remove SQLite database files
        foreach ([$this->srcFile, $this->tgtFile] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }

        // Remove output file
        $actualFile = "./tests/end2end/{$this->migration_actual}";
        if (file_exists($actualFile)) {
            unlink($actualFile);
        }
    }
}
