<?php namespace DBDiff\Diff;


class AlterTableAddKey {

    function __construct($table, $key, $diff) {
        $this->table = $table;
        $this->key = $key;
        $this->diff = $diff;
    }
}
