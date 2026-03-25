<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\Dialect\MySQLDialect;

class MySQLDialectQuoteTest extends TestCase
{
    public function testQuoteSimpleName(): void
    {
        $dialect = new MySQLDialect();
        $this->assertSame('`users`', $dialect->quote('users'));
    }

    public function testQuoteHyphenatedName(): void
    {
        $dialect = new MySQLDialect();
        $this->assertSame('`my-database`', $dialect->quote('my-database'));
    }

    public function testQuoteNameWithBacktick(): void
    {
        $dialect = new MySQLDialect();
        $this->assertSame('`name``with``ticks`', $dialect->quote('name`with`ticks'));
    }

    public function testQuoteNameWithSpaces(): void
    {
        $dialect = new MySQLDialect();
        $this->assertSame('`table name`', $dialect->quote('table name'));
    }
}
