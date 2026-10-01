<?php

namespace DBDiff\Tests\Unit;

use DBDiff\Diff\AlterTableAddKey;
use DBDiff\Diff\AlterTableChangeConstraint;
use DBDiff\Diff\AlterTableColumnStorage;
use DBDiff\Diff\AlterTableDropConstraint;
use DBDiff\Diff\AlterTableDropKey;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\DiffToSQL\AlterTableAddKeySQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableChangeConstraintSQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableColumnStorageSQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableDropConstraintSQL;
use DBDiff\SQLGen\DiffToSQL\AlterTableDropKeySQL;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpChange;
use Diff\DiffOp\DiffOpRemove;
use PHPUnit\Framework\TestCase;

/**
 * Column STORAGE (issue #225), and the DROPs an enum swap may have run
 * before them (issue #237).
 */
class ColumnStorageAndDropIfExistsSQLTest extends TestCase
{
    public function testStorageIsSetBothWays(): void
    {
        $sql = new AlterTableColumnStorageSQL(new AlterTableColumnStorage('t', 'b', 'EXTERNAL', 'EXTENDED'), new PostgresDialect());

        $this->assertSame('ALTER TABLE "t" ALTER COLUMN "b" SET STORAGE EXTERNAL;', $sql->getUp());
        $this->assertSame('ALTER TABLE "t" ALTER COLUMN "b" SET STORAGE EXTENDED;', $sql->getDown());
    }

    public function testANewColumnsStorageHasNoDown(): void
    {
        // The DOWN drops the column itself.
        $sql = new AlterTableColumnStorageSQL(new AlterTableColumnStorage('t', 'b', 'MAIN', 'EXTENDED', true), new PostgresDialect());

        $this->assertSame('', $sql->getDown());
    }

    public function testDropsAreStrictUnlessASwapGotThereFirst(): void
    {
        $dialect = new PostgresDialect();
        $drop = new AlterTableDropConstraint('t', 'ck', new DiffOpRemove('CONSTRAINT "ck" CHECK (true)'));
        $key  = new AlterTableDropKey('t', 'i', new DiffOpRemove('CREATE INDEX i ON t (a)'));

        $this->assertSame('ALTER TABLE "t" DROP CONSTRAINT "ck";', (new AlterTableDropConstraintSQL($drop, $dialect))->getUp());
        $this->assertSame('DROP INDEX "i";', (new AlterTableDropKeySQL($key, $dialect))->getUp());

        $drop->dropIfExists = $key->dropIfExists = true;
        $this->assertSame('ALTER TABLE "t" DROP CONSTRAINT IF EXISTS "ck";', (new AlterTableDropConstraintSQL($drop, $dialect))->getUp());
        $this->assertSame('DROP INDEX IF EXISTS "i";', (new AlterTableDropKeySQL($key, $dialect))->getUp());
    }

    public function testChangesAndAddsDownsHonourIt(): void
    {
        $dialect = new PostgresDialect();
        $change = new AlterTableChangeConstraint('t', 'ck', new DiffOpChange('CONSTRAINT "ck" CHECK (a)', 'CONSTRAINT "ck" CHECK (b)'));
        $change->dropIfExists = true;
        $add = new AlterTableAddKey('t', 'i', new DiffOpAdd('CREATE INDEX i ON t (a)'));
        $add->dropIfExists = true;

        $this->assertStringStartsWith('ALTER TABLE "t" DROP CONSTRAINT IF EXISTS "ck";', (new AlterTableChangeConstraintSQL($change, $dialect))->getUp());
        $this->assertSame('DROP INDEX IF EXISTS "i";', (new AlterTableAddKeySQL($add, $dialect))->getDown());
    }
}
