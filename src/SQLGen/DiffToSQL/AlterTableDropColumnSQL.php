<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableDropColumnSQL implements SQLGenInterface {

    protected SQLDialectInterface $dialect;

    function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $table  = $this->obj->table;
        $column = $this->obj->column;
        $t      = $this->dialect->quote($table);
        $c      = $this->dialect->quote($column);
        return "ALTER TABLE $t DROP COLUMN $c;";
    }

    public function getDown(): string {
        $table  = $this->obj->table;
        $t      = $this->dialect->quote($table);
        $schema = $this->obj->diff->getOldValue();
        return "ALTER TABLE $t ADD COLUMN $schema;";
    }

}
