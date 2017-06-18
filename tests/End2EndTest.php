<?php

use DBDiff\SQLGen\SQLGenerator;

require 'vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// TODO: Add support for multiple tests, including configurable $GLOBALS['argv'] params by test
class End2EndTest extends PHPUnit\Framework\TestCase
{
    // db config (detects CI environment)
    private $isContinuousIntegrationServer;
    private $host;
    private $port = 3306;
    private $user;
    private $pass;
    private $dbh;
    private $db1  = "diff1";
    private $db2  = "diff2";

    // db migration
    private $migration_actual = 'migration_actual';
    private $migration_expected = 'migration_expected';

    function __construct() {
      parent::__construct();

      // Initialise variables
      $this->isContinuousIntegrationServer = getenv('ci');
      $this->host = $this->isContinuousIntegrationServer ? "127.0.0.1" : "localhost";
      $this->user = $this->isContinuousIntegrationServer ? "root" : "dbdiff";
      $this->pass = $this->isContinuousIntegrationServer ? "" : "dbdiff";
      $this->dbh = new PDO("mysql:host=$this->host", $this->user, $this->pass);

      // Set some global arguments for the CLI
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
    }

    public function setUp() {
      // Create databases for test
      $this->dbh->exec("CREATE DATABASE $this->db1;");
      $this->dbh->exec("CREATE DATABASE $this->db2;");
    }

    public function tearDown() {
      // Cleanup
      $this->dbh->exec("DROP DATABASE `$this->db1`;");
      $this->dbh->exec("DROP DATABASE `$this->db2`;");

      // Remove actual migration file
      // unlink("./tests/end2end/$migration_actual");
    }

    public function testAll()
    {
        // Populate databases for test
        $db1h = new PDO("mysql:host=$this->host;dbname=$this->db1;", $this->user, $this->pass);
        $db1h->exec(file_get_contents('tests/end2end/db1-up.sql'));
        $db2h = new PDO("mysql:host=$this->host;dbname=$this->db2;", $this->user, $this->pass);
        $db2h->exec(file_get_contents('tests/end2end/db2-up.sql'));

        ob_start();
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
        ob_end_clean();

        $migration_actual_file = file_get_contents("./tests/end2end/$this->migration_actual");
        $migration_expected_file = file_get_contents("./tests/end2end/$this->migration_expected");

        echo "\nActual migration output should match expected output for the test\n";
        $this->assertEquals($migration_actual_file, $migration_expected_file);

        /*
        $sqlGenerator = new SQLGenerator($dbdiff->getDiff());
        $db2h->exec($sqlGenerator->getUp());

        // Apply the migration_actual UP to the target database and expect there to be no differences on the command-line anymore
        ob_start();
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
        ob_end_clean();

        $diff = $dbdiff->getDiff();
        echo "\nAfter up migration is applied, up and down diff should be empty\n";
        $this->assertEquals(empty($diff['schema']) && empty($diff['data']), false);

        // Apply the migration actual DOWN to the target database and expect there to be the same expected differences again
        ob_start();
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
        ob_end_clean();

        $migration_actual_file = file_get_contents("./tests/end2end/$this->migration_actual");
        $migration_expected_file = file_get_contents("./tests/end2end/$this->migration_expected");

        echo "\nAfter the down migration is applied, actual migration output should match expected output for the test\n";
        $this->assertEquals($migration_actual_file, $migration_expected_file);
        */
    }
}
?>
