<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;


class DropTable {
    use InSchema;

    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    public ?int $sortOrder = null;

    /** @var \DBDiff\DB\DBManager */
    public $manager;

    /** @var list<string> foreign keys left out of the table, added by a change of their own */
    public array $withoutConstraints = [];
    public $connectionName;

    public function __construct($table, $manager, string $connectionName = 'target') {
        $this->table          = $table;
        $this->manager        = $manager;
        $this->connectionName = $connectionName;
    }
}
