<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class AlterView {
    use InSchema;

    public $table = null;
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
     * The grants to put back once the view is recreated: `up` the source's,
     * `down` the target's, with the target's owner and default grantees —
     * see PostgresViewGrants.
     */
    public ?array $privileges = null;

    /**
     * Whether CREATE OR REPLACE VIEW can put the new definition in place, by
     * direction — see ViewReplacementPlan.
     *
     * @var array{up?: bool, down?: bool}
     */
    public array $inPlace = [];

    /**
     * What has to stand aside while the view is dropped and created again,
     * by direction: `drop` and `recreate` dependant sets, the `skip` keys
     * another diff creates later, and the view's own `triggers` to put back
     * — see ViewReplacementPlan.
     */
    public array $replacement = [];

    public $sourceDefinition;
    public $targetDefinition;

    public function __construct(string $name, string $sourceDefinition, string $targetDefinition) {
        $this->name             = $name;
        $this->sourceDefinition = $sourceDefinition;
        $this->targetDefinition = $targetDefinition;
    }
}
