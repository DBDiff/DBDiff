<?php namespace DBDiff\Diff;


class DropEnum {
    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public $definition;

    public function __construct(string $name, string $definition) {
        $this->name       = $name;
        $this->definition = $definition;
    }
}
