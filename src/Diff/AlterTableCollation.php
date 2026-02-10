<?php namespace DBDiff\Diff;


class AlterTableCollation {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    function __construct($table, $collation, $prevCollation) {
        $this->table  = $table;
        $this->collation = $collation;
        $this->prevCollation = $prevCollation;
    }
}
