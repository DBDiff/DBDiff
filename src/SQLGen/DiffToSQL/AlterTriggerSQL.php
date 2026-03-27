<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTriggerSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $drop = $this->dialect->dropTrigger($this->obj->name, $this->obj->table);
        return $drop . "\n" . $this->obj->sourceDefinition . ';';
    }

    public function getDown(): string {
        $drop = $this->dialect->dropTrigger($this->obj->name, $this->obj->table);
        return $drop . "\n" . $this->obj->targetDefinition . ';';
    }
}
