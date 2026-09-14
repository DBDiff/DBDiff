<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class DropPolicySQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return 'DROP POLICY IF EXISTS ' . $this->dialect->quote($this->obj->name)
            . ' ON ' . $this->dialect->quote($this->obj->table) . ';';
    }

    public function getDown(): string {
        return $this->obj->definition . ';';
    }
}
