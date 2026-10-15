<?php namespace DBDiff\Diff\Concerns;

/**
 * A PostgreSQL object created by one statement and removed by another that
 * names it its own way — `DROP RULE r ON t`, `DROP CAST (a AS b)`,
 * `DROP USER MAPPING FOR u SERVER s` — so each carries both, as read from
 * the catalog. See PostgresCatalogObjects.
 */
trait SchemaObject {
    use InSchema;

    public $table = null;
    public $column = null;
    public $key = null;
    public $name;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    /** The statement that creates it. */
    public string $definition;
    /** The statement that removes it. */
    public string $drop;

    /** @param array{create: string, drop: string, table?: ?string} $object */
    public function __construct(string $name, array $object) {
        $this->name       = $name;
        $this->definition = $object['create'];
        $this->drop       = $object['drop'];
        $this->table      = $object['table'] ?? null;
    }
}
