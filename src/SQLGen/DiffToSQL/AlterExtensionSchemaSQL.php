<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

class AlterExtensionSchemaSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return 'ALTER EXTENSION ' . $this->dialect->quote($this->obj->name) . ' SET SCHEMA ' . $this->dialect->quote($this->obj->toSchema) . ';';
    }

    public function getDown(): string {
        return 'ALTER EXTENSION ' . $this->dialect->quote($this->obj->name) . ' SET SCHEMA ' . $this->dialect->quote($this->obj->fromSchema) . ';';
    }
}
