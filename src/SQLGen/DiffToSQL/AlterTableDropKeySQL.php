<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class AlterTableDropKeySQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        $key   = $this->obj->key;
        return "ALTER TABLE `$table` DROP INDEX `$key`;";
    }

    public function getDown() {
        $table = $this->obj->table;
        $schema = $this->obj->diff->getOldValue();
        return "ALTER TABLE `$table` ADD $schema;";
    }

}
