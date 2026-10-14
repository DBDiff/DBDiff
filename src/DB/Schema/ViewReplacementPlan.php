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
    /** What (re)creates a view, a policy or a trigger in each direction. */
    private const CREATES = [
        'up' => [
            'views'    => [CreateView::class, AlterView::class, CreateMatView::class, AlterMatView::class],
            'onTables' => [CreatePolicy::class, AlterPolicy::class, CreateTrigger::class, AlterTrigger::class],
        ],
        'down' => [
            'views'    => [DropView::class, AlterView::class, DropMatView::class, AlterMatView::class],
            'onTables' => [DropPolicy::class, AlterPolicy::class, DropTrigger::class, AlterTrigger::class],
        ],
    ];

    private string $scope;

    /** @var array<string, array{source: ?array, target: ?array}> each changed view, as each side has it */
    private array $read = [];

    /** @var array{up: array<string, true>, down: array<string, true>} views that must not be replaced in place */
    private array $forced = ['up' => [], 'down' => []];

    /** @param array<int, object> $diffs */
    private function __construct(private array $diffs, private Connection $source, private Connection $target) {
        $this->scope = SchemaScope::of($source);
    }

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs, Connection $source, Connection $target): void {
        $views = array_values(array_filter($diffs, [self::class, 'isView']));
        if ($views !== []) {
            (new self($diffs, $source, $target))->run($views);
        }
    }

    /** @param array<int, AlterView|AlterMatView> $views */
    private function run(array $views): void {
        foreach ($views as $diff) {
            // Planned again over every schema once each has been on its own —
            // see MultiSchemaDiff.
            $diff->replacement = [];
            if ($diff instanceof AlterView) {
                $diff->inPlace = [];
            }
            $this->read[$this->key($diff)] = [
                'source' => self::read($this->source, $diff),
                'target' => self::read($this->target, $diff),
            ];
        }
        $late = $this->lateCreates();
        $this->order(array_merge($views, array_column($late, 'diff')), $late);

        $this->forced['up'] = self::droppedByColumnChange($this->diffs);
        // The UP runs against the target as it is and leads to the source;
        // the DOWN the other way round.
        $this->planDirection('up', 'target', 'source');
        $this->planDirection('down', 'source', 'target');

        $this->leaveToViews($views);
    }

    private function planDirection(string $direction, string $from, string $to): void {
        $sorted = (new DiffSorter())->sort($this->diffs, $direction);
        foreach ($sorted as $position => $diff) {
            if (!self::isView($diff) || ($direction === 'down' && $diff->downHandledElsewhere)) {
                continue;
            }
            $sides = $this->read[$this->key($diff)];
            if ($sides[$from] !== null && $sides[$to] !== null) {
                $this->plan($diff, $direction, $sides[$from], $sides[$to],
                    array_slice($sorted, 0, $position), array_slice($sorted, $position + 1));
            }
        }
    }

    /**
     * Put one changed view in place in one direction: in place when it can,
     * otherwise with what reads it dropped around it.
     *
     * @param array $have    the view as the migration finds it
     * @param array $want    the view as the migration leaves it
     * @param array $before  the diffs this direction runs before it
     * @param array $after   and after it
     */
    private function plan(object $diff, string $direction, array $have, array $want, array $before, array $after): void {
        $key = $this->key($diff);
        $earlier = $direction === 'down' ? $this->revertedBefore($before, $key, $want) : [];
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
                && !isset($this->forced[$direction][$key])
                && $earlier === [];
            if ($diff->inPlace[$direction]) {
                return;
            }
        }

        $later = $this->createdAfter($after, $direction);
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
            $this->forced[$direction][$view['schema'] . '.' . $view['name']] = true;
        }
    }

    /**
     * The changed policies, triggers and materialised views reading `$key`
     * that the DOWN reverts before it — DiffSorter puts those before views.
     *
     * @return array<int, AlterPolicy|AlterTrigger|AlterMatView>
     */
    private function revertedBefore(array $before, string $key, array $want): array {
        $reads = ColumnDependantPlan::keysOf($want['dependants']);
        foreach ($want['triggers'] as $trigger) {
            $reads[$key . '.' . $trigger['name']] = true;
        }
        return array_values(array_filter($before, fn($diff) => match (true) {
            $diff instanceof AlterMatView => !$diff->downHandledElsewhere && isset($reads[$this->key($diff)]),
            $diff instanceof AlterPolicy, $diff instanceof AlterTrigger
                => empty($diff->downDropOnly) && isset($reads[$this->tableKey($diff)]),
            default => false,
        }));
    }

    /**
     * New views reading a changed one, directly or through each other: they
     * are created among the changed views, after what they read — see
     * DiffSorter.
     *
     * @return array<string, array{diff: CreateView, dependants: array}>
     */
    private function lateCreates(): array {
        $reading = [];
        foreach ($this->read as $sides) {
            foreach ($sides['source']['dependants']['views'] ?? [] as $view) {
                $reading[$view['schema'] . '.' . $view['name']] = true;
            }
        }
        $late = [];
        foreach ($this->diffs as $diff) {
            if ($diff instanceof CreateView && isset($reading[$this->key($diff)])) {
                $diff->late = true;
                $late[$this->key($diff)] = [
                    'diff'       => $diff,
                    'dependants' => PostgresColumnDependants::ofRelation($this->source, $diff->schema ?? $this->scope, $diff->name),
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
    private function order(array $nodes, array $late): void {
        $readers = [];
        $sets = array_merge(
            array_map(fn($sides) => [$sides['source']['dependants'] ?? [], $sides['target']['dependants'] ?? []], $this->read),
            array_map(fn($create) => [$create['dependants']], $late)
        );
        foreach ($sets as $key => $dependantSets) {
            foreach ($dependantSets as $dependants) {
                foreach ($dependants['views'] ?? [] as $view) {
                    $readers[$key][] = $view['schema'] . '.' . $view['name'];
                }
            }
        }

        $rank = array_fill_keys(array_map(fn($diff) => $this->key($diff), $nodes), 0);
        // One pass per level at most; the bound keeps a cycle across the two
        // sides from looping.
        $passes = 0;
        while ($passes++ <= count($nodes) && self::raise($rank, $readers)) {
            // Each pass settles one more level.
        }
        foreach ($nodes as $diff) {
            $diff->sortOrder = -$rank[$this->key($diff)];
        }
    }

    /**
     * Rank every view above what it reads, once over; whether anything moved.
     *
     * @param array<string, int> $rank
     * @param array<string, string[]> $readers
     */
    private static function raise(array &$rank, array $readers): bool {
        $moved = false;
        foreach ($readers as $key => $dependants) {
            foreach ($dependants as $dependant) {
                if (isset($rank[$dependant]) && $rank[$dependant] <= $rank[$key]) {
                    $rank[$dependant] = $rank[$key] + 1;
                    $moved = true;
                }
            }
        }
        return $moved;
    }

    /**
     * The views, policies and triggers the given diffs (re)create in this
     * direction, keyed as a dependant set keys them.
     *
     * @return array<string, true>
     */
    private function createdAfter(array $diffs, string $direction): array {
        $keys = [];
        foreach ($diffs as $diff) {
            $keys += $this->createdBy($diff, $direction);
        }
        return $keys;
    }

    /** @return array<string, true> */
    private function createdBy(object $diff, string $direction): array {
        $class = get_class($diff);
        return match (true) {
            $direction === 'down' && !empty($diff->downHandledElsewhere) => [],
            in_array($class, self::CREATES[$direction]['views'], true) => [$this->key($diff) => true],
            in_array($class, self::CREATES[$direction]['onTables'], true)
                => $direction === 'down' && !empty($diff->downDropOnly) ? [] : [$this->tableKey($diff) => true],
            // A column change puts back what reads the column, as the side it
            // leads to has it.
            $diff instanceof AlterTableChangeColumn && $diff->dependants !== null => array_diff_key(
                ColumnDependantPlan::keysOf($diff->dependants),
                $direction === 'up' ? $diff->upSkip : []
            ),
            default => [],
        };
    }

    /**
     * A column type change takes the views reading the column out of its
     * way; one of them that is changed is left to its own diff. That diff then
     * puts back what reads it, so the column change must not — the view would
     * not exist yet.
     *
     * @param array<int, AlterView|AlterMatView> $views
     */
    private function leaveToViews(array $views): void {
        $byKey = [];
        foreach ($views as $diff) {
            $byKey[$this->key($diff)] = $diff;
        }
        foreach ($this->diffs as $diff) {
            if (!($diff instanceof AlterTableChangeColumn) || $diff->dependants === null) {
                continue;
            }
            foreach (array_keys(ColumnDependantPlan::keysOf($diff->dependants)) as $key) {
                $view = $byKey[$key] ?? null;
                if (isset($diff->upSkip[$key], $view->replacement['up'])) {
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

    private function key(object $diff): string {
        return ($diff->schema ?? $this->scope) . '.' . $diff->name;
    }

    /** A policy's or trigger's key: `schema.table.name`. */
    private function tableKey(object $diff): string {
        return ($diff->schema ?? $this->scope) . '.' . $diff->table . '.' . $diff->name;
    }

    private static function isView(object $diff): bool {
        return $diff instanceof AlterView || $diff instanceof AlterMatView;
    }
}
