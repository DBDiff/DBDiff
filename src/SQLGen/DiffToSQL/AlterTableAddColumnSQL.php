<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableAddColumnSQL implements SQLGenInterface {

    protected SQLDialectInterface $dialect;

    function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $table  = $this->obj->table;
        $schema = $this->obj->diff->getNewValue();
        return $this->dialect->addColumn($table, $schema);
    }

    public function getDown(): string {
        $table  = $this->obj->table;
        $column = $this->obj->column;
        return $this->dialect->dropColumn($table, $column);
    }

}
