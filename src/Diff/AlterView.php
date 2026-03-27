<?php namespace DBDiff\Diff;


class AlterView {
    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public $sourceDefinition;
    public $targetDefinition;

    public function __construct(string $name, string $sourceDefinition, string $targetDefinition) {
        $this->name             = $name;
        $this->sourceDefinition = $sourceDefinition;
        $this->targetDefinition = $targetDefinition;
    }
}
