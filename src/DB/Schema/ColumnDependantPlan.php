<?php namespace DBDiff\DB\Schema;

use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\Diff\AlterMatView;
use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterTrigger;
use DBDiff\Diff\AlterView;
use DBDiff\Diff\DropMatView;
use DBDiff\Diff\DropPolicy;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\DropView;

/**
 * Reconciles the dependants a column type change puts back with what the rest
 * of the migration does to them.
 *
 * A column type change drops the views, policies and triggers reading the
 * column and recreates them as the target has them. On its own that is right;
 * next to the other diffs it is not always:
 *
 *   - A view the source no longer has is dropped earlier in the UP
 *     (DiffSorter runs DropView first). Recreating it afterwards brought it
 *     back, and the next diff offered to drop it again.
 *   - A view or policy the source *changed* is recreated later from the new
 *     definition. Recreating the old one in between is at best wasted, and at
 *     worst fails: `user_id = auth.uid()::text` is not valid once `user_id` is
 *     a uuid, which is the very change being made.
 *
 * So once every diff is known, each column change is told which dependants
 * to leave out of its UP and which to recreate from the source definition.
 * The DOWN is the mirror image: it recreates every dependant as the target
 * has it, after reverting the type, so the dependants' own DOWNs — which
 * DiffSorter would run first, against the new type — are left out.
 */
final class ColumnDependantPlan {

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs): void {
        $skip    = [];
        $replace = [];

        foreach ($diffs as $diff) {
            if ($diff instanceof DropView || $diff instanceof DropMatView) {
                $skip['public.' . $diff->name] = true;
            } elseif ($diff instanceof DropPolicy || $diff instanceof DropTrigger) {
                $skip['public.' . $diff->table . '.' . $diff->name] = true;
            } elseif ($diff instanceof AlterView || $diff instanceof AlterMatView) {
                $replace['public.' . $diff->name] = $diff->sourceDefinition;
            } elseif ($diff instanceof AlterPolicy || $diff instanceof AlterTrigger) {
                $replace['public.' . $diff->table . '.' . $diff->name] = $diff->sourceDefinition;
            }
        }

        $handled = [];
        foreach ($diffs as $diff) {
            if ($diff instanceof AlterTableChangeColumn && $diff->dependants !== null) {
                $diff->upPlan = ['skip' => $skip, 'replace' => $replace];
                $handled += self::keysOf($diff->dependants);
            }
        }

        // The DOWN side. DiffSorter reverts view, policy and trigger changes
        // before it reverts column changes, so a dependant's own DOWN would
        // recreate the target's definition while the column still has the
        // source's type — `user_id = auth.uid()::text` against a uuid column,
        // which fails. The column change's DOWN already drops every dependant
        // and recreates the target's version after reverting the type, so
        // theirs is left out.
        foreach ($diffs as $diff) {
            $key = self::keyOf($diff);
            if ($key !== null && isset($handled[$key])) {
                $diff->downHandledElsewhere = true;
            }
        }
    }

    /**
     * The keys of every dependant, matching keyOf().
     *
     * @return array<string, true>
     */
    private static function keysOf(array $dependants): array {
        $keys = [];
        foreach ($dependants['views'] ?? [] as $view) {
            $keys[$view['schema'] . '.' . $view['name']] = true;
            foreach ($view['triggers'] ?? [] as $trigger) {
                $keys[$view['schema'] . '.' . $view['name'] . '.' . $trigger['name']] = true;
            }
        }
        foreach (['policies', 'triggers'] as $kind) {
            foreach ($dependants[$kind] ?? [] as $object) {
                $keys[$object['schema'] . '.' . $object['table'] . '.' . $object['name']] = true;
            }
        }
        return $keys;
    }

    /** The dependant key a view, policy or trigger diff would have, or null. */
    private static function keyOf(object $diff): ?string {
        if ($diff instanceof DropView || $diff instanceof DropMatView
            || $diff instanceof AlterView || $diff instanceof AlterMatView) {
            return 'public.' . $diff->name;
        }
        if ($diff instanceof DropPolicy || $diff instanceof AlterPolicy
            || $diff instanceof DropTrigger || $diff instanceof AlterTrigger) {
            return 'public.' . $diff->table . '.' . $diff->name;
        }
        return null;
    }
}
