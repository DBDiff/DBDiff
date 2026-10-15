<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/** A constraint named after a renamed column, renamed with it — see ColumnRenames::carried(). */
class AlterTableRenameConstraint {
    use InSchema;

    public $table;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public function __construct(string $table, string $name, public string $from) {
        $this->table = $table;
        $this->name  = $name;
    }
}
