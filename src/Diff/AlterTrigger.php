<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class AlterTrigger {
    use InSchema;

    public $table;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;
    /**
     * Set when a column type change's DOWN recreates this object instead —
     * see ColumnDependantPlan. Its own DOWN is then left out.
     */
    public bool $downHandledElsewhere = false;
    /**
     * Set when a column type change recreates this object's target version
     * in its DOWN — see ColumnDependantPlan. Its own DOWN then only drops it.
     */
    public bool $downDropOnly = false;

    public $sourceDefinition;
    public $targetDefinition;

    public function __construct(string $name, string $table, string $sourceDefinition, string $targetDefinition) {
        $this->name             = $name;
        $this->table            = $table;
        $this->sourceDefinition = $sourceDefinition;
        $this->targetDefinition = $targetDefinition;
    }
}
