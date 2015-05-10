<?php namespace DBDiff\Diff;


class TableData {

    function __construct($table, $diff) {
        $this->table  = $table;
        $this->diff = $diff;
    }
}
