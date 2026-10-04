<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


/**
 * A comment to set: what to write after COMMENT ON, and the comment each
 * direction sets, as an SQL literal or NULL — or null for no statement, when
 * that direction drops the object or already has the comment.
 */
class AlterComment {
    use InSchema;

    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public ?int $sortOrder = null;

    public function __construct(string $on, public ?string $up, public ?string $down) {
        $this->name = $on;
    }
}
