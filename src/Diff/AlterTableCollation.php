<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class AlterTableCollation {
    use InSchema;

    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    public $collation;
    public $prevCollation;

    function __construct($table, $collation, $prevCollation) {
        $this->table  = $table;
        $this->collation = $collation;
        $this->prevCollation = $prevCollation;
    }
}
