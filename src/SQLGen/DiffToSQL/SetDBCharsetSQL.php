<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class SetDBCharsetSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $db = $this->obj->db;
        $charset = $this->obj->charset;
        return "ALTER DATABASE `$db` CHARACTER SET $charset;";
    }

    public function getDown() {
        $db = $this->obj->db;
        $prevCharset = $this->obj->prevCharset;
        return "ALTER DATABASE `$db` CHARACTER SET $prevCharset;";
    }

}
