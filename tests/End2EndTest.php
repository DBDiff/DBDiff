<?php

require 'vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;

class End2EndTest extends PHPUnit\Framework\TestCase
{
    public function testAll()
    {
        // db config
        $host = "127.0.0.1";
        $port = 3306;
        $user = "root";
        $pass = "";
        $db1  = "diff1";
        $db2  = "diff2";

        // db migration
        $migration_actual = 'migration_actual';
        $migration_expected = 'migration_expected';
        $dbh = new PDO("mysql:host=$host", $user, $pass);
        $dbh->exec("DROP DATABASE `$db1`;");
        $dbh->exec("CREATE DATABASE $db1;");
        $dbh->exec("DROP DATABASE `$db2`;");
        $dbh->exec("CREATE DATABASE $db2;");

        $db1h = new PDO("mysql:host=$host;dbname=$db1;", $user, $pass);
        $db1h->exec(file_get_contents('tests/end2end/db1-up.sql'));
        $db2h = new PDO("mysql:host=$host;dbname=$db2;", $user, $pass);
        $db2h->exec(file_get_contents('tests/end2end/db2-up.sql'));

        $GLOBALS['argv'] = [
            "",
            "--server1=$user:$pass@$host:$port",
            "--template=templates/simple-db-migrate.tmpl",
            "--type=all",
            "--include=all",
            "--nocomments",
            "--output=./tests/end2end/$migration_actual",
            "server1.$db1:server1.$db2"
        ];

        ob_start();
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
        ob_end_clean();

        $migration_actual_file = file_get_contents("./tests/end2end/$migration_actual");
        $migration_expected_file = file_get_contents("./tests/end2end/$migration_expected");
        // unlink("./tests/end2end/$migration_actual");

        // TODO: Apply the migration_actual UP to the target database and expect there to be no differences on the command-line anymore
        // TODO: Apply the migration actual DOWN to the target database and expect there to be the same expected differences again
        // TODO: Ensure the database is emptied/reset after each test

        $this->assertEquals($migration_actual_file, $migration_expected_file);
    }
}
?>
