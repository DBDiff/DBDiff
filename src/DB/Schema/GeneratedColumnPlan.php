<?php namespace DBDiff\DB\Schema;

use DBDiff\DB\Support\PostgresColumnDefinition as Column;
use DBDiff\SQLGen\Dialect\PostgresDialect;

/**
 * How each changing stored generated column of a table is applied — PostgreSQL.
 *
 * A generated column can be neither retyped under nor given a new expression
 * in place on every supported server, and PostgreSQL refuses to retype a column
 * one reads:
 *
 *     ERROR:  cannot alter type of a column used by a generated column
 *
 * so a generated column is dropped and re-added — recomputed from its
 * expression, no data lost — inside the bracket of the change that needs it,
 * with whatever reads it standing aside (issue #233). Three cases:
 *
 *   - `attach`: its definition changes *and* it reads a column whose type
 *     changes. It is regenerated inside that column's change, from the
 *     source's definition on the way up.
 *   - `regenerate`: its definition (expression or type) changes and nothing
 *     it reads is retyped. It is regenerated in a change of its own. Before
 *     this, an expression change emitted only a stray `DROP NOT NULL` and the
 *     new expression was never applied.
 *   - `dropFirst`: the source removes it while a column it reads is retyped;
 *     its own DROP has to run before that change.
 *
 * A generated column whose definition is unchanged but which reads a retyped
 * column is not in the diff at all; the retyped column's dependant lookup
 * finds it from the catalog.
 */
final class GeneratedColumnPlan {

    /**
     * @param array<string, object> $diffs  column name => DiffOp, target → source
     * @return array{attach: array<string, string>, regenerate: array<string, true>, dropFirst: array<string, true>}
     */
    public static function plan(array $diffs): array {
        $retyped = self::retypedColumns($diffs);
        $plan = ['attach' => [], 'regenerate' => [], 'dropFirst' => []];
        foreach ($diffs as $column => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpRemove) {
                if (self::readsAny($diff->getOldValue(), $retyped, $column) !== null) {
                    $plan['dropFirst'][$column] = true;
                }
            } elseif (self::definitionChanges($diff)) {
                $old = $diff->getOldValue();
                $new = $diff->getNewValue();
                $base = self::readsAny($old, $retyped, $column) ?? self::readsAny($new, $retyped, $column);
                if ($base !== null) {
                    $plan['attach'][$column] = $base;
                } else {
                    $plan['regenerate'][$column] = true;
                }
            }
        }
        return $plan;
    }

    /** The plain (non-generated) columns whose type changes. */
    private static function retypedColumns(array $diffs): array {
        $retyped = [];
        foreach ($diffs as $column => $diff) {
            if ($diff instanceof \Diff\DiffOp\DiffOpChange
                && !self::isGenerated($diff->getOldValue())
                && PostgresDialect::changesColumnType($diff->getOldValue(), $diff->getNewValue())) {
                $retyped[$column] = true;
            }
        }
        return $retyped;
    }

    public static function isGenerated(string $def): bool {
        return Column::parse($def)->isGenerated();
    }

    /**
     * A change PostgreSQL can only make, in one direction or the other, by
     * dropping and re-adding the column. Both directions count: a generated
     * column becoming a plain one is a DROP EXPRESSION on the way up, but its
     * DOWN gives a plain column an expression, which only a drop and re-add
     * can. Which direction regenerates is decided when the SQL is written.
     */
    private static function definitionChanges(object $diff): bool {
        return $diff instanceof \Diff\DiffOp\DiffOpChange
            && (Column::needsRegenerating($diff->getOldValue(), $diff->getNewValue())
                || Column::needsRegenerating($diff->getNewValue(), $diff->getOldValue()));
    }

    /** The first of `$columns` the generated definition `$def` reads, if any. */
    private static function readsAny(string $def, array $columns, string $self): ?string {
        $expression = Column::parse($def)->generated;
        if ($expression === null) {
            return null;
        }
        foreach ($columns as $column => $_) {
            if ($column !== $self && preg_match('/\b' . preg_quote($column, '/') . '\b/', $expression)) {
                return $column;
            }
        }
        return null;
    }
}
