<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class AlterTableAddColumn {
    use InSchema;

    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    public ?int $ordinal = null;
    public bool $isGenerated = false;

    function __construct($table, $column, $diff) {
        $this->table = $table;
        $this->column = $column;
        $this->diff = $diff;
    }
}
