<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/** An index named after a renamed column, renamed with it — see ColumnRenames::carried(). */
class AlterTableRenameKey {
    use InSchema;

    public $table;
    public $column = null;
    public $key;
    public $name = null;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public function __construct(string $table, string $key, public string $from) {
        $this->table = $table;
        $this->key   = $key;
    }
}
