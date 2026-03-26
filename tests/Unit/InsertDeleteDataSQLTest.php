<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffToSQL\InsertDataSQL;
use DBDiff\SQLGen\DiffToSQL\DeleteDataSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;

class InsertDeleteDataSQLTest extends TestCase
{
    // ── InsertDataSQL ─────────────────────────────────────────────────────

    public function testInsertGetUpIncludesColumnNames(): void
    {
        $row = ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'];
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['id' => '1'],
                'diff' => new DiffOpAdd($row),
            ],
        ];

        $sql = new InsertDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();

        $this->assertStringContainsString('(`id`,`name`,`email`)', $result);
        $this->assertStringContainsString("VALUES('1','Alice','alice@example.com')", $result);
    }

    public function testInsertGetUpHandlesNullValues(): void
    {
        $row = ['id' => '1', 'name' => null, 'email' => 'test@test.com'];
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['id' => '1'],
                'diff' => new DiffOpAdd($row),
            ],
        ];

        $sql = new InsertDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();

        $this->assertStringContainsString('(`id`,`name`,`email`)', $result);
        $this->assertStringContainsString("VALUES('1',NULL,'test@test.com')", $result);
    }

    public function testInsertGetDownGeneratesDelete(): void
    {
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['id' => '1'],
                'diff' => new DiffOpAdd(['id' => '1', 'name' => 'Alice']),
            ],
        ];

        $sql = new InsertDataSQL($obj, new MySQLDialect());
        $result = $sql->getDown();

        $this->assertSame("DELETE FROM `users` WHERE `id` = '1';", $result);
    }

    // ── DeleteDataSQL ─────────────────────────────────────────────────────

    public function testDeleteGetDownIncludesColumnNames(): void
    {
        $row = ['id' => '2', 'name' => 'Bob', 'email' => 'bob@example.com'];
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['id' => '2'],
                'diff' => new DiffOpRemove($row),
            ],
        ];

        $sql = new DeleteDataSQL($obj, new MySQLDialect());
        $result = $sql->getDown();

        $this->assertStringContainsString('(`id`,`name`,`email`)', $result);
        $this->assertStringContainsString("VALUES('2','Bob','bob@example.com')", $result);
    }

    public function testDeleteGetDownHandlesNullValues(): void
    {
        $row = ['id' => '2', 'name' => null, 'email' => 'test@test.com'];
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['id' => '2'],
                'diff' => new DiffOpRemove($row),
            ],
        ];

        $sql = new DeleteDataSQL($obj, new MySQLDialect());
        $result = $sql->getDown();

        $this->assertStringContainsString('(`id`,`name`,`email`)', $result);
        $this->assertStringContainsString("VALUES('2',NULL,'test@test.com')", $result);
    }

    public function testDeleteGetUpGeneratesDelete(): void
    {
        $obj = (object) [
            'table' => 'users',
            'diff'  => [
                'keys' => ['id' => '2'],
                'diff' => new DiffOpRemove(['id' => '2', 'name' => 'Bob']),
            ],
        ];

        $sql = new DeleteDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();

        $this->assertSame("DELETE FROM `users` WHERE `id` = '2';", $result);
    }
}
