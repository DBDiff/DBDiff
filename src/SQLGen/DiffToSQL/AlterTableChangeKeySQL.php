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
        if (starts_with($schema, 'PRIMARY KEY')) {
            $key = 'PRIMARY KEY';
        } else {
            $key = "INDEX `$key`";
        }
        return "ALTER TABLE `$table` DROP $key, ADD $schema;";
    }

    public function getDown() {
        $table = $this->obj->table;
        $key = $this->obj->key;
        $schema = $this->obj->diff->getOldValue();
        if (starts_with($schema, 'PRIMARY KEY')) {
            $key = 'PRIMARY KEY';
        } else {
            $key = "INDEX `$key`";
        }
        return "ALTER TABLE `$table` DROP $key, ADD $schema;";
    }

}
