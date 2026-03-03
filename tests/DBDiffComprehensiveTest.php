<?php

require_once __DIR__ . '/AbstractComprehensiveTest.php';

/**
 * MySQL implementation of the comprehensive test suite.
 * All test methods live in AbstractComprehensiveTest.
 */
class DBDiffComprehensiveTest extends AbstractComprehensiveTest
{
    // MySQL connection details
    private $host;
    private $port = 3306;
    private $user = 'root';
    private $pass = 'rootpass';
    private $db;
    private $mysqlMajorVersion;

    protected function connectAndBootstrap(): void
    {
        $this->host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? 'db'));
        $this->db1  = 'dbdiff_test1';
        $this->db2  = 'dbdiff_test2';

        $this->db = $this->connectWithRetry(
            "mysql:host={$this->host};port={$this->port}",
            $this->user,
            $this->pass
        );

        $version                 = $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->mysqlMajorVersion = (int) explode('.', $version)[0];

        $this->db->exec("DROP DATABASE IF EXISTS `{$this->db1}`;");
        $this->db->exec("DROP DATABASE IF EXISTS `{$this->db2}`;");
        $this->db->exec("CREATE DATABASE `{$this->db1}`;");
        $this->db->exec("CREATE DATABASE `{$this->db2}`;");
    }

    protected function getVersionSuffix(): string
    {
        return (string) $this->mysqlMajorVersion;
    }

    protected function loadFixture(string $fixtureName): void
    {
        $db1File = "tests/fixtures/{$fixtureName}/db1.sql";
        $db2File = "tests/fixtures/{$fixtureName}/db2.sql";

        if (!file_exists($db1File) || !file_exists($db2File)) {
            $this->fail("Fixture files not found for: $fixtureName");
        }

        $this->db->exec("USE `{$this->db1}`");
        $this->db->exec(file_get_contents($db1File));

        $this->db->exec("USE `{$this->db2}`");
        $this->db->exec(file_get_contents($db2File));
    }

    protected function driverArgs(): array
    {
        return ["--server1={$this->user}:{$this->pass}@{$this->host}:{$this->port}"];
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

    protected function tearDownDatabases(): void
    {
        if ($this->db) {
            $this->db->exec("DROP DATABASE IF EXISTS `{$this->db1}`;");
            $this->db->exec("DROP DATABASE IF EXISTS `{$this->db2}`;");
        }
    }
}

