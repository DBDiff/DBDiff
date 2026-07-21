<?php namespace DBDiff\Linter;

use DBDiff\Diff\DropTable;
use DBDiff\Diff\AlterTableDropColumn;
use DBDiff\Diff\AlterTableAddColumn;
use DBDiff\Diff\DropEnum;
use DBDiff\Diff\DropRoutine;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\DropView;

/**
 * Inspects a diff array and returns a LintResult describing every
 * potentially destructive schema change.
 *
 * Violations with level='error' block migration generation unless
 * $params->allowDestructive is true.  Level='warning' items are
 * reported but never block generation.
 */
class DestructiveLinter {
    /**
     * @param array{schema?: list<object>, data?: list<object>} $diff
     */
    public function lint(array $diff): LintResult {
        $schema     = $diff['schema'] ?? [];
        $violations = [];

        // First pass: index drop-column / add-column per table for rename detection
        $dropsByTable = [];
        $addsByTable  = [];
        foreach ($schema as $item) {
            if ($item instanceof AlterTableDropColumn) {
                $dropsByTable[$item->table][] = $item;
            }
            if ($item instanceof AlterTableAddColumn) {
                $addsByTable[$item->table][] = $item;
            }
        }

        // Keys of drop-column items that look like one half of a rename
        $renameKeys = [];
        foreach ($dropsByTable as $table => $drops) {
            $adds = $addsByTable[$table] ?? [];
            foreach ($drops as $drop) {
                foreach ($adds as $add) {
                    if ($this->likelyRename($drop, $add)) {
                        $renameKeys[] = $this->columnKey($table, $drop->column);
                        break;
                    }
                }
            }
        }

        foreach ($schema as $item) {
            $violation = $this->classifyItem($item, $renameKeys);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return new LintResult($violations);
    }

    /**
     * Classify a single schema diff item into a LintViolation, or null if it
     * is not a destructive change.
     *
     * @param object   $item       A diff object from the schema array.
     * @param string[] $renameKeys Column keys that look like one half of a rename.
     */
    private function classifyItem(object $item, array $renameKeys): ?LintViolation {
        if ($item instanceof DropTable) {
            return new LintViolation(
                'error',
                'drop-table',
                "table `{$item->table}`",
                "DROP TABLE `{$item->table}`",
                'Use --allow-destructive to proceed, or archive the table instead.'
            );
        }

        if ($item instanceof AlterTableDropColumn) {
            return $this->classifyDropColumn($item, $renameKeys);
        }

        if ($item instanceof DropEnum) {
            return new LintViolation(
                'warning',
                'drop-enum',
                "enum type `{$item->name}`",
                "DROP TYPE \"{$item->name}\"",
                'Ensure no columns reference this enum before dropping.'
            );
        }

        if ($item instanceof DropRoutine) {
            return new LintViolation(
                'warning',
                'drop-routine',
                "routine `{$item->name}`",
                "DROP FUNCTION/PROCEDURE \"{$item->name}\"",
                'Ensure no code references this routine before dropping.'
            );
        }

        if ($item instanceof DropTrigger) {
            return new LintViolation(
                'warning',
                'drop-trigger',
                "trigger `{$item->name}` on `{$item->table}`",
                "DROP TRIGGER \"{$item->name}\"",
                'Verify that removing this trigger does not break business logic.'
            );
        }

        if ($item instanceof DropView) {
            return new LintViolation(
                'warning',
                'drop-view',
                "view `{$item->name}`",
                "DROP VIEW \"{$item->name}\"",
                'Ensure no queries or applications reference this view.'
            );
        }

        return null;
    }

    /**
     * Classify an AlterTableDropColumn as either a possible-rename (warning)
     * or a true drop-column (error).
     */
    private function classifyDropColumn(AlterTableDropColumn $item, array $renameKeys): LintViolation {
        $key = $this->columnKey($item->table, $item->column);

        if (in_array($key, $renameKeys, true)) {
            return new LintViolation(
                'warning',
                'possible-rename',
                "column `{$item->table}`.`{$item->column}`",
                "ALTER TABLE `{$item->table}` DROP COLUMN `{$item->column}`",
                'Detected a possible column rename. If intentional, use --allow-destructive.'
            );
        }

        return new LintViolation(
            'error',
            'drop-column',
            "column `{$item->table}`.`{$item->column}`",
            "ALTER TABLE `{$item->table}` DROP COLUMN `{$item->column}`",
            'Use --allow-destructive to proceed. Back up data in this column first.'
        );
    }

    private function columnKey(string $table, string $column): string {
        return "{$table}.{$column}";
    }

    /**
     * Heuristic: same table, same data type → probably a rename, not a true drop.
     * The diff sub-object comes from the DiffCalculator and may be a stdClass or array.
     */
    private function likelyRename(AlterTableDropColumn $drop, AlterTableAddColumn $add): bool {
        $dropType = $this->extractType($drop->diff);
        $addType  = $this->extractType($add->diff);
        return $dropType !== null && $addType !== null && strtolower($dropType) === strtolower($addType);
    }

    /** Extracts the column data type from the diff metadata object. */
    private function extractType($diff): ?string {
        if (is_object($diff)) {
            return $diff->Type ?? $diff->DATA_TYPE ?? $diff->type ?? null;
        }
        if (is_array($diff)) {
            return $diff['Type'] ?? $diff['DATA_TYPE'] ?? $diff['type'] ?? null;
        }
        return null;
    }
}
