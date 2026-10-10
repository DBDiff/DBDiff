<?php

use DBDiff\DB\Support\PostgresSessionOptions;
use DBDiff\Migration\Config\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * A connection URL's `options` and `sslmode`, as DBDiff reads them.
 *
 * `options` was dropped — `?options=-c default_transaction_read_only=on` made
 * a source read-only for psql and pg_dump but not for DBDiff — and the source
 * URL's sslmode was applied to both connections.
 */
class PostgresSessionOptionsTest extends TestCase
{
    public function testItReadsLibpqOptionStrings(): void
    {
        $this->assertSame(
            [['statement_timeout', '5s'], ['default_transaction_read_only', 'on'], ['application_name', 'a b'], ['work_mem', '64MB']],
            PostgresSessionOptions::parse('-c statement_timeout=5s -cdefault_transaction_read_only=on -c application_name=a\ b --work-mem=64MB')
        );
    }

    public function testItIgnoresWhatIsNotASetting(): void
    {
        $this->assertSame([], PostgresSessionOptions::parse('-c noequals --=x stray'));
        $this->assertSame([], PostgresSessionOptions::parse(''));
    }

    public function testEachServerKeepsItsOwnSslmodeAndOptions(): void
    {
        $source = DsnParser::toServerAndDb(DsnParser::parse(
            'postgres://u:p@db.one:5432/app?sslmode=require&options=-c%20default_transaction_read_only%3Don'));
        $target = DsnParser::toServerAndDb(DsnParser::parse('postgres://u:p@localhost:5432/app'));

        $this->assertSame('require', $source['server']['sslmode']);
        $this->assertSame('-c default_transaction_read_only=on', $source['server']['options']);
        $this->assertArrayNotHasKey('sslmode', $target['server']);
        $this->assertArrayNotHasKey('options', $target['server']);
    }
}
