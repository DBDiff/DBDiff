<?php namespace DBDiff\Diff;


class DropRoutine {
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
     * A table's default, generated column, constraint or index on the target
     * calls it, so the DOWN recreates it before the tables — see
     * CreationOrderPlan.
     */
    public bool $early = false;

    public function __construct(string $name, string $definition) {
        $this->name       = $name;
        $this->definition = $definition;
    }
}
