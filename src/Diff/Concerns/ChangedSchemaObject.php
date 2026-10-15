<?php namespace DBDiff\Diff\Concerns;

/** A SchemaObject both sides have, defined differently: replaced. */
trait ChangedSchemaObject {
    use InSchema;

    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public string $sourceDefinition;
    public string $targetDefinition;
    public string $sourceDrop;
    public string $targetDrop;
    /** Set when the object can be changed where it stands — see ReplaceSchemaObjectSQL. */
    public ?array $sourceInPlace = null;
    public ?array $targetInPlace = null;

    /**
     * @param array{create: string, drop: string, table?: ?string, inPlace?: array{same: string, clear: string, fill: string}} $source
     * @param array{create: string, drop: string, table?: ?string, inPlace?: array{same: string, clear: string, fill: string}} $target
     */
    public function __construct(string $name, array $source, array $target) {
        $this->name             = $name;
        $this->sourceDefinition = $source['create'];
        $this->targetDefinition = $target['create'];
        $this->sourceDrop       = $source['drop'];
        $this->targetDrop       = $target['drop'];
        $this->table            = $source['table'] ?? null;
        $this->sourceInPlace    = $source['inPlace'] ?? null;
        $this->targetInPlace    = $target['inPlace'] ?? null;
    }
}
