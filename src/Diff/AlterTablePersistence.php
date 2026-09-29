<?php namespace DBDiff\Diff;


/**
 * A table changing between LOGGED and UNLOGGED.
 *
 * Not decoration: an unlogged table is not crash-safe and is emptied on
 * recovery, so a table that is unlogged in one environment and logged in the
 * other is a durability difference (issue #229).
 */
class AlterTablePersistence {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    /** True when the source table is UNLOGGED. */
    public bool $unlogged;
    /** True when the target table is UNLOGGED. */
    public bool $prevUnlogged;

    public function __construct($table, bool $unlogged, bool $prevUnlogged) {
        $this->table        = $table;
        $this->unlogged     = $unlogged;
        $this->prevUnlogged = $prevUnlogged;
    }
}
