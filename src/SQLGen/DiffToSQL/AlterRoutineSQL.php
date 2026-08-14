<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterRoutineSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $drop = RoutineDrop::build($this->obj->targetDefinition, $this->obj->name, $this->dialect);
        return $drop . "\n" . $this->obj->sourceDefinition . ';';
    }

    public function getDown(): string {
        $drop = RoutineDrop::build($this->obj->sourceDefinition, $this->obj->name, $this->dialect);
        return $drop . "\n" . $this->obj->targetDefinition . ';';
    }

}
