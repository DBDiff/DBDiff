<?php namespace DBDiff\Diff;


class AlterTableDropConstraint {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    /** Its DROP may find the object gone — an enum swap could not put it back (EnumSwapPlan). */
    public bool $dropIfExists = false;

    function __construct($table, $name, $diff) {
        $this->table = $table;
        $this->name = $name;
        $this->diff = $diff;
    }
}
