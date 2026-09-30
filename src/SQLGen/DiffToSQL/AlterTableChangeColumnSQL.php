<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\DB\Support\PostgresColumnDependants;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableChangeColumnSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $newDef = $this->obj->diff->getNewValue();
        $oldDef = $this->obj->diff->getOldValue();
        return $this->aroundDependants(
            $this->statements($newDef, $oldDef),
            $this->obj->upSkip ?? [],
            'up'
        );
    }

    public function getDown(): string {
        $oldDef = $this->obj->diff->getOldValue();
        $newDef = $this->obj->diff->getNewValue();
        return $this->aroundDependants(
            $this->statements($oldDef, $newDef),
            [],
            'down'
        );
    }

    /**
     * The change itself. None for a regenerated generated column: dropping
     * and re-adding it is the change, and ColumnDependantsSQL does both.
     */
    private function statements(string $toDef, string $fromDef): string {
        if (!empty($this->obj->regenerated)) {
            return '';
        }
        return $this->dialect->changeColumn($this->obj->table, $this->obj->column, $toDef, $fromDef);
    }

    /**
     * Wrap a column type change in the drop and recreate of what reads it.
     *
     * PostgreSQL refuses to retype a column any view, policy or trigger
     * condition depends on:
     *
     *     ERROR:  cannot alter type of a column used by a view or rule
     *
     * so the statement was valid SQL that could not run (issue #226). The
     * dependants stand aside and go back as they were — see
     * ColumnDependantsSQL for what "as they were" has to include.
     *
     * Only a type change needs this. `SET NOT NULL`, `SET DEFAULT` and the rest
     * are allowed with views in place, and dropping a view to run one would
     * be destructive for no reason.
     */
    private function aroundDependants(string $statements, array $skip, string $direction): string {
        if (!empty($this->obj->typeInherited)) {
            return self::withoutTypeChange($statements);
        }
        $dependants = $this->obj->dependants ?? null;
        $regenerated = !empty($this->obj->regenerated);
        if ($dependants === null
            || PostgresColumnDependants::isEmpty($dependants)
            || (!$regenerated && !self::changesType($statements))) {
            return $statements;
        }

        $sql = new ColumnDependantsSQL($dependants, $skip, $direction);

        return implode("\n", array_filter(
            array_merge($sql->drops(), [$statements], $sql->recreates()),
            fn(string $line) => $line !== ''
        ));
    }

    /**
     * The statements with any `ALTER COLUMN ... TYPE` removed — for an
     * inherited column, whose type the parent's statement changes.
     */
    private static function withoutTypeChange(string $statements): string {
        $kept = array_filter(
            explode("\n", $statements),
            fn(string $line) => !preg_match(self::TYPE_CHANGE, $line)
        );
        return implode("\n", $kept);
    }

    private const TYPE_CHANGE = '/\bALTER\s+COLUMN\s+(?:"(?:[^"]|"")*"|\S+)\s+TYPE\b/i';

    /** Whether any of these statements retypes a column. */
    private static function changesType(string $statements): bool {
        return (bool) preg_match(self::TYPE_CHANGE, $statements);
    }
}
