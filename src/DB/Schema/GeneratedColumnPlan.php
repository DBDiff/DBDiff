<?php namespace DBDiff\DB\Schema;

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

    private const GENERATED = '/GENERATED\s+ALWAYS\s+AS\s+\((.+)\)\s+STORED/i';

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
        return (bool) preg_match(self::GENERATED, $def);
    }

    /**
     * A generated column staying generated whose expression or type differs —
     * not merely its nullability, which ALTER can change.
     */
    private static function definitionChanges(object $diff): bool {
        if (!($diff instanceof \Diff\DiffOp\DiffOpChange)) {
            return false;
        }
        $old = $diff->getOldValue();
        $new = $diff->getNewValue();
        if (!self::isGenerated($old) || !self::isGenerated($new)) {
            return false;
        }
        preg_match(self::GENERATED, $old, $a);
        preg_match(self::GENERATED, $new, $b);
        return ($a[1] ?? null) !== ($b[1] ?? null)
            || PostgresDialect::changesColumnType($old, $new);
    }

    /** The first of `$columns` the generated definition `$def` reads, if any. */
    private static function readsAny(string $def, array $columns, string $self): ?string {
        if (!preg_match(self::GENERATED, $def, $m)) {
            return null;
        }
        foreach ($columns as $column => $_) {
            if ($column !== $self && preg_match('/\b' . preg_quote($column, '/') . '\b/', $m[1])) {
                return $column;
            }
        }
        return null;
    }
}
