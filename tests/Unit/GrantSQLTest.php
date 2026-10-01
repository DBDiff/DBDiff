<?php

namespace DBDiff\Tests\Unit;

use DBDiff\SQLGen\DiffToSQL\GrantSQL;
use PHPUnit\Framework\TestCase;

class GrantSQLTest extends TestCase
{
    public function testOneStatementPerGranteeAndGrantOption(): void
    {
        $this->assertSame(
            [
                'GRANT SELECT, INSERT ON "v" TO reader;',
                'GRANT UPDATE ON "v" TO reader WITH GRANT OPTION;',
                'GRANT SELECT ON "v" TO PUBLIC;',
            ],
            GrantSQL::statements([
                ['grantee' => 'reader', 'privilege' => 'SELECT', 'grantable' => false],
                ['grantee' => 'reader', 'privilege' => 'UPDATE', 'grantable' => true],
                ['grantee' => 'reader', 'privilege' => 'INSERT', 'grantable' => false],
                ['grantee' => 'PUBLIC', 'privilege' => 'SELECT', 'grantable' => false],
            ], 'ON "v"')
        );
    }

    public function testNoGrantsNoStatements(): void
    {
        $this->assertSame([], GrantSQL::statements([], 'ON TYPE "e"'));
    }
}
