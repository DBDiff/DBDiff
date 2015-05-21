<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class SetDBCollationSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $db = $this->obj->db;
        $collation = $this->obj->collation;
        return "ALTER DATABASE `$db` COLLATE $collation;";
    }

    public function getDown() {
        $db = $this->obj->db;
        $prevCollation = $this->obj->prevCollation;
        return "ALTER DATABASE `$db` COLLATE $prevCollation;";
    }

}
