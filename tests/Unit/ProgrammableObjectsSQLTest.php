<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\Diff\CreateView;
use DBDiff\Diff\DropView;
use DBDiff\Diff\AlterView;
use DBDiff\Diff\CreateTrigger;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\AlterTrigger;
use DBDiff\Diff\CreateRoutine;
use DBDiff\Diff\DropRoutine;
use DBDiff\Diff\AlterRoutine;
use DBDiff\SQLGen\DiffToSQL\CreateViewSQL;
use DBDiff\SQLGen\DiffToSQL\DropViewSQL;
use DBDiff\SQLGen\DiffToSQL\AlterViewSQL;
use DBDiff\SQLGen\DiffToSQL\CreateTriggerSQL;
use DBDiff\SQLGen\DiffToSQL\DropTriggerSQL;
use DBDiff\SQLGen\DiffToSQL\AlterTriggerSQL;
use DBDiff\SQLGen\DiffToSQL\CreateRoutineSQL;
use DBDiff\SQLGen\DiffToSQL\DropRoutineSQL;
use DBDiff\SQLGen\DiffToSQL\AlterRoutineSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\Dialect\SQLiteDialect;

class ProgrammableObjectsSQLTest extends TestCase
{
    // ── CreateView ─────────────────────────────────────────────────────────

    public function testCreateViewUpMySQL(): void
    {
        $diff = new CreateView('product_names', 'CREATE VIEW `product_names` AS SELECT `id`, `name` FROM `products`');
        $sql  = new CreateViewSQL($diff, new MySQLDialect());
        $this->assertSame('CREATE VIEW `product_names` AS SELECT `id`, `name` FROM `products`;', $sql->getUp());
    }

    public function testCreateViewDownMySQL(): void
    {
        $diff = new CreateView('product_names', 'CREATE VIEW `product_names` AS SELECT `id`, `name` FROM `products`');
        $sql  = new CreateViewSQL($diff, new MySQLDialect());
        $this->assertSame('DROP VIEW IF EXISTS `product_names`;', $sql->getDown());
    }

    public function testCreateViewUpPostgres(): void
    {
        $diff = new CreateView('product_names', 'CREATE VIEW "product_names" AS SELECT id, name FROM products');
        $sql  = new CreateViewSQL($diff, new PostgresDialect());
        $this->assertSame('CREATE VIEW "product_names" AS SELECT id, name FROM products;', $sql->getUp());
    }

    public function testCreateViewDownPostgres(): void
    {
        $diff = new CreateView('product_names', 'CREATE VIEW "product_names" AS SELECT id, name FROM products');
        $sql  = new CreateViewSQL($diff, new PostgresDialect());
        $this->assertSame('DROP VIEW IF EXISTS "product_names";', $sql->getDown());
    }

    // ── DropView ──────────────────────────────────────────────────────────

    public function testDropViewUpMySQL(): void
    {
        $diff = new DropView('old_report', 'CREATE VIEW `old_report` AS SELECT 1');
        $sql  = new DropViewSQL($diff, new MySQLDialect());
        $this->assertSame('DROP VIEW IF EXISTS `old_report`;', $sql->getUp());
    }

    public function testDropViewDownMySQL(): void
    {
        $diff = new DropView('old_report', 'CREATE VIEW `old_report` AS SELECT 1');
        $sql  = new DropViewSQL($diff, new MySQLDialect());
        $this->assertSame('CREATE VIEW `old_report` AS SELECT 1;', $sql->getDown());
    }

    // ── AlterView ─────────────────────────────────────────────────────────

    public function testAlterViewUpMySQL(): void
    {
        $diff = new AlterView(
            'expensive_products',
            'CREATE VIEW `expensive_products` AS SELECT * FROM `products` WHERE `price` > 10',
            'CREATE VIEW `expensive_products` AS SELECT * FROM `products` WHERE `price` > 50'
        );
        $sql = new AlterViewSQL($diff, new MySQLDialect());
        $up  = $sql->getUp();
        $this->assertStringContainsString('DROP VIEW IF EXISTS `expensive_products`;', $up);
        $this->assertStringContainsString('price` > 10;', $up);
    }

    public function testAlterViewDownMySQL(): void
    {
        $diff = new AlterView(
            'expensive_products',
            'CREATE VIEW `expensive_products` AS SELECT * FROM `products` WHERE `price` > 10',
            'CREATE VIEW `expensive_products` AS SELECT * FROM `products` WHERE `price` > 50'
        );
        $sql = new AlterViewSQL($diff, new MySQLDialect());
        $down = $sql->getDown();
        $this->assertStringContainsString('DROP VIEW IF EXISTS `expensive_products`;', $down);
        $this->assertStringContainsString('price` > 50;', $down);
    }

    // ── CreateTrigger ─────────────────────────────────────────────────────

    public function testCreateTriggerUpMySQL(): void
    {
        $def  = 'CREATE TRIGGER `trg_upd` BEFORE UPDATE ON `products` FOR EACH ROW SET NEW.`updated_at` = NOW()';
        $diff = new CreateTrigger('trg_upd', 'products', $def);
        $sql  = new CreateTriggerSQL($diff, new MySQLDialect());
        $this->assertSame($def . ';', $sql->getUp());
    }

    public function testCreateTriggerDownMySQL(): void
    {
        $diff = new CreateTrigger('trg_upd', 'products', 'CREATE TRIGGER ...');
        $sql  = new CreateTriggerSQL($diff, new MySQLDialect());
        $this->assertSame('DROP TRIGGER IF EXISTS `trg_upd`;', $sql->getDown());
    }

    public function testCreateTriggerDownPostgres(): void
    {
        $diff = new CreateTrigger('trg_upd', 'products', 'CREATE TRIGGER ...');
        $sql  = new CreateTriggerSQL($diff, new PostgresDialect());
        $this->assertSame('DROP TRIGGER IF EXISTS "trg_upd" ON "products";', $sql->getDown());
    }

    // ── DropTrigger ───────────────────────────────────────────────────────

    public function testDropTriggerUpMySQL(): void
    {
        $diff = new DropTrigger('trg_old', 'products', 'CREATE TRIGGER ...');
        $sql  = new DropTriggerSQL($diff, new MySQLDialect());
        $this->assertSame('DROP TRIGGER IF EXISTS `trg_old`;', $sql->getUp());
    }

    public function testDropTriggerUpPostgres(): void
    {
        $diff = new DropTrigger('trg_old', 'products', 'CREATE TRIGGER ...');
        $sql  = new DropTriggerSQL($diff, new PostgresDialect());
        $this->assertSame('DROP TRIGGER IF EXISTS "trg_old" ON "products";', $sql->getUp());
    }

    public function testDropTriggerDownReturnsCreate(): void
    {
        $def  = 'CREATE TRIGGER `trg_old` AFTER DELETE ON `products` FOR EACH ROW BEGIN END';
        $diff = new DropTrigger('trg_old', 'products', $def);
        $sql  = new DropTriggerSQL($diff, new MySQLDialect());
        $this->assertSame($def . ';', $sql->getDown());
    }

    // ── AlterTrigger ──────────────────────────────────────────────────────

    public function testAlterTriggerUpMySQL(): void
    {
        $diff = new AlterTrigger('trg_x', 'products', 'CREATE TRIGGER `trg_x` NEW', 'CREATE TRIGGER `trg_x` OLD');
        $sql  = new AlterTriggerSQL($diff, new MySQLDialect());
        $up   = $sql->getUp();
        $this->assertStringContainsString('DROP TRIGGER IF EXISTS `trg_x`;', $up);
        $this->assertStringContainsString('CREATE TRIGGER `trg_x` NEW;', $up);
    }

    public function testAlterTriggerUpPostgres(): void
    {
        $diff = new AlterTrigger('trg_x', 'products', 'CREATE TRIGGER trg_x NEW', 'CREATE TRIGGER trg_x OLD');
        $sql  = new AlterTriggerSQL($diff, new PostgresDialect());
        $up   = $sql->getUp();
        $this->assertStringContainsString('DROP TRIGGER IF EXISTS "trg_x" ON "products";', $up);
        $this->assertStringContainsString('CREATE TRIGGER trg_x NEW;', $up);
    }

    // ── CreateRoutine ─────────────────────────────────────────────────────

    public function testCreateRoutineUpFunction(): void
    {
        $def  = 'CREATE FUNCTION `get_count`() RETURNS int BEGIN RETURN 1; END';
        $diff = new CreateRoutine('get_count', $def);
        $sql  = new CreateRoutineSQL($diff, new MySQLDialect());
        $this->assertSame($def . ';', $sql->getUp());
    }

    public function testCreateRoutineDownFunction(): void
    {
        $def  = 'CREATE FUNCTION `get_count`() RETURNS int BEGIN RETURN 1; END';
        $diff = new CreateRoutine('get_count', $def);
        $sql  = new CreateRoutineSQL($diff, new MySQLDialect());
        $this->assertSame('DROP FUNCTION IF EXISTS `get_count`;', $sql->getDown());
    }

    public function testCreateRoutineDownProcedure(): void
    {
        $def  = 'CREATE PROCEDURE `cleanup`() BEGIN DELETE FROM t; END';
        $diff = new CreateRoutine('cleanup', $def);
        $sql  = new CreateRoutineSQL($diff, new MySQLDialect());
        $this->assertSame('DROP PROCEDURE IF EXISTS `cleanup`;', $sql->getDown());
    }

    // ── DropRoutine ───────────────────────────────────────────────────────

    public function testDropRoutineUpFunction(): void
    {
        $def  = 'CREATE FUNCTION `old_fn`() RETURNS int BEGIN RETURN 0; END';
        $diff = new DropRoutine('old_fn', $def);
        $sql  = new DropRoutineSQL($diff, new MySQLDialect());
        $this->assertSame('DROP FUNCTION IF EXISTS `old_fn`;', $sql->getUp());
    }

    public function testDropRoutineUpProcedure(): void
    {
        $def  = 'CREATE PROCEDURE `old_proc`() BEGIN END';
        $diff = new DropRoutine('old_proc', $def);
        $sql  = new DropRoutineSQL($diff, new MySQLDialect());
        $this->assertSame('DROP PROCEDURE IF EXISTS `old_proc`;', $sql->getUp());
    }

    // ── AlterRoutine ──────────────────────────────────────────────────────

    public function testAlterRoutineUp(): void
    {
        $srcDef = 'CREATE FUNCTION `fn`() RETURNS int BEGIN RETURN 1; END';
        $tgtDef = 'CREATE FUNCTION `fn`() RETURNS int BEGIN RETURN 0; END';
        $diff   = new AlterRoutine('fn', $srcDef, $tgtDef);
        $sql    = new AlterRoutineSQL($diff, new MySQLDialect());
        $up     = $sql->getUp();
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS `fn`;', $up);
        $this->assertStringContainsString('RETURN 1;', $up);
    }

    public function testAlterRoutineDown(): void
    {
        $srcDef = 'CREATE FUNCTION `fn`() RETURNS int BEGIN RETURN 1; END';
        $tgtDef = 'CREATE FUNCTION `fn`() RETURNS int BEGIN RETURN 0; END';
        $diff   = new AlterRoutine('fn', $srcDef, $tgtDef);
        $sql    = new AlterRoutineSQL($diff, new MySQLDialect());
        $down   = $sql->getDown();
        $this->assertStringContainsString('DROP FUNCTION IF EXISTS `fn`;', $down);
        $this->assertStringContainsString('RETURN 0;', $down);
    }

    // ── SQLite variations ─────────────────────────────────────────────────

    public function testCreateViewDownSQLite(): void
    {
        $diff = new CreateView('v1', 'CREATE VIEW "v1" AS SELECT 1');
        $sql  = new CreateViewSQL($diff, new SQLiteDialect());
        $this->assertSame('DROP VIEW IF EXISTS "v1";', $sql->getDown());
    }

    public function testDropTriggerUpSQLite(): void
    {
        $diff = new DropTrigger('trg_del', 'products', 'CREATE TRIGGER "trg_del" ...');
        $sql  = new DropTriggerSQL($diff, new SQLiteDialect());
        $this->assertSame('DROP TRIGGER IF EXISTS "trg_del";', $sql->getUp());
    }
}
