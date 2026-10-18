<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class AlterTableAddKey {
    use InSchema;

    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    /** Its DROP may find the object gone — an enum swap could not put it back (EnumSwapPlan). */
    public bool $dropIfExists = false;

    /** MySQL: a foreign key's columns lead it, so its drop waits for the keys added — see TableSchema::keyDiffs. */
    public bool $forForeignKey = false;

    function __construct($table, $key, $diff) {
        $this->table = $table;
        $this->key = $key;
        $this->diff = $diff;
    }
}
