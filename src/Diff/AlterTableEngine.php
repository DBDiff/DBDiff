<?php namespace DBDiff\Diff;


class AlterTableEngine {

    function __construct($table, $engine, $prevEngine) {
        $this->table  = $table;
        $this->engine = $engine;
        $this->prevEngine = $prevEngine;
    }
}
