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

    /**
     * @param array{create: string, drop: string, table?: ?string} $source
     * @param array{create: string, drop: string, table?: ?string} $target
     */
    public function __construct(string $name, array $source, array $target) {
        $this->name             = $name;
        $this->sourceDefinition = $source['create'];
        $this->targetDefinition = $target['create'];
        $this->sourceDrop       = $source['drop'];
        $this->targetDrop       = $target['drop'];
        $this->table            = $source['table'] ?? null;
    }
}
