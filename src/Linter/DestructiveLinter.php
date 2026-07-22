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
        $renameKeys = $this->detectRenameKeys($schema);

        $violations = [];
        foreach ($schema as $item) {
            $violation = $this->classifyItem($item, $renameKeys);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return new LintResult($violations);
    }

    /**
     * Column keys of drop-column items that look like one half of a rename
     * (a drop paired with an add of the same type on the same table).
     *
     * @param  list<object> $schema
     * @return string[]
     */
    private function detectRenameKeys(array $schema): array {
        $dropsByTable = [];
        $addsByTable  = [];
        foreach ($schema as $item) {
            if ($item instanceof AlterTableDropColumn) {
                $dropsByTable[$item->table][] = $item;
            } elseif ($item instanceof AlterTableAddColumn) {
                $addsByTable[$item->table][] = $item;
            }
        }

        $renameKeys = [];
        foreach ($dropsByTable as $table => $drops) {
            $adds = $addsByTable[$table] ?? [];
            foreach ($drops as $drop) {
                if ($this->hasMatchingAdd($drop, $adds)) {
                    $renameKeys[] = $this->columnKey($table, $drop->column);
                }
            }
        }
        return $renameKeys;
    }

    /** @param AlterTableAddColumn[] $adds */
    private function hasMatchingAdd(AlterTableDropColumn $drop, array $adds): bool {
        foreach ($adds as $add) {
            if ($this->likelyRename($drop, $add)) {
                return true;
            }
        }
        return false;
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

        return $this->classifyDroppedObject($item);
    }

    /**
     * Warning-level violations for dropping standalone objects (enum, routine,
     * trigger, view). Returns null when the item isn't one of those.
     */
    private function classifyDroppedObject(object $item): ?LintViolation {
        $rules = [
            DropEnum::class => [
                'drop-enum', "enum type `{name}`", 'DROP TYPE "{name}"',
                'Ensure no columns reference this enum before dropping.',
            ],
            DropRoutine::class => [
                'drop-routine', "routine `{name}`", 'DROP FUNCTION/PROCEDURE "{name}"',
                'Ensure no code references this routine before dropping.',
            ],
            DropTrigger::class => [
                'drop-trigger', "trigger `{name}` on `{table}`", 'DROP TRIGGER "{name}"',
                'Verify that removing this trigger does not break business logic.',
            ],
            DropView::class => [
                'drop-view', "view `{name}`", 'DROP VIEW "{name}"',
                'Ensure no queries or applications reference this view.',
            ],
        ];

        foreach ($rules as $class => [$kind, $subject, $sql, $advice]) {
            if ($item instanceof $class) {
                $tokens = ['{name}' => $item->name ?? '', '{table}' => $item->table ?? ''];
                return new LintViolation(
                    'warning',
                    $kind,
                    strtr($subject, $tokens),
                    strtr($sql, $tokens),
                    $advice
                );
            }
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
