<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class AlterTableDropColumnSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        $column = $this->obj->column;
        return "ALTER TABLE `$table` DROP `$column`;";
    }

    public function getDown() {
        $table = $this->obj->table;
        $schema = $this->obj->diff->getOldValue();
        return "ALTER TABLE `$table` ADD $schema;";
    }

}
