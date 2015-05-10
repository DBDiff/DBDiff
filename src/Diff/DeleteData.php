<?php namespace DBDiff\Diff;


class DeleteData {

    function __construct($table, $diff) {
        $this->table = $table;
        $this->diff = $diff;
    }
}
