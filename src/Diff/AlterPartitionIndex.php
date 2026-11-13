<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/**
 * A partition's index attached to its parent's, named or optioned
 * differently on the two sides (PostgreSQL). Matched by the parent index it
 * is attached to, so it is renamed and given its options rather than dropped
 * and made again, which PostgreSQL refuses for an attached index.
 */
class AlterPartitionIndex {
    use InSchema;

    public $table;
    public $column = null;
    public $key = null;
    public $name = null;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    /**
     * @param array{name: string, options: ?string} $sourceIndex
     * @param array{name: string, options: ?string} $targetIndex
     */
    public function __construct(string $table, public string $parentIndex, public array $sourceIndex, public array $targetIndex) {
        $this->table = $table;
        $this->key = $sourceIndex['name'];
    }
}
