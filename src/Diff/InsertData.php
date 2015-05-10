<?php namespace DBDiff\Diff;


class InsertData {

    function __construct($table, $diff) {
        $this->table = $table;
        $this->diff = $diff;
    }
}
