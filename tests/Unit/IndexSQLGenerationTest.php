<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffToSQL\AlterTableAddKeySQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableDropKeySQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableChangeKeySQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\Dialect\SQLiteDialect;

/**
 * Bug #91: Index SQL generation — ensures correct syntax for all three
 * dialects including MySQL PRIMARY KEY handling.
 */
class IndexSQLGenerationTest extends TestCase
{
    private function makeKeyObj(string $table, string $key, $diff): object
    {
        return (object) [
            'table' => $table,
            'key'   => $key,
            'diff'  => $diff,
        ];
    }

    // ── MySQL PRIMARY KEY ─────────────────────────────────────────────────

    public function testMySQLDropPrimaryKey(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('PRIMARY KEY (`id`)');
        $obj    = $this->makeKeyObj('users', 'PRIMARY', $diffOp);
        $sql    = new AlterTableDropKeySQL($obj, new MySQLDialect());

        $this->assertSame('ALTER TABLE `users` DROP PRIMARY KEY;', $sql->getUp());
    }

    public function testMySQLAddPrimaryKey(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpAdd('PRIMARY KEY (`id`)');
        $obj    = $this->makeKeyObj('users', 'PRIMARY', $diffOp);
        $sql    = new AlterTableAddKeySQL($obj, new MySQLDialect());

        $this->assertSame('ALTER TABLE `users` ADD PRIMARY KEY (`id`);', $sql->getUp());
    }

    public function testMySQLChangePrimaryKey(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpChange('PRIMARY KEY (`id`)', 'PRIMARY KEY (`id`,`created_at`)');
        $obj    = $this->makeKeyObj('users', 'PRIMARY', $diffOp);
        $sql    = new AlterTableChangeKeySQL($obj, new MySQLDialect());

        $expected = "ALTER TABLE `users` DROP PRIMARY KEY;\nALTER TABLE `users` ADD PRIMARY KEY (`id`,`created_at`);";
        $this->assertSame($expected, $sql->getUp());
    }

    // ── MySQL regular index ───────────────────────────────────────────────

    public function testMySQLDropRegularIndex(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('KEY `idx_email` (`email`)');
        $obj    = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql    = new AlterTableDropKeySQL($obj, new MySQLDialect());

        $this->assertSame('ALTER TABLE `users` DROP INDEX `idx_email`;', $sql->getUp());
    }

    public function testMySQLAddRegularIndex(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpAdd('KEY `idx_email` (`email`)');
        $obj    = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql    = new AlterTableAddKeySQL($obj, new MySQLDialect());

        $this->assertSame('ALTER TABLE `users` ADD KEY `idx_email` (`email`);', $sql->getUp());
    }

    public function testMySQLDropIndexGetDown(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('KEY `idx_email` (`email`)');
        $obj    = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql    = new AlterTableDropKeySQL($obj, new MySQLDialect());

        $this->assertSame('ALTER TABLE `users` ADD KEY `idx_email` (`email`);', $sql->getDown());
    }

    // ── Postgres index ────────────────────────────────────────────────────

    public function testPostgresDropIndex(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('CREATE INDEX "idx_email" ON "users" ("email")');
        $obj    = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql    = new AlterTableDropKeySQL($obj, new PostgresDialect());

        $this->assertSame('DROP INDEX "idx_email";', $sql->getUp());
    }

    public function testPostgresAddIndex(): void
    {
        $indexDef = 'CREATE INDEX "idx_email" ON "users" ("email")';
        $diffOp   = new \Diff\DiffOp\DiffOpAdd($indexDef);
        $obj      = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql      = new AlterTableAddKeySQL($obj, new PostgresDialect());

        $this->assertSame($indexDef . ';', $sql->getUp());
    }

    public function testPostgresDropIndexGetDown(): void
    {
        $indexDef = 'CREATE INDEX "idx_email" ON "users" ("email")';
        $diffOp   = new \Diff\DiffOp\DiffOpRemove($indexDef);
        $obj      = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql      = new AlterTableDropKeySQL($obj, new PostgresDialect());

        $this->assertSame($indexDef . ';', $sql->getDown());
    }

    // ── SQLite index ──────────────────────────────────────────────────────

    public function testSQLiteDropIndex(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('CREATE INDEX "idx_email" ON "users" ("email")');
        $obj    = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql    = new AlterTableDropKeySQL($obj, new SQLiteDialect());

        $this->assertSame('DROP INDEX "idx_email";', $sql->getUp());
    }

    public function testSQLiteAddIndex(): void
    {
        $indexDef = 'CREATE INDEX "idx_email" ON "users" ("email")';
        $diffOp   = new \Diff\DiffOp\DiffOpAdd($indexDef);
        $obj      = $this->makeKeyObj('users', 'idx_email', $diffOp);
        $sql      = new AlterTableAddKeySQL($obj, new SQLiteDialect());

        $this->assertSame($indexDef . ';', $sql->getUp());
    }

    // ── Change index across dialects ──────────────────────────────────────

    public function testMySQLChangeIndex(): void
    {
        $oldDef = 'KEY `idx_name` (`name`)';
        $newDef = 'KEY `idx_name` (`name`,`email`)';
        $diffOp = new \Diff\DiffOp\DiffOpChange($oldDef, $newDef);
        $obj    = $this->makeKeyObj('users', 'idx_name', $diffOp);
        $sql    = new AlterTableChangeKeySQL($obj, new MySQLDialect());

        $expected = "ALTER TABLE `users` DROP INDEX `idx_name`;\nALTER TABLE `users` ADD $newDef;";
        $this->assertSame($expected, $sql->getUp());

        $expectedDown = "ALTER TABLE `users` DROP INDEX `idx_name`;\nALTER TABLE `users` ADD $oldDef;";
        $this->assertSame($expectedDown, $sql->getDown());
    }

    public function testPostgresChangeIndex(): void
    {
        $oldDef = 'CREATE INDEX "idx_name" ON "users" ("name")';
        $newDef = 'CREATE INDEX "idx_name" ON "users" ("name", "email")';
        $diffOp = new \Diff\DiffOp\DiffOpChange($oldDef, $newDef);
        $obj    = $this->makeKeyObj('users', 'idx_name', $diffOp);
        $sql    = new AlterTableChangeKeySQL($obj, new PostgresDialect());

        $expected = "DROP INDEX \"idx_name\";\n$newDef;";
        $this->assertSame($expected, $sql->getUp());
    }
}
