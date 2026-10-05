<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class DropExtension {
    use InSchema;

    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public ?int $sortOrder = null;

    /** `CREATE EXTENSION ...` as the side that has it installs it. */
    public $definition;

    public function __construct(string $name, string $definition) {
        $this->name       = $name;
        $this->definition = $definition;
    }
}
