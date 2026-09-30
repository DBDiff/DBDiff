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
     * What reads this column on the target, when its type changes.
     *
     * PostgreSQL refuses `ALTER COLUMN ... TYPE` while a view, policy or
     * trigger condition reads the column, so a type change drops these and
     * puts them back around it (issue #226). Null on MySQL and SQLite, which
     * have no such restriction, and whenever the type is not what changed.
     * Shape: see PostgresColumnDependants::find().
     *
     * @var array<string, mixed>|null
     */
    public ?array $dependants = null;

    /**
     * The column is inherited — a partition's, or a child's under INHERITS.
     *
     * Its type follows the parent's: the parent's `ALTER COLUMN ... TYPE`
     * reaches it, and one of its own is refused ("cannot alter inherited
     * column"), so none is emitted for it (issue #232). Anything else about
     * the column — a partition-local default — still is.
     */
    public bool $typeInherited = false;

    /**
     * A stored generated column whose own definition changes, applied by
     * dropping and re-adding it inside its dependants' bracket rather than
     * by any ALTER — see GeneratedColumnPlan (issue #233).
     */
    public bool $regenerated = false;

    /**
     * Dependants the UP leaves alone, keyed by `schema.name` (policies and
     * triggers: `schema.table.name`): those another diff in the migration
     * drops or changes, which that diff then handles itself. Recreating them
     * here brought a dropped view back to life, and a changed policy's old
     * expression can be invalid against the column's new type. Filled in by
     * ColumnDependantPlan once every diff is known.
     *
     * @var array<string, true>
     */
    public array $upSkip = [];

    function __construct($table, $column, $diff) {
        $this->table = $table;
        $this->column = $column;
        $this->diff = $diff;
    }
}
