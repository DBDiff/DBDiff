<?php namespace DBDiff\Diff;


class AlterTableChangeKey {

    function __construct($table, $key, $diff) {
        $this->table = $table;
        $this->key = $key;
        $this->diff = $diff;
    }
}
