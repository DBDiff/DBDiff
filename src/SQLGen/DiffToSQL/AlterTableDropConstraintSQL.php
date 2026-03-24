<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableDropConstraintSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $t    = $this->dialect->quote($this->obj->table);
        $name = $this->dialect->quote($this->obj->name);
        return "ALTER TABLE $t DROP CONSTRAINT $name;";
    }

    public function getDown(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $schema = $this->obj->diff->getOldValue();
        return "ALTER TABLE $t ADD $schema;";
    }

}
