<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class AlterTableChangeKeySQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        $key = $this->obj->key;
        $schema = $this->obj->diff->getNewValue();
        return "ALTER TABLE `$table` DROP INDEX `$key`;\nALTER TABLE `$table` ADD $schema;";
    }

    public function getDown() {
        $table = $this->obj->table;
        $key = $this->obj->key;
        $schema = $this->obj->diff->getOldValue();
        return "ALTER TABLE `$table` DROP INDEX `$key`;\nALTER TABLE `$table` ADD $schema;";
    }

}
