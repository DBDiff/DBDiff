<?php
require 'vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\SQLGenerator;
use Illuminate\Database\Capsule\Manager as Capsule;

// TODO: Add support for multiple tests, including configurable $GLOBALS['argv'] params by test
class End2EndTest extends TestCase
{
    // db config (detects CI environment)
    private $isContinuousIntegrationServer;
    private $host;
    private $port = 3306;
    private $user;
    private $pass;
    private $db;
    private $db_source;
    private $db_target;
    private $source  = "source";
    private $target  = "target";

    // db migration
    private $migration_actual = 'migration_actual';
    private $migration_expected = 'migration_expected';

    function __construct() {
      parent::__construct();

      // Initialise variables
      $this->isContinuousIntegrationServer = getenv('CI');
      $this->host = $this->isContinuousIntegrationServer ? "127.0.0.1" : "localhost";
      $this->user = $this->isContinuousIntegrationServer ? "root" : "dbdiff";
      $this->pass = $this->isContinuousIntegrationServer ? "" : "dbdiff";
      $this->db = new PDO("mysql:host=$this->host", $this->user, $this->pass);

      // Set some global arguments for the CLI
      $GLOBALS['argv'] = [
          "",
          "--server1=$this->user:$this->pass@$this->host:$this->port",
          "--template=templates/simple-db-migrate.tmpl",
          "--type=all",
          "--include=all",
          "--nocomments",
          "--output=./tests/end2end/$this->migration_actual",
          "server1.$this->source:server1.$this->target"
      ];

      // Create databases for test
      $this->db->exec("CREATE DATABASE `$this->source`;");
      $this->db->exec("CREATE DATABASE `$this->target`;");

      // Populate databases for test
      $this->db_source = new PDO("mysql:host=$this->host;dbname=$this->source;", $this->user, $this->pass);
      $this->db_source->exec(file_get_contents("tests/end2end/$this->source.sql"));
      $this->db_target = new PDO("mysql:host=$this->host;dbname=$this->target;", $this->user, $this->pass);
      $this->db_target->exec(file_get_contents("tests/end2end/$this->target.sql"));
    }

    function __destruct() {
      // Cleanup
      $this->db->exec("DROP DATABASE `$this->source`;");
      $this->db->exec("DROP DATABASE `$this->target`;");

      // Remove actual migration file
      // unlink("./tests/end2end/$migration_actual");
    }

    public function testExpectedMigrationMatchesActualMigration() {
        ob_start();
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
        ob_end_clean();

        $migration_actual_file = file_get_contents("./tests/end2end/$this->migration_actual");
        $migration_expected_file = file_get_contents("./tests/end2end/$this->migration_expected");

        $this->assertEquals($migration_actual_file, $migration_expected_file);

        $sqlGenerator = new SQLGenerator($dbdiff->getDiff());
        return $sqlGenerator;
    }

    /**
     * @depends testExpectedMigrationMatchesActualMigration
     */
    public function testApplyMigrationUpToTargetDatabaseAndExpectNoDifferences($sqlGenerator) {
      /*
      $this->db_target->exec($sqlGenerator->getUp());

      $dbdiff = new DBDiff\DBDiff;
      $dbdiff->run();

      $diff = $dbdiff->getDiff();
      $this->assertEquals(empty($diff['schema']) && empty($diff['data']), true);

      return $sqlGenerator;
      */
    }

    /**
     * @depends testApplyMigrationUpToTargetDatabaseAndExpectNoDifferences
     */
    public function testApplyMigrationDownToTargetDatabaseAndExpectSameMigrationIsProducedAsBefore($sqlGenerator) {
      /*
      $this->db_target->exec($sqlGenerator->getDown());

      $dbdiff = new DBDiff\DBDiff;
      $dbdiff->run();

      $migration_actual_file = file_get_contents("./tests/end2end/$this->migration_actual");

      $this->assertEquals($migration_actual_file, $migration_expected_file);
      */
    }
}
?>
