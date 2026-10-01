<?php namespace DBDiff\DB\Schema;

use DBDiff\DB\Support\EnumLabels;
use DBDiff\DB\Support\PostgresColumnDependants;
use DBDiff\DB\Support\PostgresEnumUsage;
use DBDiff\Diff\AlterEnum;
use DBDiff\Diff\AlterTableAddConstraint;
use DBDiff\Diff\AlterTableAddKey;
use DBDiff\Diff\AlterTableChangeConstraint;
use DBDiff\Diff\AlterTableChangeKey;
use DBDiff\Diff\AlterTableDropConstraint;
use DBDiff\Diff\AlterTableDropKey;
use DBDiff\SQLGen\DiffToSQL\AlterEnumSQL;
use Illuminate\Database\Connection;

/**
 * What an enum label removal or reorder has to carry, in each direction that
 * needs one (issue #237). See PostgresEnumUsage for what the swap moves, and
 * EnumSwapSQL for how.
 *
 * Each direction reads the database it runs against — the target for the UP,
 * the source for the DOWN — and runs early, before views, policies, triggers
 * and routines are touched (DiffSorter). The views, policies and triggers
 * another diff of that direction drops or changes are left to that diff, as
 * for a column type change (ColumnDependantPlan): it runs later, once the
 * labels it may name exist. The constraints the UP has already dropped by
 * then are left out.
 *
 * A constraint or index whose definition names a removed label cannot come
 * back. The destination cannot have it either, so another diff drops or
 * changes it later in the same direction; that diff's DROP is told it may find
 * the object already gone.
 */
final class EnumSwapPlan {

    private const NONE = ['views' => [], 'policies' => [], 'triggers' => [], 'generated' => [], 'defaultGrantees' => []];

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs, Connection $source, Connection $target): void {
        foreach ($diffs as $diff) {
            if (!$diff instanceof AlterEnum) {
                continue;
            }
            if (self::needsSwap($diff->targetDefinition, $diff->sourceDefinition)) {
                $diff->swaps['up'] = self::direction(
                    $diff->name, $diff->targetDefinition, $diff->sourceDefinition, $diffs,
                    $target, ColumnDependantPlan::handledByOwnDiff($diffs, 'up'), self::droppedBefore($diffs)
                );
            }
            if (self::needsSwap($diff->sourceDefinition, $diff->targetDefinition)) {
                $diff->swaps['down'] = self::direction(
                    $diff->name, $diff->sourceDefinition, $diff->targetDefinition, $diffs,
                    $source, ColumnDependantPlan::handledByOwnDiff($diffs, 'down'), []
                );
            }
        }
    }

    /** A change ADD VALUE cannot make, between two definitions that parse. */
    private static function needsSwap(string $from, string $to): bool {
        return EnumLabels::of($from) !== null
            && EnumLabels::of($to) !== null
            && !AlterEnumSQL::isAddition($from, $to);
    }

    /**
     * @param Connection          $here  the database this direction runs against
     * @param array<string, true> $skip  readers another diff of this direction recreates
     * @param array<string, true> $gone  constraints already dropped when the swap runs
     */
    private static function direction(
        string $type, string $from, string $to, array $diffs,
        Connection $here, array $skip, array $gone
    ): array {
        $usage   = PostgresEnumUsage::find($here, $type);
        $removed = array_values(array_diff(EnumLabels::of($from), EnumLabels::of($to)));

        $dependants = self::NONE;
        foreach (array_filter($usage['columns'], fn($c) => !$c['inherited']) as $column) {
            $dependants = ColumnDependantPlan::mergeDependants(
                $dependants,
                PostgresColumnDependants::find($here, $column['table'], $column['column'])
            );
        }

        foreach ($usage['columns'] as &$column) {
            $column['restoreDefault'] = $column['default'] !== null
                && !EnumLabels::mentioned($column['default'], $type, $removed);
        }
        unset($column);

        $usage['constraints'] = array_values(array_filter(
            $usage['constraints'],
            fn($c) => !isset($gone[$c['table'] . '.' . $c['name']])
        ));
        foreach (['constraints', 'indexes'] as $kind) {
            foreach ($usage[$kind] as &$object) {
                $object['recreate'] = !EnumLabels::mentioned($object['definition'], $type, $removed);
                if (!$object['recreate']) {
                    self::markDropIfExists($diffs, $object['table'], $object['name']);
                }
            }
            unset($object);
        }

        return [
            'usage'      => $usage,
            'dependants' => $dependants,
            'skip'       => $skip,
            'blockers'   => array_merge($usage['others'], PostgresEnumUsage::uncarried($usage, $dependants)),
        ];
    }

    /** `table.name` of each constraint the UP drops before it swaps. */
    private static function droppedBefore(array $diffs): array {
        $keys = [];
        foreach ($diffs as $diff) {
            if ($diff instanceof AlterTableDropConstraint) {
                $keys[$diff->table . '.' . $diff->name] = true;
            }
        }
        return $keys;
    }

    private static function markDropIfExists(array $diffs, string $table, string $name): void {
        foreach ($diffs as $diff) {
            $own = match (true) {
                $diff instanceof AlterTableAddConstraint,
                $diff instanceof AlterTableChangeConstraint,
                $diff instanceof AlterTableDropConstraint => $diff->name,
                $diff instanceof AlterTableAddKey,
                $diff instanceof AlterTableChangeKey,
                $diff instanceof AlterTableDropKey => $diff->key,
                default => null,
            };
            if ($own === $name && $diff->table === $table) {
                $diff->dropIfExists = true;
            }
        }
    }
}
