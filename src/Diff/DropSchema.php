<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class DropSchema {
    use InSchema;

    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public ?int $sortOrder = null;

    public function __construct(string $name) {
        $this->name = $name;
    }
}
