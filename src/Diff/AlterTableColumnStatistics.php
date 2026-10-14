<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/** A column's statistics target (`ALTER COLUMN ... SET STATISTICS`); -1 is the default. */
class AlterTableColumnStatistics {
    use InSchema;

    public $table;
    public $column;
    public $key = null;
    public $name = null;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public function __construct(string $table, string $column, public int $statistics, public int $prevStatistics, public bool $newColumn = false) {
        $this->table  = $table;
        $this->column = $column;
    }
}
