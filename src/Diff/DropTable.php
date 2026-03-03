<?php namespace DBDiff\Diff;


class DropTable {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    /** @var \DBDiff\DB\DBManager */
    public $manager;
    public $connectionName;

    public function __construct($table, $manager, string $connectionName = 'target') {
        $this->table          = $table;
        $this->manager        = $manager;
        $this->connectionName = $connectionName;
    }
}
