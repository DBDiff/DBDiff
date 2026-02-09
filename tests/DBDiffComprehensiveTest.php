<?php

use PHPUnit\Framework\TestCase;

class DBDiffComprehensiveTest extends TestCase
{
    // Database configuration
    private $host;
    private $port = 3306;
    private $user = "root";
    private $pass = "rootpass";
    private $db1 = "dbdiff_test1";
    private $db2 = "dbdiff_test2";
    private $db;
    private $mysqlMajorVersion;
    private $recordMode = false; // Set to true to record new expected outputs

    protected function setUp(): void
    {
        $this->host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? 'db'));
        
        $debug = getenv('DBDIFF_DEBUG') === 'true';
        
        if ($debug) {
            echo "\nDEBUG [Comprehensive]: DB_HOST environment variable: " . (getenv('DB_HOST') ?: 'NOT_SET');
            echo "\nDEBUG [Comprehensive]: Using database host: " . $this->host . "\n";
        }
        
        // Check if we're in record mode (via environment variable)
        $this->recordMode = ($_ENV['DBDIFF_RECORD_MODE'] ?? 'false') === 'true';
        
        if ($this->recordMode) {
            echo "\n🎬 RECORD MODE ENABLED - Will capture actual output as expected results\n";
        }

        // Connect to database with retry logic
        $maxRetries = 3;
        $retryDelay = 2;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->db = new PDO("mysql:host={$this->host};port={$this->port}", $this->user, $this->pass);
                break;
            } catch (PDOException $e) {
                if ($attempt === $maxRetries) {
                    $this->fail("Failed to connect to database after $maxRetries attempts: " . $e->getMessage());
                }
                sleep($retryDelay);
            }
        }

        // Get MySQL major version for version-specific expectations
        $version = $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->mysqlMajorVersion = explode(".", $version)[0];

        // Clean up and create test databases
        $this->db->exec("DROP DATABASE IF EXISTS `{$this->db1}`;");
        $this->db->exec("DROP DATABASE IF EXISTS `{$this->db2}`;");
        $this->db->exec("CREATE DATABASE `{$this->db1}`;");
        $this->db->exec("CREATE DATABASE `{$this->db2}`;");
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            // Clean up test databases
            $this->db->exec("DROP DATABASE IF EXISTS `{$this->db1}`;");
            $this->db->exec("DROP DATABASE IF EXISTS `{$this->db2}`;");
        }
        
        // Clean up any output files
        $this->cleanupOutputFiles();
    }

    /**
     * Test schema-only diff (--type=schema)
     */
    public function testSchemaOnlyDiff()
    {
        $this->loadFixture('basic_schema_data');
        
        $output = $this->runDBDiff([
            '--type=schema',
            '--include=all',
            '--nocomments',
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('schema_only', $output);
        
        // Verify it doesn't contain data operations
        $this->assertStringNotContainsString('INSERT INTO ', $output);
        $this->assertStringNotContainsString('DELETE FROM ', $output);
        // Use regex for UPDATE to avoid matching 'ON UPDATE' in schema
        $this->assertDoesNotMatchRegularExpression('/^UPDATE\s+/m', $output);
        
        // Verify it contains schema operations
        $this->assertStringContainsString('ALTER TABLE', $output);
    }

    /**
     * Test data-only diff (--type=data)
     */
    public function testDataOnlyDiff()
    {
        $this->loadFixture('basic_schema_data');
        
        $output = $this->runDBDiff([
            '--type=data',
            '--include=all',
            '--nocomments',
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('data_only', $output);
        
        // Verify it doesn't contain schema operations
        $this->assertStringNotContainsString('ALTER TABLE', $output);
        $this->assertStringNotContainsString('CREATE TABLE', $output);
        $this->assertStringNotContainsString('DROP TABLE', $output);
        
        // Verify it contains data operations (if there are differences)
        if (!empty(trim($output))) {
            $this->assertTrue(
                strpos($output, 'INSERT INTO') !== false ||
                strpos($output, 'DELETE FROM') !== false ||
                strpos($output, 'UPDATE') !== false,
                'Data diff should contain data operations'
            );
        }
    }

    /**
     * Test template output (--template=templates/simple-db-migrate.tmpl)
     */
    public function testTemplateOutput()
    {
        $this->loadFixture('basic_schema_data');
        
        $output = $this->runDBDiff([
            '--template=templates/simple-db-migrate.tmpl',
            '--type=all',
            '--include=all',
            '--nocomments',
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('template_output', $output);
        
        // Verify template format
        $this->assertStringContainsString('SQL_UP = u"""', $output);
        $this->assertStringContainsString('SQL_DOWN = u"""', $output);
    }

    /**
     * Test UP-only output (--include=up)
     */
    public function testUpOnlyOutput()
    {
        $this->loadFixture('basic_schema_data');
        
        $output = $this->runDBDiff([
            '--type=all',
            '--include=up',
            '--nocomments',
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('up_only', $output);
        
        // Should not be empty if there are differences
        $this->assertNotEmpty(trim($output), 'UP migration should not be empty when there are differences');
    }

    /**
     * Test DOWN-only output (--include=down)
     */
    public function testDownOnlyOutput()
    {
        $this->loadFixture('basic_schema_data');
        
        $output = $this->runDBDiff([
            '--type=all',
            '--include=down',
            '--nocomments',
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('down_only', $output);
        
        // Should not be empty if there are differences
        $this->assertNotEmpty(trim($output), 'DOWN migration should not be empty when there are differences');
    }

    /**
     * Test single table diff (server1.db1.table1:server2.db2.table1)
     */
    public function testSingleTableDiff()
    {
        $this->loadFixture('multi_table');
        
        $output = $this->runDBDiff([
            '--type=all',
            '--include=all',
            '--nocomments',
            "--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}",
            "server1.{$this->db1}.users:server1.{$this->db2}.users"
        ]);
        
        $this->assertExpectedOutput('single_table', $output);
        
        // Should only contain operations for the 'users' table
        if (!empty(trim($output))) {
            $this->assertStringContainsString('users', $output);
            $this->assertStringNotContainsString('posts', $output);
            $this->assertStringNotContainsString('categories', $output);
        }
    }

    /**
     * Test config file usage (--config=config.yaml)
     */
    public function testConfigFileUsage()
    {
        $this->loadFixture('basic_schema_data');
        $this->createTestConfig('basic_config.yaml');
        
        $output = $this->runDBDiff([
            '--config=tests/config/basic_config.yaml',
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('config_file', $output);
    }

    /**
     * Test config file with CLI override
     */
    public function testConfigFileWithCliOverride()
    {
        $this->loadFixture('basic_schema_data');
        $this->createTestConfig('cli_override_config.yaml', [
            'type' => 'schema',
            'include' => 'up'
        ]);
        
        // Override config with CLI parameters
        $output = $this->runDBDiff([
            '--config=tests/config/cli_override_config.yaml',
            '--type=data',  // Override config
            '--include=down', // Override config
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('config_cli_override', $output);
        
        // Should be data-only (CLI override) not schema-only (config)
        $this->assertStringNotContainsString('ALTER TABLE', $output);
    }

    /**
     * Test tables to ignore (tablesToIgnore config)
     */
    public function testTablesToIgnore()
    {
        $this->loadFixture('multi_table');
        $this->createTestConfig('tables_ignore_config.yaml', [
            'tablesToIgnore' => ['posts', 'categories']
        ]);
        
        $output = $this->runDBDiff([
            '--config=tests/config/tables_ignore_config.yaml',
            '--type=all',
            '--include=all',
            '--nocomments',
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('tables_ignore', $output);
        
        // Should only contain 'users' table, not 'posts' or 'categories'
        if (!empty(trim($output))) {
            $this->assertStringContainsString('users', $output);
            $this->assertStringNotContainsString('posts', $output);
            $this->assertStringNotContainsString('categories', $output);
        }
    }

    /**
     * Test fields to ignore (fieldsToIgnore config)
     */
    public function testFieldsToIgnore()
    {
        $this->loadFixture('single_table');
        $this->createTestConfig('fields_ignore_config.yaml', [
            'fieldsToIgnore' => [
                'test_table' => ['ignored_field', 'another_ignored_field']
            ]
        ]);
        
        $output = $this->runDBDiff([
            '--config=tests/config/fields_ignore_config.yaml',
            '--type=schema',
            '--include=all',
            '--nocomments',
            "server1.{$this->db1}:server1.{$this->db2}"
        ]);
        
        $this->assertExpectedOutput('fields_ignore', $output);
        
        // Should not contain operations on ignored fields
        $this->assertStringNotContainsString('ignored_field', $output);
        $this->assertStringNotContainsString('another_ignored_field', $output);
    }

    /**
     * Helper method to load database fixtures
     */
    private function loadFixture(string $fixtureName): void
    {
        $db1File = "tests/fixtures/{$fixtureName}/db1.sql";
        $db2File = "tests/fixtures/{$fixtureName}/db2.sql";
        
        if (!file_exists($db1File) || !file_exists($db2File)) {
            $this->fail("Fixture files not found for: $fixtureName");
        }
        
        // Load db1
        $this->db->query("USE `{$this->db1}`");
        $this->db->exec(file_get_contents($db1File));
        
        // Load db2
        $this->db->query("USE `{$this->db2}`");
        $this->db->exec(file_get_contents($db2File));
    }

    /**
     * Helper method to run DBDiff with given arguments
     */
    private function runDBDiff(array $args): string
    {
        // Prepare output file
        $outputFile = tempnam(sys_get_temp_dir(), 'dbdiff_test_');
        $args[] = "--output=$outputFile";
        
        // Set up global argv for DBDiff
        $GLOBALS['argv'] = array_merge([''], $args);
        
        // Capture output
        ob_start();
        try {
            $dbdiff = new DBDiff\DBDiff;
            $dbdiff->run();
        } finally {
            ob_end_clean();
        }
        
        // Read generated file
        $output = file_exists($outputFile) ? file_get_contents($outputFile) : '';
        unlink($outputFile);
        
        return $output;
    }

    /**
     * Helper method to assert expected output
     */
    private function assertExpectedOutput(string $testName, string $actualOutput): void
    {
        $expectedFile = "tests/expected/{$testName}_{$this->mysqlMajorVersion}.txt";
        
        if ($this->recordMode) {
            // Record mode: save actual output as expected
            file_put_contents($expectedFile, $actualOutput);
            echo "\n📝 Recorded expected output for: {$testName}_{$this->mysqlMajorVersion}\n";
            $this->addToAssertionCount(1);
            return;
        }
        
        if (!file_exists($expectedFile)) {
            $this->fail("Expected output file not found: $expectedFile. Run with DBDIFF_RECORD_MODE=true to create it.");
        }
        
        $expectedOutput = trim(file_get_contents($expectedFile));
        $this->assertEquals($expectedOutput, trim($actualOutput), "Output mismatch for test: $testName");
    }

    /**
     * Helper method to create test config files
     */
    private function createTestConfig(string $filename, array $overrides = []): void
    {
        $defaultConfig = [
            'server1' => [
                'user' => $this->user,
                'password' => $this->pass,
                'port' => $this->port,
                'host' => $this->host
            ],
            'server2' => [
                'user' => $this->user,
                'password' => $this->pass,
                'port' => $this->port,
                'host' => $this->host
            ],
            'type' => 'all',
            'include' => 'all',
            'nocomments' => true
        ];
        
        $config = array_merge($defaultConfig, $overrides);
        
        // Convert to YAML format
        $yaml = $this->arrayToYaml($config);
        file_put_contents("tests/config/$filename", $yaml);
    }

    /**
     * Simple YAML converter for config files
     */
    private function arrayToYaml(array $data, int $indent = 0): string
    {
        $yaml = '';
        $spaces = str_repeat('  ', $indent);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $yaml .= $spaces . $key . ":\n";
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // Indexed array (list)
                    foreach ($value as $item) {
                        $yaml .= $spaces . "  - " . $this->yamlValue($item) . "\n";
                    }
                } else {
                    // Associative array
                    $yaml .= $this->arrayToYaml($value, $indent + 1);
                }
            } else {
                $yaml .= $spaces . $key . ': ' . $this->yamlValue($value) . "\n";
            }
        }
        
        return $yaml;
    }

    /**
     * Format YAML value
     */
    private function yamlValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) && (strpos($value, ' ') !== false || empty($value))) {
            return '"' . $value . '"';
        }
        return (string)$value;
    }

    /**
     * Clean up any output files
     */
    private function cleanupOutputFiles(): void
    {
        $patterns = [
            'tests/end2end/migration_actual*',
            'tests/config/*.yaml',
            '/tmp/dbdiff_test_*'
        ];
        
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
