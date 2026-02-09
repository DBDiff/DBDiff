<?php namespace DBDiff\Diff;


class InsertData {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    function __construct($table, $diff) {
        $this->table = $table;
        $this->diff = $diff;
    }
}
