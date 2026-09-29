<?php namespace DBDiff\Diff;


class AlterTableChangeColumn {
    public $table;
    public $column;
    public $key;
    public $name;
    public $diff;
    public $source;
    public $target;
    public bool $isGenerated = false;

    /**
     * Views on the target that read this column, deepest last.
     *
     * PostgreSQL refuses `ALTER COLUMN ... TYPE` while a view reads the column,
     * so a type change drops these and puts them back around it (issue #226).
     * Empty on MySQL and SQLite, which have no such restriction.
     *
     * @var array<int, array{name: string, kind: string, depth: int, definition: string, indexes: string[]}>
     */
    public array $dependentViews = [];

    function __construct($table, $column, $diff) {
        $this->table = $table;
        $this->column = $column;
        $this->diff = $diff;
    }
}
