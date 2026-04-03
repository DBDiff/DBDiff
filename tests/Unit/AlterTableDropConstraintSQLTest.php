<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\Exceptions\InvalidConstraintException;
use DBDiff\SQLGen\DiffToSQL\AlterTableDropConstraintSQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableAddConstraintSQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableChangeConstraintSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\Dialect\SQLiteDialect;

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

    // ── Bug #17: MySQL DROP FOREIGN KEY syntax ─────────────────────────────

    public function testMySQLDropForeignKeyUsesCorrectSyntax(): void
    {
        $fkDef  = 'CONSTRAINT `fk_orders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)';
        $diffOp = new \Diff\DiffOp\DiffOpRemove($fkDef);
        $obj    = $this->makeObj('orders', 'fk_orders_user_id', $diffOp);
        $sql    = new AlterTableDropConstraintSQL($obj, new MySQLDialect());

        $this->assertSame(
            'ALTER TABLE `orders` DROP FOREIGN KEY `fk_orders_user_id`;',
            $sql->getUp()
        );
    }

    public function testMySQLDropNonFKConstraintUsesDropConstraint(): void
    {
        $checkDef = 'CONSTRAINT `chk_age` CHECK (`age` > 0)';
        $diffOp   = new \Diff\DiffOp\DiffOpRemove($checkDef);
        $obj      = $this->makeObj('users', 'chk_age', $diffOp);
        $sql      = new AlterTableDropConstraintSQL($obj, new MySQLDialect());

        $this->assertSame(
            'ALTER TABLE `users` DROP CONSTRAINT `chk_age`;',
            $sql->getUp()
        );
    }

    public function testPostgresDropFKUsesStandardSyntax(): void
    {
        $fkDef  = 'CONSTRAINT "fk_orders_user_id" FOREIGN KEY ("user_id") REFERENCES "users"("id")';
        $diffOp = new \Diff\DiffOp\DiffOpRemove($fkDef);
        $obj    = $this->makeObj('orders', 'fk_orders_user_id', $diffOp);
        $sql    = new AlterTableDropConstraintSQL($obj, new PostgresDialect());

        $this->assertSame(
            'ALTER TABLE "orders" DROP CONSTRAINT "fk_orders_user_id";',
            $sql->getUp()
        );
    }

    public function testSQLiteDropFKUsesStandardSyntax(): void
    {
        $fkDef  = 'CONSTRAINT "fk_orders_0" FOREIGN KEY ("user_id") REFERENCES "users" ("id")';
        $diffOp = new \Diff\DiffOp\DiffOpRemove($fkDef);
        $obj    = $this->makeObj('orders', 'fk_orders_0', $diffOp);
        $sql    = new AlterTableDropConstraintSQL($obj, new SQLiteDialect());

        $this->assertSame(
            'ALTER TABLE "orders" DROP CONSTRAINT "fk_orders_0";',
            $sql->getUp()
        );
    }

    // ── getDown restores constraint ────────────────────────────────────────

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

    // ── Empty / null name validation ───────────────────────────────────────

    public function testGetUpThrowsOnEmptyName(): void
    {
        $diffOp = new \Diff\DiffOp\DiffOpRemove('FOREIGN KEY (`col`) REFERENCES `other`(`id`)');
        $obj    = $this->makeObj('orders', '', $diffOp);
        $sql    = new AlterTableDropConstraintSQL($obj, new MySQLDialect());

        $this->expectException(InvalidConstraintException::class);
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

        $this->expectException(InvalidConstraintException::class);
        $sql->getUp();
    }

    // ── AlterTableAddConstraintSQL: getDown uses dialect ───────────────────

    public function testAddConstraintDownMySQLDropsForeignKey(): void
    {
        $fkDef  = 'CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)';
        $diffOp = new \Diff\DiffOp\DiffOpAdd($fkDef);
        $obj    = $this->makeObj('posts', 'fk_posts_user', $diffOp);
        $sql    = new AlterTableAddConstraintSQL($obj, new MySQLDialect());

        $this->assertSame(
            'ALTER TABLE `posts` DROP FOREIGN KEY `fk_posts_user`;',
            $sql->getDown()
        );
    }

    public function testAddConstraintDownPostgresUsesDropConstraint(): void
    {
        $fkDef  = 'CONSTRAINT "fk_posts_user" FOREIGN KEY ("user_id") REFERENCES "users"("id")';
        $diffOp = new \Diff\DiffOp\DiffOpAdd($fkDef);
        $obj    = $this->makeObj('posts', 'fk_posts_user', $diffOp);
        $sql    = new AlterTableAddConstraintSQL($obj, new PostgresDialect());

        $this->assertSame(
            'ALTER TABLE "posts" DROP CONSTRAINT "fk_posts_user";',
            $sql->getDown()
        );
    }

    // ── AlterTableChangeConstraintSQL ──────────────────────────────────────

    public function testChangeConstraintMySQLUsesForeignKeyDrop(): void
    {
        $oldDef = 'CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)';
        $newDef = 'CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE';
        $diffOp = new \Diff\DiffOp\DiffOpChange($oldDef, $newDef);
        $obj    = $this->makeObj('orders', 'fk_orders_user', $diffOp);
        $sql    = new AlterTableChangeConstraintSQL($obj, new MySQLDialect());

        $this->assertSame(
            "ALTER TABLE `orders` DROP FOREIGN KEY `fk_orders_user`;\nALTER TABLE `orders` ADD $newDef;",
            $sql->getUp()
        );
        $this->assertSame(
            "ALTER TABLE `orders` DROP FOREIGN KEY `fk_orders_user`;\nALTER TABLE `orders` ADD $oldDef;",
            $sql->getDown()
        );
    }

    public function testChangeConstraintPostgresUsesDropConstraint(): void
    {
        $oldDef = 'CONSTRAINT "fk_orders_user" FOREIGN KEY ("user_id") REFERENCES "users"("id")';
        $newDef = 'CONSTRAINT "fk_orders_user" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE';
        $diffOp = new \Diff\DiffOp\DiffOpChange($oldDef, $newDef);
        $obj    = $this->makeObj('orders', 'fk_orders_user', $diffOp);
        $sql    = new AlterTableChangeConstraintSQL($obj, new PostgresDialect());

        $this->assertSame(
            "ALTER TABLE \"orders\" DROP CONSTRAINT \"fk_orders_user\";\nALTER TABLE \"orders\" ADD $newDef;",
            $sql->getUp()
        );
    }
}
