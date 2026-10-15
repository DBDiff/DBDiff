<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/** A column the source renamed: kept, with its data — see ColumnRenames. */
class AlterTableRenameColumn {
    use InSchema;

    public $table;
    public $column;
    public $key = null;
    public $name = null;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public function __construct(string $table, string $column, public string $from) {
        $this->table  = $table;
        $this->column = $column;
    }
}
