<?php namespace DBDiff\Diff;


class SetDBCollation {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    function __construct($db, $collation, $prevCollation) {
        $this->db = $db;
        $this->collation = $collation;
        $this->prevCollation = $prevCollation;
    }
}
