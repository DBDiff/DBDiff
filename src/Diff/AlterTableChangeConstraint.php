<?php namespace DBDiff\Diff;


class AlterTableChangeConstraint {

    function __construct($table, $name, $diff) {
        $this->table = $table;
        $this->name = $name;
        $this->diff = $diff;
    }
}
