<?php namespace DBDiff\SQLGen\Data;

use DBDiff\SQLGen\SQLGenInterface;


class DeleteDataSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function(&$value, $column) {
            $value = '`'.$column."` = '$value'";
        });
        $condition = implode(' AND ', $keys);
        return "DELETE FROM `$table` WHERE $condition;";
    }

    public function getDown() {
        $table = $this->obj->table;
        $values = $this->obj->diff['diff']->getOldValue();
        $values = array_map(function ($el) {
            return "'".$el."'";
        }, $values);
        return "INSERT INTO `$table` VALUES(".implode(',', $values).");";
    }

}
