<?php

/**
 * AbstractComprehensiveTest
 *
 * Shared test suite for DBDiff covering MySQL, PostgreSQL, and SQLite.
 * All test methods, assertions, and helpers live here.  Subclasses supply
 * only the driver-specific plumbing: how to connect, how to load fixtures,
 * and what CLI arguments to pass.
 *
 * To add a new driver:
 *   1. Create a subclass that implements every abstract method.
 *   2. Add driver-specific fixture files under tests/fixtures/{name}/db{1,2}-{driver}.sql
 *   3. Run DBDIFF_RECORD_MODE=true once to commit the baselines.
 */
abstract class AbstractComprehensiveTest extends PHPUnit\Framework\TestCase
{
    // Set by connectAndBootstrap() ─────────────────────────────────────────
    protected $db1;        // source DB name / file path
    protected $db2;        // target DB name / file path
    protected $recordMode = false;

    // ── PHPUnit lifecycle ─────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->recordMode = ($_ENV['DBDIFF_RECORD_MODE'] ?? 'false') === 'true';
        if ($this->recordMode) {
            echo "\n🎬 RECORD MODE ENABLED — actual output will be saved as the expected baseline\n";
        }
        $this->connectAndBootstrap();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabases();
        $this->cleanupOutputFiles();
    }

    // ── Abstract: driver-specific wiring ──────────────────────────────────

    /**
     * Connect to the backend (or set up SQLite files), create test schemas,
     * and populate $this->db1 / $this->db2.
     * Call $this->markTestSkipped() here when the required service or
     * extension is unavailable.
     */
    abstract protected function connectAndBootstrap(): void;

    /**
     * Suffix appended to expected-output filenames.
     * Examples: '8' (MySQL 8), 'pgsql_16' (Postgres 16), 'sqlite'.
     */
    abstract protected function getVersionSuffix(): string;

    /**
     * Load the named fixture set into db1 and db2.
     * Fixture files live at tests/fixtures/{fixtureName}/db{1,2}[-driver].sql
     */
    abstract protected function loadFixture(string $fixtureName): void;

    /**
     * Driver-specific CLI flags prepended to every runDBDiff() call.
     * E.g. ['--driver=pgsql', '--server1=user:pass@host:5432']
     * or   ['--server1=root:pass@host:3306']  (MySQL, driver implicit)
     * or   ['--driver=sqlite']
     */
    abstract protected function driverArgs(): array;

    /**
     * Input arg for a DB-level diff.
     * E.g. 'server1.db1:server1.db2'   (MySQL / Postgres)
     *   or 'server1./tmp/src:server2./tmp/tgt'  (SQLite)
     */
    abstract protected function dbInputArg(): string;

    /**
     * Input arg for a single-table diff, or null to skip testSingleTableDiff.
     * SQLite paths containing '/' cannot be used in the dot-notation parsed
     * by CLIGetter::parseInput(), so SQLite subclasses return null.
     */
    abstract protected function tableInputArg(string $table): ?string;

    /**
     * Server credentials to embed into generated YAML config files.
     * Return [] for SQLite (no server block needed).
     */
    abstract protected function getServerConfig(): array;

    /** Drop / delete the test databases or files created by connectAndBootstrap(). */
    abstract protected function tearDownDatabases(): void;

    // ── Overrideable hooks ────────────────────────────────────────────────

    /**
     * Top-level keys added to every generated YAML config file.
     * Override to add e.g. 'driver: pgsql' or 'driver: sqlite'.
     */
    protected function configDefaults(): array
    {
        return ['type' => 'all', 'include' => 'all', 'nocomments' => true];
    }

    // ── Test suite ────────────────────────────────────────────────────────

    public function testSchemaOnlyDiff(): void
    {
        $this->loadFixture('basic_schema_data');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=schema', '--include=all', '--nocomments', $this->dbInputArg()]
        ));
        $this->assertExpectedOutput('schema_only', $output);
        $this->assertStringNotContainsString('INSERT INTO ', $output);
        $this->assertStringNotContainsString('DELETE FROM ', $output);
        $this->assertDoesNotMatchRegularExpression('/^UPDATE\s+/m', $output);
        $this->assertStringContainsString('ALTER TABLE', $output);
    }

    public function testDataOnlyDiff(): void
    {
        $this->loadFixture('basic_schema_data');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=data', '--include=all', '--nocomments', $this->dbInputArg()]
        ));
        $this->assertExpectedOutput('data_only', $output);
        $this->assertStringNotContainsString('ALTER TABLE', $output);
        $this->assertStringNotContainsString('CREATE TABLE', $output);
        $this->assertStringNotContainsString('DROP TABLE', $output);
        if (!empty(trim($output))) {
            $this->assertTrue(
                strpos($output, 'INSERT INTO') !== false ||
                strpos($output, 'DELETE FROM') !== false ||
                strpos($output, 'UPDATE') !== false,
                'Data diff should contain data operations'
            );
        }
    }

    public function testTemplateOutput(): void
    {
        $this->loadFixture('basic_schema_data');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            [
                '--template=templates/simple-db-migrate.tmpl',
                '--type=all', '--include=all', '--nocomments',
                $this->dbInputArg(),
            ]
        ));
        $this->assertExpectedOutput('template_output', $output);
        $this->assertStringContainsString('SQL_UP = u"""', $output);
        $this->assertStringContainsString('SQL_DOWN = u"""', $output);
    }

    public function testUpOnlyOutput(): void
    {
        $this->loadFixture('basic_schema_data');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=all', '--include=up', '--nocomments', $this->dbInputArg()]
        ));
        $this->assertExpectedOutput('up_only', $output);
        $this->assertNotEmpty(trim($output), 'UP migration should not be empty when there are differences');
    }

    public function testDownOnlyOutput(): void
    {
        $this->loadFixture('basic_schema_data');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=all', '--include=down', '--nocomments', $this->dbInputArg()]
        ));
        $this->assertExpectedOutput('down_only', $output);
        $this->assertNotEmpty(trim($output), 'DOWN migration should not be empty when there are differences');
    }

    public function testSingleTableDiff(): void
    {
        $input = $this->tableInputArg('users');
        if ($input === null) {
            $this->markTestSkipped('Single-table diff is not supported for this driver.');
        }
        $this->loadFixture('multi_table');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=all', '--include=all', '--nocomments', $input]
        ));
        $this->assertExpectedOutput('single_table', $output);
        if (!empty(trim($output))) {
            $this->assertStringContainsString('users', $output);
            $this->assertStringNotContainsString('posts', $output);
            $this->assertStringNotContainsString('categories', $output);
        }
    }

    public function testConfigFileUsage(): void
    {
        $this->loadFixture('basic_schema_data');
        $this->createTestConfig('basic_config.yaml');
        $output = $this->runDBDiff([
            '--config=tests/config/basic_config.yaml',
            $this->dbInputArg(),
        ]);
        $this->assertExpectedOutput('config_file', $output);
    }

    public function testConfigFileWithCliOverride(): void
    {
        $this->loadFixture('basic_schema_data');
        $this->createTestConfig('cli_override_config.yaml', [
            'type'    => 'schema',
            'include' => 'up',
        ]);
        $output = $this->runDBDiff([
            '--config=tests/config/cli_override_config.yaml',
            '--type=data',
            '--include=down',
            $this->dbInputArg(),
        ]);
        $this->assertExpectedOutput('config_cli_override', $output);
        // CLI --type=data overrides config type=schema → no schema ops
        $this->assertStringNotContainsString('ALTER TABLE', $output);
    }

    public function testTablesToIgnore(): void
    {
        $this->loadFixture('multi_table');
        $this->createTestConfig('tables_ignore_config.yaml', [
            'tablesToIgnore' => ['posts', 'categories'],
        ]);
        $output = $this->runDBDiff([
            '--config=tests/config/tables_ignore_config.yaml',
            '--type=all', '--include=all', '--nocomments',
            $this->dbInputArg(),
        ]);
        $this->assertExpectedOutput('tables_ignore', $output);
        if (!empty(trim($output))) {
            $this->assertStringContainsString('users', $output);
            $this->assertStringNotContainsString('posts', $output);
            $this->assertStringNotContainsString('categories', $output);
        }
    }

    public function testFieldsToIgnore(): void
    {
        $this->loadFixture('single_table');
        $this->createTestConfig('fields_ignore_config.yaml', [
            'fieldsToIgnore' => [
                'test_table' => ['ignored_field', 'another_ignored_field'],
            ],
        ]);
        $output = $this->runDBDiff([
            '--config=tests/config/fields_ignore_config.yaml',
            '--type=schema', '--include=all', '--nocomments',
            $this->dbInputArg(),
        ]);
        $this->assertExpectedOutput('fields_ignore', $output);
        $this->assertStringNotContainsString('ignored_field', $output);
        $this->assertStringNotContainsString('another_ignored_field', $output);
    }

    /**
     * Bug #6 regression: views must never appear in the diff output.
     *
     * The source DB contains a VIEW `active_products` alongside a `products`
     * table.  After the fix, `getTables()` excludes views so only the real
     * schema differences between the `products` tables are emitted — no
     * DROP TABLE / CREATE TABLE for the view.
     */
    public function testViewsExcludedFromDiff(): void
    {
        $this->loadFixture('views_ignored');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=schema', '--include=all', '--nocomments', $this->dbInputArg()]
        ));

        // Core regression: the view name must never appear in any generated SQL.
        $this->assertStringNotContainsString(
            'active_products',
            $output,
            'Bug #6 regression: views must not appear in the diff output'
        );
        // The real table difference (price column) should still be detected.
        $this->assertStringContainsString(
            'products',
            $output,
            'The schema diff for the base table must still be generated'
        );
    }

    /**
     * Bug #7 regression: rows containing NULL column values must be detected
     * during data diff and appear in the output as UPDATE statements.
     *
     * Before the fix, MySQL's CONCAT() returned NULL for any row with a NULL
     * column, causing the MD5 hash to be NULL for all such rows — making any
     * difference in those rows invisible.
     */
    public function testNullableColumnDataDetected(): void
    {
        $this->loadFixture('nullable_data');
        $output = $this->runDBDiff(array_merge(
            $this->driverArgs(),
            ['--type=data', '--include=all', '--nocomments', $this->dbInputArg()]
        ));

        // Core regression: NULL↔value changes must produce UPDATE statements.
        $this->assertNotEmpty(
            trim($output),
            'Bug #7 regression: data diff with NULL column changes must not be empty'
        );
        $this->assertStringContainsString(
            'UPDATE',
            $output,
            'Bug #7 regression: NULL↔value column changes must produce UPDATE statements'
        );
        // Row 3 has description=NULL in both source and target — must not be in diff.
        $this->assertStringNotContainsString(
            "'3'",
            $output,
            'Bug #7 regression: row 3 has identical NULLs in source and target — must not appear in diff'
        );
    }

    // ── Shared helpers ────────────────────────────────────────────────────

    protected function runDBDiff(array $args): string
    {
        $outputFile      = tempnam(sys_get_temp_dir(), 'dbdiff_test_');
        $args[]          = "--output=$outputFile";
        $GLOBALS['argv'] = array_merge([''], $args);

        ob_start();
        try {
            $dbdiff = new DBDiff\DBDiff;
            $dbdiff->run();
        } finally {
            ob_end_clean();
        }

        $output = file_exists($outputFile) ? file_get_contents($outputFile) : '';
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        return $output;
    }

    protected function assertExpectedOutput(string $testName, string $actualOutput): void
    {
        $suffix       = $this->getVersionSuffix();
        $expectedFile = "tests/expected/{$testName}_{$suffix}.txt";

        if ($this->recordMode) {
            file_put_contents($expectedFile, $actualOutput);
            echo "\n📝 Recorded: {$testName}_{$suffix}\n";
            $this->addToAssertionCount(1);
            return;
        }

        if (!file_exists($expectedFile)) {
            $this->fail(
                "Expected file not found: $expectedFile. " .
                'Run with DBDIFF_RECORD_MODE=true to create it.'
            );
        }
        $this->assertEquals(
            trim(file_get_contents($expectedFile)),
            trim($actualOutput),
            "Output mismatch for: $testName ($suffix)"
        );
    }

    /**
     * Generate a YAML config file at tests/config/{filename}.
     *
     * Server1/server2 blocks are omitted when getServerConfig() returns [].
     * Driver and base options come from configDefaults().
     */
    protected function createTestConfig(string $filename, array $overrides = []): void
    {
        $serverConfig = $this->getServerConfig();
        $base         = $this->configDefaults();

        // Prepend server blocks only when the driver needs them (not SQLite)
        if (!empty($serverConfig)) {
            $base = array_merge(
                ['server1' => $serverConfig, 'server2' => $serverConfig],
                $base
            );
        }

        $config = array_merge($base, $overrides);
        file_put_contents("tests/config/$filename", $this->arrayToYaml($config));
    }

    /**
     * PDO connection with retry logic.
     * Subclasses call this from connectAndBootstrap() instead of rolling
     * their own retry loop.
     */
    protected function connectWithRetry(string $dsn, string $user, string $pass): PDO
    {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $pdo = new PDO($dsn, $user, $pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $pdo;
            } catch (PDOException $e) {
                if ($attempt === $maxRetries) {
                    $this->fail(
                        "Failed to connect ($dsn) after $maxRetries attempts: " .
                        $e->getMessage()
                    );
                }
                sleep(2);
            }
        }
        // Unreachable but satisfies static analysers.
        throw new \RuntimeException('connectWithRetry: unexpected exit');
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function arrayToYaml(array $data, int $indent = 0): string
    {
        $yaml   = '';
        $spaces = str_repeat('  ', $indent);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $yaml .= $spaces . $key . ":\n";
                if (array_keys($value) === range(0, count($value) - 1)) {
                    foreach ($value as $item) {
                        $yaml .= $spaces . '  - ' . $this->yamlValue($item) . "\n";
                    }
                } else {
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                }
            } else {
                $yaml .= $spaces . $key . ': ' . $this->yamlValue($value) . "\n";
            }
        }
        return $yaml;
    }

    private function yamlValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) && (strpos($value, ' ') !== false || $value === '')) {
            return '"' . $value . '"';
        }
        return (string) $value;
    }

    private function cleanupOutputFiles(): void
    {
        foreach (['tests/end2end/migration_actual*', 'tests/config/*.yaml', '/tmp/dbdiff_test_*'] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
