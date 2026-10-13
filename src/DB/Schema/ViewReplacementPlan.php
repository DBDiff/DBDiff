<?php namespace DBDiff\DB\Schema;

use DBDiff\DB\Support\PostgresColumnDependants;
use DBDiff\DB\Support\PostgresSchemaHelper;
use DBDiff\DB\Support\SchemaScope;
use DBDiff\Diff\AlterMatView;
use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterTableChangeColumn;
use DBDiff\Diff\AlterTrigger;
use DBDiff\Diff\AlterView;
use DBDiff\Diff\CreateMatView;
use DBDiff\Diff\CreatePolicy;
use DBDiff\Diff\CreateTrigger;
use DBDiff\Diff\CreateView;
use DBDiff\Diff\DropMatView;
use DBDiff\Diff\DropPolicy;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\DropView;
use DBDiff\SQLGen\DiffSorter;
use Illuminate\Database\Connection;

/**
 * How a changed view is put in place when other objects depend on it.
 *
 * A changed view was dropped and created again. PostgreSQL refuses to drop a
 * view other views, or policies, read — "cannot drop view v because other
 * objects depend on it" — so the whole migration failed, unrelated changes
 * included.
 *
 * A view whose new columns begin with the old ones, same names, types and
 * collations, is replaced in place with CREATE OR REPLACE VIEW: what reads it
 * keeps working, and its grants, comments and triggers stay. Otherwise —
 * a column renamed, retyped or removed, or a materialised view, which cannot
 * be replaced — what depends on it is dropped first, deepest first, and put
 * back afterwards, complete, as the side being migrated to has it. What
 * another diff creates or replaces later in the migration is left to that
 * diff, which has the definition and everything around it ready.
 *
 * Changed views run in dependency order, and a new view reading a changed
 * one runs among them, after it: created before, it read the view's old
 * columns.
 *
 * Each direction is decided on its own: a view that gains a column is
 * replaced in place on the way up and needs its dependants dropped on the way
 * down.
 */
final class ViewReplacementPlan {

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs, Connection $source, Connection $target): void {
        $views = array_values(array_filter($diffs, fn($d) => $d instanceof AlterView || $d instanceof AlterMatView));
        if ($views === []) {
            return;
        }
        $scope = SchemaScope::of($source);
        // Planned again over every schema once each has been on its own —
        // see MultiSchemaDiff.
        foreach ($views as $diff) {
            $diff->replacement = [];
            if ($diff instanceof AlterView) {
                $diff->inPlace = [];
            }
        }

        $read = [];
        foreach ($views as $diff) {
            $read[self::key($diff, $scope)] = ['source' => self::read($source, $diff), 'target' => self::read($target, $diff)];
        }
        $late = self::lateCreates($diffs, $read, $source, $scope);
        self::order(array_merge($views, array_column($late, 'diff')), $read, $late, $scope);

        $forced = ['up' => self::droppedByColumnChange($diffs), 'down' => []];
        $sorter = new DiffSorter();
        // The UP runs against the target as it is and leads to the source;
        // the DOWN the other way round.
        foreach (['up' => ['target', 'source'], 'down' => ['source', 'target']] as $direction => [$from, $to]) {
            $sorted = $sorter->sort($diffs, $direction);
            foreach ($sorted as $position => $diff) {
                $isView = $diff instanceof AlterView || $diff instanceof AlterMatView;
                if (!$isView || ($direction === 'down' && $diff->downHandledElsewhere)) {
                    continue;
                }
                $sides = $read[self::key($diff, $scope)];
                if ($sides[$from] !== null && $sides[$to] !== null) {
                    self::plan($diff, $direction, $sides[$from], $sides[$to], $sorted, $position, $forced, $scope);
                }
            }
        }

        self::leaveToViews($diffs, $views, $scope);
    }

    /**
     * Put one changed view in place in one direction: in place when it can,
     * otherwise with what reads it dropped around it.
     *
     * @param array $have     the view as the migration finds it
     * @param array $want     the view as the migration leaves it
     * @param array $sorted   every diff, in the order this direction runs them
     * @param array $forced   views that must not be replaced in place, by direction
     */
    private static function plan(object $diff, string $direction, array $have, array $want, array $sorted, int $position, array &$forced, string $scope): void {
        $key = self::key($diff, $scope);
        $earlier = $direction === 'down' ? self::revertedBefore(array_slice($sorted, 0, $position), $key, $want, $scope) : [];
        // What reads the view and is reverted before it would read its old
        // columns too soon: it only comes off there, and the bracket below
        // puts it back.
        foreach ($earlier as $reader) {
            if ($reader instanceof AlterMatView) {
                $reader->downHandledElsewhere = true;
            } else {
                $reader->downDropOnly = true;
            }
        }
        if ($diff instanceof AlterView) {
            $diff->inPlace[$direction] = self::extendsColumns($want['columns'], $have['columns'])
                && !isset($forced[$direction][$key])
                && $earlier === [];
            if ($diff->inPlace[$direction]) {
                return;
            }
        }

        $later = self::createdAfter(array_slice($sorted, $position + 1), $direction, $scope);
        // Whatever reads the view on either side: one the change stops
        // reading still does until its own diff runs.
        $drop = ColumnDependantPlan::mergeDependants($have['dependants'], $want['dependants']);
        $diff->replacement[$direction] = [
            'drop'     => $drop,
            'recreate' => $want['dependants'],
            'skip'     => $later,
            'triggers' => array_values(array_filter(
                $want['triggers'],
                fn($t) => !isset($later[$key . '.' . $t['name']])
            )),
        ];
        // A changed view this drops is gone by the time its own diff runs,
        // so that diff has to create it whole.
        foreach ($drop['views'] as $view) {
            $forced[$direction][$view['schema'] . '.' . $view['name']] = true;
        }
    }

    /**
     * The changed policies, triggers and materialised views reading `$key`
     * that the DOWN reverts before it — DiffSorter puts those before views.
     *
     * @return array<int, AlterPolicy|AlterTrigger|AlterMatView>
     */
    private static function revertedBefore(array $before, string $key, array $want, string $scope): array {
        $reads = ColumnDependantPlan::keysOf($want['dependants']);
        foreach ($want['triggers'] as $trigger) {
            $reads[$key . '.' . $trigger['name']] = true;
        }
        return array_values(array_filter($before, function ($diff) use ($reads, $scope) {
            if ($diff instanceof AlterMatView) {
                return !$diff->downHandledElsewhere && isset($reads[self::key($diff, $scope)]);
            }
            return ($diff instanceof AlterPolicy || $diff instanceof AlterTrigger)
                && empty($diff->downDropOnly)
                && isset($reads[($diff->schema ?? $scope) . '.' . $diff->table . '.' . $diff->name]);
        }));
    }

    /**
     * New views reading a changed one, directly or through each other: they
     * are created among the changed views, after what they read — see
     * DiffSorter.
     *
     * @return array<string, array{diff: CreateView, dependants: array}>
     */
    private static function lateCreates(array $diffs, array $read, Connection $source, string $scope): array {
        $reading = [];
        foreach ($read as $sides) {
            foreach ($sides['source']['dependants']['views'] ?? [] as $view) {
                $reading[$view['schema'] . '.' . $view['name']] = true;
            }
        }
        $late = [];
        foreach ($diffs as $diff) {
            $key = $diff instanceof CreateView ? self::key($diff, $scope) : null;
            if ($key !== null && isset($reading[$key])) {
                $diff->late = true;
                $late[$key] = [
                    'diff'       => $diff,
                    'dependants' => PostgresColumnDependants::ofRelation($source, $diff->schema ?? $scope, $diff->name),
                ];
            }
        }
        return $late;
    }

    /**
     * Changed (and late new) views in dependency order — one built on another
     * after it, in both directions. Each sorts by -rank: DiffSorter runs
     * these descending.
     *
     * @param array<int, object> $nodes
     */
    private static function order(array $nodes, array $read, array $late, string $scope): void {
        $readers = [];
        foreach ($read as $key => $sides) {
            foreach ($sides as $side) {
                foreach ($side['dependants']['views'] ?? [] as $view) {
                    $readers[$key][] = $view['schema'] . '.' . $view['name'];
                }
            }
        }
        foreach ($late as $key => $create) {
            foreach ($create['dependants']['views'] as $view) {
                $readers[$key][] = $view['schema'] . '.' . $view['name'];
            }
        }

        $rank = [];
        foreach ($nodes as $diff) {
            $rank[self::key($diff, $scope)] = 0;
        }
        for ($pass = 0, $changed = true; $changed && $pass <= count($nodes); $pass++) {
            $changed = false;
            foreach ($readers as $key => $dependants) {
                foreach ($dependants as $dependant) {
                    if (isset($rank[$dependant]) && $rank[$dependant] <= $rank[$key]) {
                        $rank[$dependant] = $rank[$key] + 1;
                        $changed = true;
                    }
                }
            }
        }
        foreach ($nodes as $diff) {
            $diff->sortOrder = -$rank[self::key($diff, $scope)];
        }
    }

    /**
     * The views, policies and triggers the given diffs (re)create in this
     * direction, keyed as a dependant set keys them.
     *
     * @return array<string, true>
     */
    private static function createdAfter(array $diffs, string $direction, string $scope): array {
        $views = $direction === 'up'
            ? [CreateView::class, AlterView::class, CreateMatView::class, AlterMatView::class]
            : [DropView::class, AlterView::class, DropMatView::class, AlterMatView::class];
        $onTables = $direction === 'up'
            ? [CreatePolicy::class, AlterPolicy::class, CreateTrigger::class, AlterTrigger::class]
            : [DropPolicy::class, AlterPolicy::class, DropTrigger::class, AlterTrigger::class];
        $keys = [];
        foreach ($diffs as $diff) {
            if ($direction === 'down' && !empty($diff->downHandledElsewhere)) {
                continue;
            }
            if (in_array(get_class($diff), $views, true)) {
                $keys[self::key($diff, $scope)] = true;
            } elseif (in_array(get_class($diff), $onTables, true)
                && !($direction === 'down' && !empty($diff->downDropOnly))) {
                $keys[($diff->schema ?? $scope) . '.' . $diff->table . '.' . $diff->name] = true;
            } elseif ($diff instanceof AlterTableChangeColumn && $diff->dependants !== null) {
                // A column change puts back what reads the column, as the
                // side it leads to has it.
                $keys += array_diff_key(
                    ColumnDependantPlan::keysOf($diff->dependants),
                    $direction === 'up' ? $diff->upSkip : []
                );
            }
        }
        return $keys;
    }

    /**
     * A column type change takes the views reading the column out of its
     * way; one of them that is changed is left to its own diff. That diff then
     * puts back what reads it, so the column change must not — the view would
     * not exist yet.
     *
     * @param array<int, AlterView|AlterMatView> $views
     */
    private static function leaveToViews(array $diffs, array $views, string $scope): void {
        $byKey = [];
        foreach ($views as $diff) {
            $byKey[self::key($diff, $scope)] = $diff;
        }
        foreach ($diffs as $diff) {
            if (!($diff instanceof AlterTableChangeColumn) || $diff->dependants === null) {
                continue;
            }
            foreach (array_keys(ColumnDependantPlan::keysOf($diff->dependants)) as $key) {
                $view = $byKey[$key] ?? null;
                if ($view !== null && isset($diff->upSkip[$key]) && isset($view->replacement['up'])) {
                    $diff->upSkip += ColumnDependantPlan::keysOf($view->replacement['up']['drop']);
                }
            }
        }
    }

    /**
     * The views the UP's column type changes drop.
     *
     * @return array<string, true>
     */
    private static function droppedByColumnChange(array $diffs): array {
        $keys = [];
        foreach ($diffs as $diff) {
            if ($diff instanceof AlterTableChangeColumn && $diff->dependants !== null) {
                $keys += ColumnDependantPlan::keysOf($diff->dependants);
            }
        }
        return $keys;
    }

    /** @return array{columns: array, dependants: array, triggers: array}|null */
    private static function read(Connection $connection, object $diff): ?array {
        $schema = $diff->schema ?? SchemaScope::of($connection);
        $name   = PostgresSchemaHelper::qualifiedName($schema, $diff->name);
        $columns = $connection->select(
            "SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, co.collname AS collation
               FROM pg_attribute a
               LEFT JOIN pg_collation co ON co.oid = a.attcollation
              WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped
              ORDER BY a.attnum",
            [$name]
        );
        if ($columns === []) {
            return null;
        }
        $triggers = $connection->select(
            "SELECT t.tgname AS name, pg_get_triggerdef(t.oid) AS definition
               FROM pg_trigger t
              WHERE t.tgrelid = to_regclass(?) AND NOT t.tgisinternal
              ORDER BY t.tgname",
            [$name]
        );
        return [
            'columns'    => array_map(fn($c) => [$c['name'], $c['type'], $c['collation']], $columns),
            'dependants' => PostgresColumnDependants::ofRelation($connection, $schema, $diff->name),
            'triggers'   => array_map(fn($t) => ['name' => $t['name'], 'definition' => $t['definition']], $triggers),
        ];
    }

    /**
     * Whether `$new` keeps every column of `$old`, in order, and only adds
     * columns after them — what CREATE OR REPLACE VIEW accepts.
     */
    private static function extendsColumns(array $new, array $old): bool {
        return count($new) >= count($old) && array_slice($new, 0, count($old)) === $old;
    }

    private static function key(object $diff, string $scope): string {
        return ($diff->schema ?? $scope) . '.' . $diff->name;
    }
}
