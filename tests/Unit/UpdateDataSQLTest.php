<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffToSQL\UpdateDataSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;
use Diff\DiffOp\DiffOpChange;

class UpdateDataSQLTest extends TestCase
{
    private function makeObj(string $table, array $diffMap, array $keys): object
    {
        return (object) [
            'table' => $table,
            'diff'  => ['diff' => $diffMap, 'keys' => $keys],
        ];
    }

    // ── getUp ─────────────────────────────────────────────────────────────

    public function testGetUpWithDiffOpChange(): void
    {
        $obj = $this->makeObj('users', [
            'name' => new DiffOpChange('old_name', 'new_name'),
        ], ['id' => '1']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();

        $this->assertSame("UPDATE `users` SET `name` = 'new_name' WHERE `id` = '1';", $result);
    }

    public function testGetUpWithDiffOpChangeNullNewValue(): void
    {
        $obj = $this->makeObj('users', [
            'email' => new DiffOpChange('old@test.com', null),
        ], ['id' => '2']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();

        $this->assertSame("UPDATE `users` SET `email` = NULL WHERE `id` = '2';", $result);
    }

    public function testGetUpWithDiffOpRemoveDoesNotCrash(): void
    {
        $obj = $this->makeObj('users', [
            'old_col' => new DiffOpRemove('removed_value'),
        ], ['id' => '3']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();

        // DiffOpRemove has no getNewValue(), so should produce NULL
        $this->assertSame("UPDATE `users` SET `old_col` = NULL WHERE `id` = '3';", $result);
    }

    public function testGetUpWithDiffOpAddDoesNotCrash(): void
    {
        $obj = $this->makeObj('users', [
            'new_col' => new DiffOpAdd('added_value'),
        ], ['id' => '4']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getUp();

        $this->assertSame("UPDATE `users` SET `new_col` = 'added_value' WHERE `id` = '4';", $result);
    }

    // ── getDown ───────────────────────────────────────────────────────────

    public function testGetDownWithDiffOpChange(): void
    {
        $obj = $this->makeObj('users', [
            'name' => new DiffOpChange('old_name', 'new_name'),
        ], ['id' => '1']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getDown();

        $this->assertSame("UPDATE `users` SET `name` = 'old_name' WHERE `id` = '1';", $result);
    }

    public function testGetDownWithDiffOpChangeNullOldValue(): void
    {
        $obj = $this->makeObj('users', [
            'email' => new DiffOpChange(null, 'new@test.com'),
        ], ['id' => '2']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getDown();

        $this->assertSame("UPDATE `users` SET `email` = NULL WHERE `id` = '2';", $result);
    }

    public function testGetDownWithDiffOpAddDoesNotCrash(): void
    {
        // DiffOpAdd has no getOldValue() — this was the crash in Bug #2
        $obj = $this->makeObj('users', [
            'new_col' => new DiffOpAdd('added_value'),
        ], ['id' => '3']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getDown();

        $this->assertSame("UPDATE `users` SET `new_col` = NULL WHERE `id` = '3';", $result);
    }

    public function testGetDownWithDiffOpRemoveDoesNotCrash(): void
    {
        $obj = $this->makeObj('users', [
            'old_col' => new DiffOpRemove('removed_value'),
        ], ['id' => '4']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $result = $sql->getDown();

        $this->assertSame("UPDATE `users` SET `old_col` = 'removed_value' WHERE `id` = '4';", $result);
    }

    // ── Mixed DiffOp types in a single UPDATE ─────────────────────────────

    public function testMixedDiffOpTypes(): void
    {
        $obj = $this->makeObj('users', [
            'name'  => new DiffOpChange('old', 'new'),
            'bio'   => new DiffOpAdd('new_bio'),
            'phone' => new DiffOpRemove('old_phone'),
        ], ['id' => '5']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());

        $up = $sql->getUp();
        $this->assertStringContainsString("`name` = 'new'", $up);
        $this->assertStringContainsString("`bio` = 'new_bio'", $up);
        $this->assertStringContainsString('`phone` = NULL', $up);

        $down = $sql->getDown();
        $this->assertStringContainsString("`name` = 'old'", $down);
        $this->assertStringContainsString('`bio` = NULL', $down);
        $this->assertStringContainsString("`phone` = 'old_phone'", $down);
    }
}
