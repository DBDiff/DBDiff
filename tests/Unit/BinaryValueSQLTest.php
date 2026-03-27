<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\DB\Data\BinaryValue;
use DBDiff\SQLGen\DiffToSQL\InsertDataSQL;
use DBDiff\SQLGen\DiffToSQL\DeleteDataSQL;
use DBDiff\SQLGen\DiffToSQL\UpdateDataSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;
use Diff\DiffOp\DiffOpChange;

class BinaryValueSQLTest extends TestCase
{
    // ── BinaryValue helpers ───────────────────────────────────────────────

    public function testFormatSQLWithNull(): void
    {
        $this->assertSame('NULL', BinaryValue::formatSQL(null));
    }

    public function testFormatSQLWithRegularString(): void
    {
        $this->assertSame("'hello'", BinaryValue::formatSQL('hello'));
    }

    public function testFormatSQLWithBinaryValue(): void
    {
        $bv = new BinaryValue('550E8400E29B41D4A716446655440000');
        $this->assertSame("UNHEX('550E8400E29B41D4A716446655440000')", BinaryValue::formatSQL($bv));
    }

    public function testFormatConditionWithRegularValue(): void
    {
        $this->assertSame("`id` = '42'", BinaryValue::formatCondition('`id`', '42'));
    }

    public function testFormatConditionWithBinaryValue(): void
    {
        $bv = new BinaryValue('AABBCCDD');
        $this->assertSame("`uuid` = UNHEX('AABBCCDD')", BinaryValue::formatCondition('`uuid`', $bv));
    }

    public function testBinaryValueToString(): void
    {
        $bv = new BinaryValue('FF00');
        $this->assertSame('FF00', (string) $bv);
    }

    // ── InsertDataSQL with BinaryValue ────────────────────────────────────

    public function testInsertWithBinaryUUID(): void
    {
        $hex = '550E8400E29B41D4A716446655440000';
        $row = ['uuid' => new BinaryValue($hex), 'name' => 'Alice'];
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['uuid' => new BinaryValue($hex)],
                'diff' => new DiffOpAdd($row),
            ],
        ];

        $sql = new InsertDataSQL($obj, new MySQLDialect());
        $up = $sql->getUp();
        $this->assertStringContainsString("UNHEX('$hex')", $up);
        $this->assertStringContainsString("'Alice'", $up);

        $down = $sql->getDown();
        $this->assertStringContainsString("UNHEX('$hex')", $down);
    }

    public function testInsertWithMixedRegularAndBinary(): void
    {
        $hex = 'DEADBEEF';
        $row = ['id' => '1', 'data' => new BinaryValue($hex), 'name' => 'Bob'];
        $obj = (object) [
            'table' => 'items',
            'diff'  => [
                'keys' => ['id' => '1'],
                'diff' => new DiffOpAdd($row),
            ],
        ];

        $sql = new InsertDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();
        $this->assertStringContainsString("'1'", $result);
        $this->assertStringContainsString("UNHEX('DEADBEEF')", $result);
        $this->assertStringContainsString("'Bob'", $result);
    }

    // ── DeleteDataSQL with BinaryValue ────────────────────────────────────

    public function testDeleteWithBinaryKey(): void
    {
        $hex = '550E8400E29B41D4A716446655440000';
        $row = ['uuid' => new BinaryValue($hex), 'name' => 'Alice'];
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['uuid' => new BinaryValue($hex)],
                'diff' => new DiffOpRemove($row),
            ],
        ];

        $sql = new DeleteDataSQL($obj, new MySQLDialect());
        $up = $sql->getUp();
        $this->assertStringContainsString("UNHEX('$hex')", $up);

        $down = $sql->getDown();
        $this->assertStringContainsString("UNHEX('$hex')", $down);
    }

    // ── UpdateDataSQL with BinaryValue ────────────────────────────────────

    public function testUpdateWithBinaryKeyAndValue(): void
    {
        $hex = 'AABBCCDD11223344';
        $obj = (object) [
            'table' => 'items',
            'diff'  => [
                'keys' => ['uuid' => new BinaryValue($hex)],
                'diff' => [
                    'data' => new DiffOpChange(
                        new BinaryValue('OLD0OLD0'),
                        new BinaryValue('NEW0NEW0')
                    ),
                ],
            ],
        ];

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $up = $sql->getUp();
        $this->assertStringContainsString("UNHEX('NEW0NEW0')", $up);
        $this->assertStringContainsString("UNHEX('AABBCCDD11223344')", $up);

        $down = $sql->getDown();
        $this->assertStringContainsString("UNHEX('OLD0OLD0')", $down);
        $this->assertStringContainsString("UNHEX('AABBCCDD11223344')", $down);
    }

    public function testUpdateWithMixedBinaryAndRegularValues(): void
    {
        $obj = (object) [
            'table' => 'items',
            'diff'  => [
                'keys' => ['id' => '42'],
                'diff' => [
                    'name' => new DiffOpChange('old_name', 'new_name'),
                    'hash' => new DiffOpChange(
                        new BinaryValue('AAAA'),
                        new BinaryValue('BBBB')
                    ),
                ],
            ],
        ];

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $up = $sql->getUp();
        $this->assertStringContainsString("'new_name'", $up);
        $this->assertStringContainsString("UNHEX('BBBB')", $up);
        $this->assertStringContainsString("`id` = '42'", $up);
    }
}
