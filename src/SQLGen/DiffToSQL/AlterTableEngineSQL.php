<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class AlterTableEngineSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        $engine = $this->obj->engine;
        return "ALTER TABLE `$table` ENGINE = $engine;";
    }

    public function getDown() {
        $table = $this->obj->table;
        $prevEngine = $this->obj->prevEngine;
        return "ALTER TABLE `$table` ENGINE = $prevEngine;";
    }

}
