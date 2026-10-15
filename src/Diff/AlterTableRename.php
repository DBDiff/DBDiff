<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/** A table the source renamed: kept, with its rows — see TableRenames. */
class AlterTableRename {
    use InSchema;

    public $table;
    public $column = null;
    public $key = null;
    public $name = null;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public function __construct(string $table, public string $from) {
        $this->table = $table;
    }
}
