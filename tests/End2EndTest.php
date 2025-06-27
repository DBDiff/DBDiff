<?php

require 'vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

class End2EndTest extends PHPUnit\Framework\TestCase
{
    // db config
    private $host;
    private $port = 3306;
    private $user = "root";
    private $pass = "rootpass";
    private $db1  = "diff1";
    private $db2  = "diff2";
    
    public function __construct()
    {
        parent::__construct();
        // Use environment variable for database host, fallback to 'db'
        $this->host = $_ENV['DB_HOST'] ?? 'db';
        echo "\nDEBUG: DB_HOST environment variable: " . ($_ENV['DB_HOST'] ?? 'NOT_SET');
        echo "\nDEBUG: Using database host: " . $this->host;
        echo "\nDEBUG: Full connection string will be: mysql:host=" . $this->host . ";port=" . $this->port . "\n";
    }
    // migration output expectations
    private $migration_actual = 'migration_actual';
    private $migration_expected = 'migration_expected';
    // db connection
    private $db;

    protected function setUp(): void
    {
        // Retry connection up to 3 times with 2 second delays
        $maxRetries = 3;
        $retryDelay = 2;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->db = new PDO("mysql:host=$this->host;port=$this->port", $this->user, $this->pass);
                echo "\nSuccessfully connected to database on attempt $attempt\n";
                break;
            } catch (PDOException $e) {
                echo "\nConnection attempt $attempt failed: " . $e->getMessage();
                if ($attempt === $maxRetries) {
                    echo "\nFailed to connect after $maxRetries attempts\n";
                    exit(1);
                }
                echo "\nRetrying in $retryDelay seconds...\n";
                sleep($retryDelay);
            }
        }

        // Get MySQL server version to decide expectation output
        $databaseVersion = explode(".", $this->db->getAttribute(PDO::ATTR_SERVER_VERSION));
        $databaseMajor = $databaseVersion[0];
        $isVersion5 = $databaseMajor === '5';
        $isVersion8 = $databaseMajor === '8';
        $this->migration_expected = $this->migration_expected . "_" . $databaseMajor;
        $this->migration_actual = $this->migration_actual . "_" . $databaseMajor;
        echo "\nDatabase server major version is: " . $databaseMajor . "\n";

        if (!$isVersion5 && !$isVersion8) {
            throw new ErrorException('Unsupported database version');
        }

        // Drop old databases, create new ones
        $this->db->exec("DROP DATABASE IF EXISTS `$this->db1`;");
        $this->db->exec("DROP DATABASE IF EXISTS `$this->db2`;");
        $this->db->exec("CREATE DATABASE `$this->db1`;");
        $this->db->exec("CREATE DATABASE `$this->db2`;");

        // Apply test SQL to databases
        $this->db->query("use `$this->db1`");
        $this->db->exec(file_get_contents('tests/end2end/db1-up.sql'));
        $this->db->query("use `$this->db2`");
        $this->db->exec(file_get_contents('tests/end2end/db2-up.sql'));
    }

    public function testAll()
    {
        $GLOBALS['argv'] = [
            "",
            "--server1=$this->user:$this->pass@$this->host:$this->port",
            "--template=templates/simple-db-migrate.tmpl",
            "--type=all",
            "--include=all",
            "--nocomments",
            "--output=./tests/end2end/$this->migration_actual",
            "server1.$this->db1:server1.$this->db2"
        ];

        ob_start();
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
        ob_end_clean();

        $migration_actual_file = file_get_contents("./tests/end2end/$this->migration_actual");
        $migration_expected_file = file_get_contents("./tests/end2end/$this->migration_expected");

        // TODO: Apply the migration_actual UP to the target database and expect there to be no differences on the command-line anymore
        // TODO: Apply the migration actual DOWN to the target database and expect there to be the same expected differences again

        $this->assertEquals($migration_expected_file, $migration_actual_file);
    }

    protected function tearDown(): void
    {
        // Ensure the database is emptied/reset after each test irrespective of assert result
        $this->db->exec("DROP DATABASE IF EXISTS `$this->db1`;");
        $this->db->exec("DROP DATABASE IF EXISTS `$this->db2`;");
        // Remove output migration file
        unlink("./tests/end2end/$this->migration_actual");
    }
}
?>
