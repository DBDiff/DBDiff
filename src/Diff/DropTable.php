<?php namespace DBDiff\Diff;


class DropTable {

    function __construct($table, $connection) {
        $this->table = $table;
        $this->connection = $connection;
    }
}
