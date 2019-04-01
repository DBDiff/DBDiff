<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;


class UpdateDataSQL implements SQLGenInterface {

    function __construct($obj) {
        $this->obj = $obj;
    }
    
    public function getUp() {
        $table = $this->obj->table;
        //print_r($this->obj->diff);

        $diffs = $this->obj->diff['diff'];
        $keys  = $this->obj->diff['keys'];
        foreach ($diffs as $k=>$v) {
            if (strpos(get_class($v), 'DiffOpRemove') !== false) {
                unset($diffs[$k]);
            } else {
                if (!is_null($v->getNewValue())) {
                    $diffs[$k] = '`' . $k . "` = '" . addslashes($v->getNewValue()) . "'";
                } else {
                    $diffs[$k] = '`' . $k . "` = NULL";
                }
            }
        }

        if (!$diffs) {
            return '';
        }

        array_walk($keys, function(&$value, $column) {
            $value = '`'.$column."` = '".addslashes($value)."'";
        });

        $values    = implode(', ', $diffs);
        $condition = implode(' AND ', $keys);
        
        return "UPDATE `$table` SET $values WHERE $condition;";
    }

    public function getDown() {
        $table = $this->obj->table;
        
        $values = $this->obj->diff['diff'];
        array_walk($values, function(&$diff, $column) {
            $diff = '`'.$column."` = '".addslashes($diff->getOldValue())."'";
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
