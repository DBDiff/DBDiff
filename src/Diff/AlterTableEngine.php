<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class AlterTableEngine {
    use InSchema;

    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    public $engine;
    public $prevEngine;

    function __construct($table, $engine, $prevEngine) {
        $this->table  = $table;
        $this->engine = $engine;
        $this->prevEngine = $prevEngine;
    }
}
