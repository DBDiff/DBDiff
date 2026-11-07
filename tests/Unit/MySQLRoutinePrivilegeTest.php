<?php

namespace DBDiff\Tests\Unit;

use DBDiff\DB\Adapters\MySQLAdapter;
use DBDiff\Exceptions\DBException;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * A MySQL user with every privilege on the schema but without SHOW_ROUTINE
 * sees a routine listed, yet `SHOW CREATE PROCEDURE` gives NULL for its body
 * (#286). The rows below are what MySQL 8.4 returns to such a user.
 */
class MySQLRoutinePrivilegeTest extends TestCase
{
    private function connection(?string $body): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabaseName')->willReturn('app');
        $connection->method('select')->willReturnCallback(
            fn (string $sql) => str_contains($sql, 'information_schema.ROUTINES')
                ? [['ROUTINE_NAME' => 'refresh_totals', 'ROUTINE_TYPE' => 'PROCEDURE']]
                : [['Procedure' => 'refresh_totals', 'Create Procedure' => $body]]
        );
        return $connection;
    }

    public function testAHiddenRoutineBodyStopsTheDiffAndNamesTheGrant(): void
    {
        try {
            (new MySQLAdapter())->getRoutines($this->connection(null));
            $this->fail('A routine whose body MySQL hid was read as if it had one');
        } catch (DBException $e) {
            $this->assertStringContainsString('procedure `app`.`refresh_totals`', $e->getMessage());
            $this->assertStringContainsString('SHOW_ROUTINE', $e->getMessage());
        }
    }

    public function testAReadableRoutineIsReturned(): void
    {
        $routines = (new MySQLAdapter())->getRoutines($this->connection(
            'CREATE DEFINER=`root`@`localhost` PROCEDURE `refresh_totals`() BEGIN SELECT 1; END'
        ));
        $this->assertSame(['refresh_totals' => 'CREATE PROCEDURE `refresh_totals`() BEGIN SELECT 1; END'], $routines);
    }
}
