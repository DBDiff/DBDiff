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
 *   - A view, policy or trigger the source *changed* is recreated later in the
 *     UP from the new definition, by its own diff. Recreating the old one in
 *     between can fail — `user_id = auth.uid()::text` is not valid once
 *     `user_id` is a uuid, the very change being made — and recreating the
 *     new one there instead can fail too, because routines and new views are
 *     created after column changes.
 *
 * So in the UP, a column change leaves out every dependant another diff drops
 * or changes; that diff's own statement, which drops IF EXISTS, takes care of
 * it at the point where everything it needs exists.
 *
 * The DOWN is the mirror image: it recreates every dependant as the target
 * has it, after reverting the type, so the dependants' own DOWNs — which
 * DiffSorter would run first, against the new type — are left out.
 */
final class ColumnDependantPlan {

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs): void {
        $handledByOwnDiff = [];
        foreach ($diffs as $diff) {
            $key = self::keyOf($diff);
            if ($key !== null) {
                $handledByOwnDiff[$key] = true;
            }
        }

        $dependantKeys = [];
        foreach ($diffs as $diff) {
            if ($diff instanceof AlterTableChangeColumn && $diff->dependants !== null) {
                $diff->upSkip = $handledByOwnDiff;
                $dependantKeys += self::keysOf($diff->dependants);
            }
        }

        foreach ($diffs as $diff) {
            $key = self::keyOf($diff);
            if ($key === null || !isset($dependantKeys[$key])) {
                continue;
            }
            // A changed policy or trigger still comes off at its own point in
            // the DOWN: nothing depends on one, and leaving it in place until
            // the column change reverts would keep a routine it calls alive
            // past the point the DOWN drops that routine. The column change
            // then recreates the target's version. A view cannot do the same
            // — other objects may still depend on it there — so it is left
            // entirely to the column change.
            if ($diff instanceof AlterPolicy || $diff instanceof AlterTrigger) {
                $diff->downDropOnly = true;
            } else {
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

    /**
     * The dependant key a view, policy or trigger diff would have, or null.
     *
     * Only drops and changes: a Create diff is for an object the target does
     * not have, so it is never a dependant.
     */
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
