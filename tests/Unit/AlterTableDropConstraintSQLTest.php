<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffToSQL\AlterTableDropConstraintSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;

class AlterTableDropConstraintSQLTest extends TestCase
{
    private function makeObj(string $table, string $name, $diff = null): object
    {
        return (object) [
            'table' => $table,
            'name'  => $name,
            'diff'  => $diff,
        ];
    }

    public function testGetUpWithValidName(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('FOREIGN KEY (`col`) REFERENCES `other`(`id`)');
        $obj    = $this->makeObj('orders', 'fk_orders_user_id', $diffOp);
        $sql    = new AlterTableDropConstraintSQL($obj, new MySQLDialect());

        $this->assertSame(
            'ALTER TABLE `orders` DROP CONSTRAINT `fk_orders_user_id`;',
            $sql->getUp()
        );
    }

    public function testGetDownRestoresConstraint(): void
    {
        $constraint = 'CONSTRAINT `fk_orders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)';
        $diffOp     = new \Diff\DiffOp\DiffOpRemove($constraint);
        $obj        = $this->makeObj('orders', 'fk_orders_user_id', $diffOp);
        $sql        = new AlterTableDropConstraintSQL($obj, new MySQLDialect());

        $this->assertSame(
            "ALTER TABLE `orders` ADD $constraint;",
            $sql->getDown()
        );
    }

    public function testGetUpThrowsOnEmptyName(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('FOREIGN KEY (`col`) REFERENCES `other`(`id`)');
        $obj    = $this->makeObj('orders', '', $diffOp);
        $sql    = new AlterTableDropConstraintSQL($obj, new MySQLDialect());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('constraint name is empty');
        $sql->getUp();
    }

    public function testGetUpThrowsOnNullName(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('FOREIGN KEY (`col`) REFERENCES `other`(`id`)');
        $obj    = (object) [
            'table' => 'orders',
            'name'  => null,
            'diff'  => $diffOp,
        ];
        $sql = new AlterTableDropConstraintSQL($obj, new MySQLDialect());

        $this->expectException(\RuntimeException::class);
        $sql->getUp();
    }
}
