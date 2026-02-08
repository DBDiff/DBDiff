<?php namespace DBDiff\Diff;


class DropTable {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;

    function __construct($table, $connection) {
        $this->table = $table;
        $this->connection = $connection;
    }
}
