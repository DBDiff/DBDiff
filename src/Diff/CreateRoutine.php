<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class CreateRoutine {
    use InSchema;

    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public $definition;
    /**
     * A table's default, generated column, constraint or index calls it, so
     * it is created before the tables — see CreationOrderPlan.
     */
    public bool $early = false;

    public function __construct(string $name, string $definition) {
        $this->name       = $name;
        $this->definition = $definition;
    }
}
