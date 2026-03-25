<?php

namespace DBDiff\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DBDiff\SQLGen\DiffToSQL\AlterTableEngineSQL;
use DBDiff\SQLGen\Dialect\MySQLDialect;

class AlterTableEngineSQLTest extends TestCase
{
    private function makeObj(string $table, $engine, $prevEngine): object
    {
        return (object) [
            'table'      => $table,
            'engine'     => $engine,
            'prevEngine' => $prevEngine,
        ];
    }

    public function testGetUpWithValidEngines(): void
    {
        $obj = $this->makeObj('users', 'InnoDB', 'MyISAM');
        $sql = new AlterTableEngineSQL($obj, new MySQLDialect());

        $this->assertSame('ALTER TABLE `users` ENGINE = InnoDB;', $sql->getUp());
    }

    public function testGetDownWithValidEngines(): void
    {
        $obj = $this->makeObj('users', 'InnoDB', 'MyISAM');
        $sql = new AlterTableEngineSQL($obj, new MySQLDialect());

        $this->assertSame('ALTER TABLE `users` ENGINE = MyISAM;', $sql->getDown());
    }

    public function testGetUpReturnsEmptyWhenEngineIsNull(): void
    {
        $obj = $this->makeObj('users', null, 'MyISAM');
        $sql = new AlterTableEngineSQL($obj, new MySQLDialect());

        $this->assertSame('', $sql->getUp());
    }

    public function testGetUpReturnsEmptyWhenEngineIsEmptyString(): void
    {
        $obj = $this->makeObj('users', '', 'MyISAM');
        $sql = new AlterTableEngineSQL($obj, new MySQLDialect());

        $this->assertSame('', $sql->getUp());
    }

    public function testGetDownReturnsEmptyWhenPrevEngineIsNull(): void
    {
        $obj = $this->makeObj('users', 'InnoDB', null);
        $sql = new AlterTableEngineSQL($obj, new MySQLDialect());

        $this->assertSame('', $sql->getDown());
    }

    public function testGetDownReturnsEmptyWhenPrevEngineIsEmptyString(): void
    {
        $obj = $this->makeObj('users', 'InnoDB', '');
        $sql = new AlterTableEngineSQL($obj, new MySQLDialect());

        $this->assertSame('', $sql->getDown());
    }
}
