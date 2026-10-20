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

    public function testGetUpLeavesOutAColumnOnlyTheTargetHas(): void
    {
        // A column only the target's rows have is one only its table has, and
        // the UP drops it before the rows are updated: naming it failed with
        // "unknown column". With nothing else to set, there is no UPDATE.
        $obj = $this->makeObj('users', [
            'old_col' => new DiffOpRemove('removed_value'),
        ], ['id' => '3']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $this->assertSame('', $sql->getUp());
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

    public function testGetDownLeavesOutAColumnOnlyTheSourceHas(): void
    {
        // The DOWN drops a column only the source has before the rows are put
        // back, so it is not named (DiffOpAdd has no old value to set either).
        $obj = $this->makeObj('users', [
            'new_col' => new DiffOpAdd('added_value'),
        ], ['id' => '3']);

        $sql = new UpdateDataSQL($obj, new MySQLDialect());
        $this->assertSame('', $sql->getDown());
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

        $this->assertSame("UPDATE `users` SET `name` = 'new', `bio` = 'new_bio' WHERE `id` = '5';", $sql->getUp());
        $this->assertSame("UPDATE `users` SET `name` = 'old', `phone` = 'old_phone' WHERE `id` = '5';", $sql->getDown());
    }
}
