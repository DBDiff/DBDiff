<?php

require 'vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;


class End2EndTest extends PHPUnit_Framework_TestCase
{
    public function testAll()
    {
        // db config
        $host = "localhost";
        $port = 3306;
        $user = "root";
        $pass = "xxxx";
        $db1  = "diff1";
        $db2  = "diff2";

        // db migration
        $dbh = new PDO("mysql:host=$host", $user, $pass);
        $dbh->exec("DROP DATABASE `$db1`;"); 
        $dbh->exec("CREATE DATABASE $db1;"); 
        $dbh->exec("DROP DATABASE `$db2`;");
        $dbh->exec("CREATE DATABASE $db2;");

        $db1h = new PDO("mysql:host=$host:$port;dbname=$db1;", $user, $pass);
        $db1h->exec(file_get_contents('tests/end2end/db1-up.sql'));
        $db2h = new PDO("mysql:host=$host:$port;dbname=$db2;", $user, $pass);
        $db2h->exec(file_get_contents('tests/end2end/db2-up.sql'));

        $GLOBALS['argv'] = [
            "", 
            "--server1=$user:$pass@$host:$port",
            "--template=simple-db-migrate.tmpl",
            "--nocomments",
            "--output=./tests/end2end/migration",
            "server1.$db1:server1.$db2"
        ];

        ob_start();
        $dbdiff = new DBDiff\DBDiff;
        $dbdiff->run();
        ob_end_clean();

        $migration = file_get_contents("./tests/end2end/migration");
        $rmigration = file_get_contents("./tests/end2end/result_migration");
        unlink("./tests/end2end/migration");

        $this->assertEquals($migration, $rmigration);
    }
}
?>
