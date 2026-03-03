<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableChangeColumnSQL implements SQLGenInterface {

    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $newDef = $this->obj->diff->getNewValue();
        return $this->dialect->changeColumn($this->obj->table, $this->obj->column, $newDef);
    }

    public function getDown(): string {
        $oldDef = $this->obj->diff->getOldValue();
        return $this->dialect->changeColumn($this->obj->table, $this->obj->column, $oldDef);
    }

}
