<?php

require_once __DIR__ . '/AbstractComprehensiveTest.php';

/**
 * SQLite implementation of the comprehensive test suite.
 * All test methods live in AbstractComprehensiveTest.
 *
 * Skips automatically when pdo_sqlite is not loaded.
 * No external service required; databases are temporary files in /tmp.
 *
 * Note: testSingleTableDiff is skipped because SQLite file paths contain
 * slashes that break CLIGetter::parseInput()'s dot-notation parser.
 */
class DBDiffComprehensiveSQLiteTest extends AbstractComprehensiveTest
{
    // ── Abstract method implementations ───────────────────────────────────

    protected function connectAndBootstrap(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension not loaded — skipping SQLite comprehensive tests.');
        }

        // Paths MUST be dot-free (no extension) — parseInput() splits on '.'
        // so 'server1./tmp/file.db' would be mis-parsed as a 3-part table input.
        $this->db1 = '/tmp/dbdiff_comp_src';
        $this->db2 = '/tmp/dbdiff_comp_tgt';

        // Start with fresh files
        foreach ([$this->db1, $this->db2] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    protected function getVersionSuffix(): string
    {
        return 'sqlite';
    }

    protected function loadFixture(string $fixtureName): void
    {
        foreach (['db1' => $this->db1, 'db2' => $this->db2] as $key => $filePath) {
            $sqlFile = "tests/fixtures/{$fixtureName}/{$key}-sqlite.sql";
            if (!file_exists($sqlFile)) {
                $this->fail("SQLite fixture not found: $sqlFile");
            }

            $pdo = new PDO("sqlite:$filePath");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Strip SQL comments and split on semicolons for statement-by-statement execution
            $raw        = file_get_contents($sqlFile);
            $statements = $this->parseSqlStatements($raw);
            foreach ($statements as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }
        }
    }

    protected function driverArgs(): array
    {
        return ['--driver=sqlite'];
    }

    protected function dbInputArg(): string
    {
        // SQLite uses separate server handles whose "db" is the file path.
        // The server handle name is arbitrary; ParamsFactory ignores it for SQLite.
        return "server1.{$this->db1}:server2.{$this->db2}";
    }

    protected function tableInputArg(string $table): ?string
    {
        // File paths with '/' cannot be used in dot-notation server.db.table format.
        return null;
    }

    protected function getServerConfig(): array
    {
        // SQLite needs no server credentials; omit server1/server2 from config files.
        return [];
    }

    protected function configDefaults(): array
    {
        return [
            'driver'     => 'sqlite',
            'type'       => 'all',
            'include'    => 'all',
            'nocomments' => true,
        ];
    }

    protected function tearDownDatabases(): void
    {
        foreach ([$this->db1, $this->db2] as $file) {
            if ($file && file_exists($file)) {
                unlink($file);
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Strip line comments and split SQL text into individual statements.
     * Matches the approach used in End2EndSQLiteTest.
     */
    private function parseSqlStatements(string $sql): array
    {
        $lines    = explode("\n", $sql);
        $stripped = [];
        foreach ($lines as $line) {
            $clean = preg_replace('/--.*$/', '', $line);
            if (trim($clean) !== '') {
                $stripped[] = $clean;
            }
        }

        // Reassemble, then split respecting BEGIN…END blocks
        $body       = implode("\n", $stripped);
        $statements = [];
        $current    = '';
        $depth      = 0;

        foreach (preg_split('/;/', $body) as $fragment) {
            $current .= ($current !== '' ? ';' : '') . $fragment;
            $depth   += preg_match_all('/\bBEGIN\b/i', $fragment);
            $depth   -= preg_match_all('/\bEND\b/i', $fragment);
            if ($depth <= 0) {
                if (trim($current) !== '') {
                    $statements[] = $current;
                }
                $current = '';
                $depth   = 0;
            }
        }
        if (trim($current) !== '') {
            $statements[] = $current;
        }
        return $statements;
    }
}
