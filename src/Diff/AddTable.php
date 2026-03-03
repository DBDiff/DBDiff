<?php namespace DBDiff\Diff;


class AddTable {
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

    public function __construct($table, $manager, string $connectionName = 'source') {
        $this->table          = $table;
        $this->manager        = $manager;
        $this->connectionName = $connectionName;
    }
}
