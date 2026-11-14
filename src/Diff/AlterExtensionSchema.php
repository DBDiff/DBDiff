<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/** An extension both sides have, in a different schema on each (PostgreSQL). */
class AlterExtensionSchema {
    use InSchema;

    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public ?int $sortOrder = null;

    public function __construct(string $name, public string $toSchema, public string $fromSchema) {
        $this->name = $name;
    }
}
