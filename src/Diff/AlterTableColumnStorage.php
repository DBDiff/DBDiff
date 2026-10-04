<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


/**
 * A column's storage strategy changing — PLAIN, MAIN, EXTERNAL, EXTENDED.
 *
 * Read only for rendering a new table until #225, so a column moved to
 * EXTERNAL storage (TOAST without compression, the usual choice for large
 * values that are read by substring) reported no difference at all.
 */
class AlterTableColumnStorage {
    use InSchema;

    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    /** The source column's storage. */
    public string $storage;
    /** The target column's storage. */
    public string $prevStorage;
    /**
     * The column is new: its DOWN is dropping it, which takes the storage
     * with it, so there is nothing to put back.
     */
    public bool $newColumn;

    public function __construct(string $table, string $column, string $storage, string $prevStorage, bool $newColumn = false) {
        $this->table       = $table;
        $this->column      = $column;
        $this->storage     = $storage;
        $this->prevStorage = $prevStorage;
        $this->newColumn   = $newColumn;
    }
}
