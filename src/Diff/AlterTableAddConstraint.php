<?php namespace DBDiff\Diff;


class AlterTableAddConstraint {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    function __construct($table, $name, $diff) {
        $this->table = $table;
        $this->name = $name;
        $this->diff = $diff;
    }
}
