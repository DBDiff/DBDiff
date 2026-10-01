<?php namespace DBDiff\Diff;


class AlterEnum {
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

    /**
     * Per direction ('up', 'down'), what a label removal or reorder carries
     * across to the new type; see EnumSwapPlan. None for an addition.
     */
    public array $swaps = [];

    public function __construct(string $name, string $sourceDefinition, string $targetDefinition) {
        $this->name             = $name;
        $this->sourceDefinition = $sourceDefinition;
        $this->targetDefinition = $targetDefinition;
    }
}
