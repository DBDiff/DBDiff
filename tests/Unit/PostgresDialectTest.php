<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\Dialect\PostgresDialect;

class PostgresDialectTest extends TestCase
{
    private PostgresDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new PostgresDialect();
    }

    // ── Quoting ───────────────────────────────────────────────────────────

    public function testQuoteSimpleName(): void
    {
        $this->assertSame('"users"', $this->dialect->quote('users'));
    }

    public function testQuoteHyphenatedName(): void
    {
        $this->assertSame('"my-table"', $this->dialect->quote('my-table'));
    }

    public function testQuoteNameWithDoubleQuote(): void
    {
        $this->assertSame('"name""with""quotes"', $this->dialect->quote('name"with"quotes'));
    }

    public function testQuoteNameWithSpaces(): void
    {
        $this->assertSame('"table name"', $this->dialect->quote('table name'));
    }

    // ── Driver ────────────────────────────────────────────────────────────

    public function testGetDriver(): void
    {
        $this->assertSame('pgsql', $this->dialect->getDriver());
    }

    public function testIsNotMySQLOnly(): void
    {
        $this->assertFalse($this->dialect->isMySQLOnly());
    }

    // ── DROP TRIGGER requires ON table ────────────────────────────────────

    public function testDropTriggerIncludesOnTable(): void
    {
        $sql = $this->dialect->dropTrigger('trg_audit', 'orders');
        $this->assertSame('DROP TRIGGER IF EXISTS "trg_audit" ON "orders";', $sql);
    }

    public function testDropTriggerQuotesSpecialNames(): void
    {
        $sql = $this->dialect->dropTrigger('my-trigger', 'my-table');
        $this->assertSame('DROP TRIGGER IF EXISTS "my-trigger" ON "my-table";', $sql);
    }

    // ── DROP INDEX (schema-level, no ALTER TABLE) ─────────────────────────

    public function testDropIndexNoAlterTable(): void
    {
        $sql = $this->dialect->dropIndex('orders', 'idx_orders_user');
        $this->assertSame('DROP INDEX "idx_orders_user";', $sql);
    }

    // ── ADD/DROP COLUMN ───────────────────────────────────────────────────

    public function testAddColumn(): void
    {
        $sql = $this->dialect->addColumn('users', '"phone" varchar(20) DEFAULT NULL');
        $this->assertSame('ALTER TABLE "users" ADD COLUMN "phone" varchar(20) DEFAULT NULL;', $sql);
    }

    public function testDropColumn(): void
    {
        $sql = $this->dialect->dropColumn('users', 'phone');
        $this->assertSame('ALTER TABLE "users" DROP COLUMN "phone";', $sql);
    }
}
