<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\DB\Data\BinaryValue;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\Dialect\SQLiteDialect;

/**
 * How each engine writes a fetched value back as an SQL literal.
 *
 * One rule — MySQL's addslashes() — used to serve every engine, so data
 * migrations for PostgreSQL wrote `\"` into JSON (invalid), `\'` for a quote
 * and doubled every backslash; bytea arrived as a stream and crashed the run;
 * and boolean false came out as '' (invalid too).
 */
class DialectLiteralTest extends TestCase
{
    public function testPostgresDoublesAQuote(): void
    {
        $this->assertSame("'it''s'", (new PostgresDialect())->literal("it's"));
    }

    public function testPostgresWritesABackslashAsAnEscapeString(): void
    {
        // E'...' reads the same whether standard_conforming_strings is on or off.
        $this->assertSame("E'back\\\\slash and it''s'", (new PostgresDialect())->literal("back\\slash and it's"));
    }

    public function testPostgresLeavesDoubleQuotesInJsonAlone(): void
    {
        $this->assertSame("'{\"q\": \"x\"}'", (new PostgresDialect())->literal('{"q": "x"}'));
    }

    public function testPostgresWritesBooleansAndNull(): void
    {
        $pg = new PostgresDialect();
        $this->assertSame('true', $pg->literal(true));
        $this->assertSame('false', $pg->literal(false));
        $this->assertSame('NULL', $pg->literal(null));
    }

    public function testPostgresWritesByteaInHex(): void
    {
        $this->assertSame("'\\x00ff10'::bytea", (new PostgresDialect())->literal(new BinaryValue('00FF10')));
    }

    public function testFloatsKeepEveryDigitAndTheirSpecialValues(): void
    {
        $pg = new PostgresDialect();
        // (string) 0.1 + 0.2 would round to 14 digits: 0.3.
        $this->assertSame("'0.30000000000000004'", $pg->literal(0.1 + 0.2));
        $this->assertSame("'NaN'", $pg->literal(NAN));
        $this->assertSame("'-Infinity'", $pg->literal(-INF));
    }

    public function testMySQLIsUnchanged(): void
    {
        $my = new MySQLDialect();
        $this->assertSame("'it\\'s'", $my->literal("it's"));
        $this->assertSame("UNHEX('00FF')", $my->literal(new BinaryValue('00FF')));
        $this->assertSame('1', $my->literal(true));
    }

    public function testSQLiteDoublesQuotesAndWritesBinaryAsHex(): void
    {
        $lite = new SQLiteDialect();
        $this->assertSame("'it''s a back\\slash'", $lite->literal("it's a back\\slash"));
        $this->assertSame("X'00FF'", $lite->literal(new BinaryValue('00FF')));
    }

    public function testAConditionOnNullIsIsNull(): void
    {
        $this->assertSame('"v" IS NULL', BinaryValue::formatCondition('"v"', null, new PostgresDialect()));
    }

    public function testAByteaStreamIsReadIntoABinaryValue(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "\x00\xff");
        rewind($stream);

        $row = BinaryValue::fromStreams(['id' => 1, 'b' => $stream]);

        $this->assertSame(1, $row['id']);
        $this->assertInstanceOf(BinaryValue::class, $row['b']);
        $this->assertSame('00ff', $row['b']->hex);
    }

    // A table with no key: two identical rows must stay two rows apart.
    public function testDeletingOneOfSeveralIdenticalRows(): void
    {
        $conds = ['"x" = \'1\'', '"y" IS NULL'];
        $this->assertSame(
            'DELETE FROM "k" WHERE ctid = (SELECT ctid FROM "k" WHERE "x" = \'1\' AND "y" IS NULL LIMIT 1);',
            (new PostgresDialect())->deleteOneRow('"k"', $conds)
        );
        $this->assertSame("DELETE FROM `k` WHERE `x` = '1' LIMIT 1;", (new MySQLDialect())->deleteOneRow('`k`', ["`x` = '1'"]));
        $this->assertStringContainsString('rowid = (SELECT rowid', (new SQLiteDialect())->deleteOneRow('"k"', $conds));
    }

    public function testPostgresOverridesTheSystemValueOnlyWhenAsked(): void
    {
        $pg = new PostgresDialect();
        $this->assertSame('INSERT INTO "t" ("id") VALUES(\'1\');', $pg->insertRow('"t"', ['"id"'], ["'1'"]));
        $this->assertSame(
            'INSERT INTO "t" ("id") OVERRIDING SYSTEM VALUE VALUES(\'1\');',
            $pg->insertRow('"t"', ['"id"'], ["'1'"], true)
        );
    }
}
