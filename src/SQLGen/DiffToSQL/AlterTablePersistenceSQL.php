<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTablePersistenceSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->statement($this->obj->unlogged);
    }

    public function getDown(): string {
        return $this->statement($this->obj->prevUnlogged);
    }

    private function statement(bool $unlogged): string {
        $t = $this->dialect->qualify($this->obj->table);
        return "ALTER TABLE $t SET " . ($unlogged ? 'UNLOGGED' : 'LOGGED') . ';';
    }
}
