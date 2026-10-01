<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\DB\Support\PostgresColumnDependants;
use DBDiff\DB\Support\PostgresColumnDefinition;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\PostgresDialect;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableChangeColumnSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        // Carried by another change's bracket, which emits its statements.
        if ($this->obj->carriedBy !== null) {
            return '';
        }
        return $this->aroundDependants($this->bracketStatements(true), $this->obj->upSkip ?? [], 'up');
    }

    public function getDown(): string {
        if ($this->obj->carriedBy !== null) {
            return '';
        }
        return $this->aroundDependants($this->bracketStatements(false), [], 'down');
    }

    /**
     * The change itself. None for a regenerated generated column: dropping
     * and re-adding it is the change, and ColumnDependantsSQL does both.
     */
    private function statements(string $toDef, string $fromDef): string {
        if ($this->regenerates($toDef, $fromDef)) {
            return '';
        }
        $dialect = $this->dialect instanceof PostgresDialect && !empty($this->obj->serialSequence)
            ? $this->dialect->withSerialSequence($this->obj->serialSequence)
            : $this->dialect;
        return $dialect->changeColumn($this->obj->table, $this->obj->column, $toDef, $fromDef);
    }

    /**
     * Whether this direction drops and re-adds the column. Decided per
     * direction: a plain column becoming a generated one is regenerated on
     * the way up, but on the way down it only needs DROP EXPRESSION, which
     * keeps the values.
     */
    private function regenerates(string $toDef, string $fromDef): bool {
        return !empty($this->obj->regenerated)
            && PostgresColumnDefinition::needsRegenerating($fromDef, $toDef);
    }

    /**
     * This change's statements followed by those of the changes it carries,
     * each in the same direction.
     */
    private function bracketStatements(bool $up): string {
        $all = [];
        foreach (array_merge([$this->obj], $this->obj->coChanges ?? []) as $change) {
            $new = $change->diff->getNewValue();
            $old = $change->diff->getOldValue();
            $sql = (new self($change, $this->dialect))->statements($up ? $new : $old, $up ? $old : $new);
            if ($sql !== '') {
                $all[] = $sql;
            }
        }
        return implode("\n", $all);
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
        [$to, $from] = $direction === 'up'
            ? [$this->obj->diff->getNewValue(), $this->obj->diff->getOldValue()]
            : [$this->obj->diff->getOldValue(), $this->obj->diff->getNewValue()];
        $regenerated = $this->regenerates($to, $from);
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
