<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class AddTableSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        $connection = $this->obj->connection;
        $res = $connection->select("SHOW CREATE TABLE `$table`");
        return $res[0]['Create Table'].';';
    }

    public function getDown() {
        $table = $this->obj->table;
        return "DROP TABLE `$table`;";
    }
}
