<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class UpdateDataSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        
        $values = $this->obj->diff['diff'];
        array_walk($values, function(&$diff, $column) {
            if(method_exists($diff, 'getNewValue') && !is_null($diff->getNewValue())) {
                $diff = '`' . $column . "` = '" . addslashes($diff->getNewValue()) . "'";
            }
            else {
                $diff = '`' . $column . "` = NULL";
            }
        });
        $values = implode(', ', $values);

        $keys = $this->obj->diff['keys'];
        array_walk($keys, function(&$value, $column) {
            $value = '`'.$column."` = '".addslashes($value)."'";
        });
        $condition = implode(' AND ', $keys);
        
        return "UPDATE `$table` SET $values WHERE $condition;";
    }

    public function getDown() {
        $table = $this->obj->table;
        
        $values = $this->obj->diff['diff'];
        array_walk($values, function(&$diff, $column) {
            if(method_exists($diff, 'getOldValue') && !is_null($diff->getOldValue())) {
                $diff = '`'.$column."` = '".addslashes($diff->getOldValue())."'";
            }
            else {
                $diff = '`' . $column . "` = NULL";
            }
        });
        $values = implode(', ', $values);

        $keys = $this->obj->diff['keys'];
        array_walk($keys, function(&$value, $column) {
            $value = '`'.$column."` = '".addslashes($value)."'";
        });
        $condition = implode(' AND ', $keys);
        
        return "UPDATE `$table` SET $values WHERE $condition;";
    }

}
