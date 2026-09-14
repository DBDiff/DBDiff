<?php namespace DBDiff\Diff;


/**
 * A change to a table's row level security flags.
 *
 * $isNewTable marks a table that exists only on the source side. Its DOWN
 * migration drops the table outright, so re-stating the flags there would run
 * an ALTER against a relation that is already gone.
 */
class AlterRowSecurity {
    public $table;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public bool $sourceEnabled;
    public bool $sourceForced;
    public bool $targetEnabled;
    public bool $targetForced;
    public bool $isNewTable;

    public function __construct(
        string $table,
        bool $sourceEnabled,
        bool $sourceForced,
        bool $targetEnabled,
        bool $targetForced,
        bool $isNewTable = false
    ) {
        $this->table         = $table;
        $this->name          = $table;
        $this->sourceEnabled = $sourceEnabled;
        $this->sourceForced  = $sourceForced;
        $this->targetEnabled = $targetEnabled;
        $this->targetForced  = $targetForced;
        $this->isNewTable    = $isNewTable;
    }
}
