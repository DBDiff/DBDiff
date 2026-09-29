<?php namespace DBDiff\Diff;


/**
 * A table's storage parameters changing — fillfactor, autovacuum_*, and the
 * rest of `reloptions`.
 *
 * Read for rendering a new table and never compared for one that already
 * existed, so a fillfactor change was reported as no difference (issue #229).
 */
class AlterTableOptions {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    /** The source table's reloptions, as `key = value` pairs, or null. */
    public ?string $options;
    /** The target's, or null. */
    public ?string $prevOptions;

    function __construct($table, ?string $options, ?string $prevOptions) {
        $this->table       = $table;
        $this->options     = $options;
        $this->prevOptions = $prevOptions;
    }
}
