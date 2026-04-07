<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterEnumSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $q = $this->dialect->quote($this->obj->name);
        return "DROP TYPE IF EXISTS $q;\n" . $this->obj->sourceDefinition . ';';
    }

    public function getDown(): string {
        $q = $this->dialect->quote($this->obj->name);
        return "DROP TYPE IF EXISTS $q;\n" . $this->obj->targetDefinition . ';';
    }
}
