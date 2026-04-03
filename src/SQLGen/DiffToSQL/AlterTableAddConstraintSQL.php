<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableAddConstraintSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $schema = $this->obj->diff->getNewValue();
        return "ALTER TABLE $t ADD $schema;";
    }

    public function getDown(): string {
        $schema = $this->obj->diff->getNewValue();
        return $this->dialect->dropConstraint($this->obj->table, $this->obj->name, $schema);
    }

}
