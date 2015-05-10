<?php namespace DBDiff\Diff;


class AlterTableDropKey {

    function __construct($table, $key, $diff) {
        $this->table = $table;
        $this->key = $key;
        $this->diff = $diff;
    }
}
