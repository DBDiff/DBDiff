<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffToSQL\DropTableSQL;
use DBDiff\SQLGen\DiffToSQL\AddTableSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;

/**
 * Tests that DropTableSQL and AddTableSQL generate correct SQL.
 * The complement of this bug (#6) — views being treated as tables — is
 * fixed at the adapter level (MySQLAdapter::getTables now uses
 * SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'), which is covered
 * by the integration test suite.
 *
 * These unit tests verify the SQL output classes still work correctly.
 */
class ViewsAsTablesSQLTest extends TestCase
{
    public function testDropTableSQLGeneratesCorrectStatement(): void
    {
        $manager = $this->createMock(\DBDiff\DB\DBManager::class);
        $obj = (object) [
            'table'          => 'old_table',
            'manager'        => $manager,
            'connectionName' => 'target',
        ];

        $sql = new DropTableSQL($obj, new MySQLDialect());
        $this->assertSame('DROP TABLE `old_table`;', $sql->getUp());
    }

    public function testAddTableSQLDownGeneratesDropTable(): void
    {
        $manager = $this->createMock(\DBDiff\DB\DBManager::class);
        $obj = (object) [
            'table'          => 'new_table',
            'manager'        => $manager,
            'connectionName' => 'source',
        ];

        $sql = new AddTableSQL($obj, new MySQLDialect());
        $this->assertSame('DROP TABLE `new_table`;', $sql->getDown());
    }
}
