<?php namespace DBDiff\Diff;


class UpdateData {

    function __construct($table, $diff) {
        $this->table = $table;
        $this->diff = $diff;
    }
}
