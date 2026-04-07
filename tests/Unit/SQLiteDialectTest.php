<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\Dialect\SQLiteDialect;

class SQLiteDialectTest extends TestCase
{
    private SQLiteDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SQLiteDialect();
    }

    // ── Quoting (double-quote, same as Postgres via AbstractAnsi) ─────────

    public function testQuoteSimpleName(): void
    {
        $this->assertSame('"products"', $this->dialect->quote('products'));
    }

    public function testQuoteNameWithDoubleQuote(): void
    {
        $this->assertSame('"col""name"', $this->dialect->quote('col"name'));
    }

    // ── Driver ────────────────────────────────────────────────────────────

    public function testGetDriver(): void
    {
        $this->assertSame('sqlite', $this->dialect->getDriver());
    }

    public function testIsNotMySQLOnly(): void
    {
        $this->assertFalse($this->dialect->isMySQLOnly());
    }

    // ── DROP TRIGGER (no ON table for SQLite) ─────────────────────────────

    public function testDropTriggerNoOnClause(): void
    {
        $sql = $this->dialect->dropTrigger('trg_audit', 'orders');
        $this->assertSame('DROP TRIGGER IF EXISTS "trg_audit";', $sql);
    }

    // ── DROP INDEX (schema-level, same as Postgres) ───────────────────────

    public function testDropIndexNoAlterTable(): void
    {
        $sql = $this->dialect->dropIndex('orders', 'idx_orders_user');
        $this->assertSame('DROP INDEX "idx_orders_user";', $sql);
    }

    // ── ADD/DROP COLUMN ───────────────────────────────────────────────────

    public function testAddColumn(): void
    {
        $sql = $this->dialect->addColumn('users', '"phone" TEXT DEFAULT NULL');
        $this->assertSame('ALTER TABLE "users" ADD COLUMN "phone" TEXT DEFAULT NULL;', $sql);
    }

    public function testDropColumn(): void
    {
        $sql = $this->dialect->dropColumn('users', 'old_field');
        $this->assertSame('ALTER TABLE "users" DROP COLUMN "old_field";', $sql);
    }
}
