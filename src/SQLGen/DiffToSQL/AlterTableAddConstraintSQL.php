<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class AlterTableAddConstraintSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        $schema = $this->obj->diff->getNewValue();
        return "ALTER TABLE `$table` ADD $schema;";
    }

    public function getDown() {
        $table = $this->obj->table;
        $name = $this->obj->name;
        return "ALTER TABLE `$table` DROP CONSTRAINT `$name`;";
    }

}
