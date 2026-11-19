<?php

use DBDiff\Migration\Runner\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

class SqlStatementSplitterTest extends TestCase
{
    private function split(string $sql, string $driver): array
    {
        return SqlStatementSplitter::split($sql, $driver);
    }

    public function testPlainStatements(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], $this->split("SELECT 1;\n SELECT 2;\n\n", 'mysql'));
    }

    public function testSemicolonsInStringsAndIdentifiers(): void
    {
        $sql = "INSERT INTO t VALUES ('a;b', 'it''s;', \"x;y\");\nSELECT `odd;name` FROM t;";
        $this->assertSame(
            ["INSERT INTO t VALUES ('a;b', 'it''s;', \"x;y\")", 'SELECT `odd;name` FROM t'],
            $this->split($sql, 'pgsql')
        );
    }

    public function testMySqlBackslashEscapesButNotPostgresPlainStrings(): void
    {
        $this->assertSame(["SELECT 'a\\';b'", 'SELECT 2'], $this->split("SELECT 'a\\';b'; SELECT 2;", 'mysql'));
        // In a plain PostgreSQL string a backslash is just a character.
        $this->assertSame(["SELECT 'a\\'", "b'"], $this->split("SELECT 'a\\';b'", 'pgsql'));
        // In an E'' string it escapes.
        $this->assertSame(["SELECT E'a\\';b'", 'SELECT 2'], $this->split("SELECT E'a\\';b'; SELECT 2;", 'pgsql'));
    }

    public function testComments(): void
    {
        $this->assertSame(
            ['SELECT 1', "-- a; comment\n/* block; still; */ SELECT 2", "# hash; comment\nSELECT 3"],
            $this->split("SELECT 1; -- a; comment\n/* block; still; */ SELECT 2;\n# hash; comment\nSELECT 3;", 'mysql')
        );
        // PostgreSQL block comments nest; # is an operator there, not a comment.
        $this->assertSame(
            ['SELECT 1', "/* outer; /* inner; */ still; */ SELECT 2", 'SELECT 5 # 3'],
            $this->split("SELECT 1; /* outer; /* inner; */ still; */ SELECT 2; SELECT 5 # 3;", 'pgsql')
        );
    }

    public function testPostgresDollarQuotedBodies(): void
    {
        $fn = "CREATE FUNCTION f() RETURNS trigger LANGUAGE plpgsql AS \$fn\$\nBEGIN\n  NEW.a := 'x;'; RETURN NEW;\nEND;\n\$fn\$";
        $do = "DO \$\$ BEGIN PERFORM 1; END \$\$";
        $this->assertSame([$fn, $do, 'SELECT $1'], $this->split("$fn;\n$do;\nSELECT \$1;", 'pgsql'));
    }

    public function testPostgresBeginAtomicAndTransactions(): void
    {
        $fn = "CREATE OR REPLACE FUNCTION add(a int, b int) RETURNS int LANGUAGE sql\nBEGIN ATOMIC\n  SELECT CASE WHEN a > 0 THEN a + b ELSE b END;\nEND";
        $this->assertSame(['BEGIN', $fn, 'COMMIT'], $this->split("BEGIN;\n$fn;\nCOMMIT;", 'pgsql'));
    }

    public function testMySqlRoutineBodies(): void
    {
        $proc = "CREATE DEFINER=`root`@`%` PROCEDURE p(IN n INT)\nBEGIN\n"
            . "  DECLARE i INT DEFAULT 0;\n"
            . "  lbl: WHILE i < n DO SET i = i + 1; END WHILE lbl;\n"
            . "  IF i > 2 THEN SELECT 'big;'; ELSEIF i > 1 THEN SELECT 'mid'; END IF;\n"
            . "  CASE i WHEN 1 THEN SELECT 1; ELSE BEGIN SELECT 2; END; END CASE;\n"
            . "  REPEAT SET i = i - 1; UNTIL i = 0 END REPEAT;\n"
            . "  SELECT CASE WHEN i = 0 THEN 'zero' END;\n"
            . "END";
        $trigger = "CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET NEW.a = 1; SET NEW.b = 2; END";
        $single = "CREATE TRIGGER t2 BEFORE UPDATE ON x FOR EACH ROW SET NEW.a = 1";
        $event = "CREATE EVENT e ON SCHEDULE EVERY 1 DAY DO BEGIN DELETE FROM x; DELETE FROM y; END";
        $this->assertSame(
            [$proc, $trigger, $single, $event, 'SELECT 1'],
            $this->split("$proc;\n$trigger;\n$single;\n$event;\nSELECT 1;", 'mysql')
        );
    }

    public function testSqliteTriggerBody(): void
    {
        $trigger = "CREATE TEMP TRIGGER IF NOT EXISTS t AFTER INSERT ON x BEGIN\n  INSERT INTO log VALUES (1);\n  UPDATE x SET a = 2;\nEND";
        $this->assertSame([$trigger, 'SELECT 1'], $this->split("$trigger;\nSELECT 1;", 'sqlite'));
    }

    public function testMySqlClientDelimiterLines(): void
    {
        $sql = "CREATE TABLE a (id int);\n\nDELIMITER \$\$\n\n"
            . "CREATE PROCEDURE p()\nBEGIN\n  SELECT 1;\n  SELECT 2;\nEND\$\$\n\n"
            . "CREATE FUNCTION f() RETURNS int DETERMINISTIC RETURN 1\$\$\n"
            . "DELIMITER ;\n"
            . "DROP TABLE a;";
        $this->assertSame(
            ['CREATE TABLE a (id int)', "CREATE PROCEDURE p()\nBEGIN\n  SELECT 1;\n  SELECT 2;\nEND",
             'CREATE FUNCTION f() RETURNS int DETERMINISTIC RETURN 1', 'DROP TABLE a'],
            $this->split($sql, 'mysql')
        );
    }

    public function testADelimiterLineAfterAComment(): void
    {
        $this->assertSame(
            ['CREATE PROCEDURE p() BEGIN SELECT 1; END', 'SELECT 2'],
            $this->split("-- ==== UP ====\nDELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; END//\nDELIMITER ;\nSELECT 2;", 'mysql')
        );
    }

    public function testAnUnterminatedLastStatementIsKept(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], $this->split("SELECT 1;\nSELECT 2", 'sqlite'));
    }
}
