<?php namespace DBDiff\DB\Schema;

use Diff\Differ\ListDiffer;
use Diff\DiffOp\DiffOpRemove;
use Diff\DiffOp\DiffOpAdd;
use DBDiff\DB\Support\PostgresTableParts;
use DBDiff\Diff\AlterTableDropConstraint;
use DBDiff\Diff\AlterTableAddConstraint;

use DBDiff\Logger;
use DBDiff\Params\ParamsFactory;
use DBDiff\Params\TableFilter;
use DBDiff\Diff\SetDBCollation;
use DBDiff\Diff\SetDBCharset;
use DBDiff\Diff\DropTable;
use DBDiff\Diff\AddTable;
use DBDiff\Diff\AlterTable;
use DBDiff\Diff\CreateView;
use DBDiff\Diff\DropView;
use DBDiff\Diff\AlterTablePersistence;
use DBDiff\DB\Support\PostgresExpressionEquivalence;
use DBDiff\Diff\AlterView;
use DBDiff\Diff\CreateTrigger;
use DBDiff\Diff\DropTrigger;
use DBDiff\Diff\AlterTrigger;
use DBDiff\Diff\CreateRoutine;
use DBDiff\Diff\DropRoutine;
use DBDiff\Diff\AlterRoutine;
use DBDiff\Diff\CreateEnum;
use DBDiff\Diff\DropEnum;
use DBDiff\Diff\AlterEnum;
use DBDiff\Diff\CreateSequence;
use DBDiff\Diff\DropSequence;
use DBDiff\Diff\AlterSequence;
use DBDiff\Diff\CreateCompositeType;
use DBDiff\Diff\DropCompositeType;
use DBDiff\Diff\AlterCompositeType;
use DBDiff\Diff\CreateDomain;
use DBDiff\Diff\DropDomain;
use DBDiff\Diff\AlterDomain;
use DBDiff\Diff\CreateMatView;
use DBDiff\Diff\DropMatView;
use DBDiff\Diff\AlterMatView;
use DBDiff\Diff\CreatePolicy;
use DBDiff\Diff\DropPolicy;
use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterRowSecurity;
use DBDiff\Diff\CreateExtension;
use DBDiff\Diff\DropExtension;
use DBDiff\DB\Adapters\BulkSchemaAdapterInterface;
use DBDiff\DB\Support\PostgresObjectKinds;
use DBDiff\DB\Support\PostgresComments;



class DBSchema {

    protected $manager;

    function __construct($manager) {
        $this->manager = $manager;
    }
    
    function getDiff() {
        $params = ParamsFactory::get();
        $driver = $this->manager->getDriver();

        $diffs = [];

        // Collation & Charset — MySQL only
        if ($driver === 'mysql') {
            $dbName = $this->manager->getDB('target')->getDatabaseName();

            $sourceCollation = $this->manager->getDBVariable('source', 'collation_database');
            $targetCollation = $this->manager->getDBVariable('target', 'collation_database');
            if ($sourceCollation !== $targetCollation) {
                $diffs[] = new SetDBCollation($dbName, $sourceCollation, $targetCollation);
            }

            $sourceCharset = $this->manager->getDBVariable('source', 'character_set_database');
            $targetCharset = $this->manager->getDBVariable('target', 'character_set_database');
            if ($sourceCharset !== $targetCharset) {
                $diffs[] = new SetDBCharset($dbName, $sourceCharset, $targetCharset);
            }
        }
        
        // Tables
        $tableSchema = new TableSchema($this->manager);

        $sourceTables = $this->manager->getTables('source');
        $targetTables = $this->manager->getTables('target');

        $allTables    = array_merge($sourceTables, $targetTables);
        $sourceTables = TableFilter::filterTables($sourceTables, $params, 'schema');
        $targetTables = TableFilter::filterTables($targetTables, $params, 'schema');

        $addedTables = array_values(array_diff($sourceTables, $targetTables));
        $deletedTables = array_values(array_diff($targetTables, $sourceTables));

        // Topological sort so parents are created before children
        if (!empty($addedTables)) {
            $sourceFkMap  = $this->manager->getForeignKeyMap('source');
            $addedTables  = TableOrder::sort($addedTables, $sourceFkMap);
        }
        if (!empty($deletedTables)) {
            $targetFkMap    = $this->manager->getForeignKeyMap('target');
            $deletedTables  = TableOrder::sort($deletedTables, $targetFkMap);
        }

        $added = [];
        foreach ($addedTables as $i => $table) {
            $diff = new AddTable($table, $this->manager, 'source');
            $diff->sortOrder = $i;
            $diffs[] = $diff;
            $added[] = $diff;
        }

        $commonTables = array_values(array_intersect($sourceTables, $targetTables));

        $tablesNeedingDiff = $this->selectTablesNeedingDiff($commonTables);
        [$sourceBulk, $targetBulk] = $this->bulkFetchSchemas($tablesNeedingDiff);

        foreach ($tablesNeedingDiff as $table) {
            $tableDiff = $tableSchema->getDiff(
                $table,
                $sourceBulk[$table] ?? null,
                $targetBulk[$table] ?? null
            );
            $diffs = array_merge($diffs, $tableDiff);
        }

        $this->orderPersistenceChanges($diffs);

        $dropped = [];
        foreach ($deletedTables as $i => $table) {
            $diff = new DropTable($table, $this->manager, 'target');
            $diff->sortOrder = $i;
            $diffs[] = $diff;
            $dropped[] = $diff;
        }

        if ($driver === 'pgsql') {
            $diffs = array_merge($diffs, $this->keysAcrossACycle($added, 'source'), $this->keysAcrossACycle($dropped, 'target'));
        }

        // Enums / custom types (must be created before tables that reference them)
        $diffs = array_merge($diffs, $this->diffEnums());

        // Views
        $diffs = array_merge($diffs, $this->diffViews());

        // Triggers
        $diffs = array_merge($diffs, $this->diffTriggers());

        // Routines (stored procedures and functions)
        $diffs = array_merge($diffs, $this->diffRoutines());

        // The kinds only PostgreSQL has, asked for only of PostgreSQL — the same
        // way collation and charset above are asked for only of MySQL.
        if ($driver === 'pgsql') {
            $diffs = array_merge($diffs, $this->diffPostgresObjectKinds($sourceTables, $targetTables));
            // Before anything plans around them: a "change" that is the same
            // definition rendered twice is not one (issue #234).
            $diffs = PostgresExpressionEquivalence::dropEquivalent(
                $diffs,
                $this->manager->getDB('source'),
                $this->manager->getDB('target')
            );
            ColumnDependantPlan::apply($diffs);
            EnumSwapPlan::apply($diffs, $this->manager->getDB('source'), $this->manager->getDB('target'));
            CreationOrderPlan::apply($diffs, $this->manager->getDB('source'), $this->manager->getDB('target'));
            // Last, knowing what the changes above drop and recreate.
            $diffs = array_merge($diffs, PostgresComments::diff(
                $this->manager->getDB('source'),
                $this->manager->getDB('target'),
                $diffs,
                array_values(array_diff($allTables, $sourceTables, $targetTables))
            ));
        }

        return $diffs;
    }

    /**
     * Foreign keys among tables made (or dropped) together that point at a
     * table made after their own: in a cycle of references there is no order
     * in which every table can be created with its keys, and the migration
     * failed with `relation "b" does not exist`. Each is left out of its
     * table and added by a change of its own once all of them exist — or,
     * for tables being dropped, dropped first, so neither table is still
     * referenced when it goes, and added back once DOWN has remade both.
     *
     * @param array<int, AddTable|DropTable> $tables
     * @return list<AlterTableAddConstraint|AlterTableDropConstraint>
     */
    private function keysAcrossACycle(array $tables, string $side): array {
        $byName = [];
        foreach ($tables as $diff) {
            $byName[$diff->table] = $diff;
        }
        $changes = [];
        foreach (PostgresTableParts::foreignKeysAmong($this->manager->getDB($side), array_keys($byName)) as $key) {
            $from = $byName[$key['table']];
            $to   = $byName[$key['references']];
            if ($from === $to || $to->sortOrder < $from->sortOrder) {
                continue;
            }
            $from->withoutConstraints[] = $key['name'];
            $changes[] = $from instanceof AddTable
                ? new AlterTableAddConstraint($key['table'], $key['name'], new DiffOpAdd($key['definition']))
                : new AlterTableDropConstraint($key['table'], $key['name'], new DiffOpRemove($key['definition']));
        }
        return $changes;
    }

    /**
     * Composite types, domains, standalone sequences, materialised views and row
     * level security.
     *
     * Read through PostgresObjectKinds rather than the adapter interface, so the
     * drivers that have none of these carry no methods saying so.
     */
    private function diffPostgresObjectKinds(array $sourceTables, array $targetTables): array {
        $source = $this->manager->getDB('source');
        $target = $this->manager->getDB('target');

        $extensionsAt = [PostgresObjectKinds::extensions($source), PostgresObjectKinds::extensions($target)];
        $extensions = [];
        foreach (array_diff_key($extensionsAt[0], $extensionsAt[1]) as $name => $definition) {
            $extensions[] = new CreateExtension($name, $definition);
        }
        foreach (array_diff_key($extensionsAt[1], $extensionsAt[0]) as $name => $definition) {
            $extensions[] = new DropExtension($name, $definition);
        }

        return array_merge(
            // What the types and tables below may use: an extension's type or
            // operator class (an exclusion constraint's btree_gist).
            $extensions,
            // Like enums, a composite, a domain or a sequence can be referenced
            // by a column — by its type, or by a nextval() default — so
            // DiffSorter emits these ahead of the tables.
            $this->diffNamedObjects(
                PostgresObjectKinds::compositeTypes($source),
                PostgresObjectKinds::compositeTypes($target),
                CreateCompositeType::class, DropCompositeType::class, AlterCompositeType::class
            ),
            $this->diffNamedObjects(
                PostgresObjectKinds::domains($source),
                PostgresObjectKinds::domains($target),
                CreateDomain::class, DropDomain::class, AlterDomain::class
            ),
            $this->diffNamedObjects(
                PostgresObjectKinds::sequences($source),
                PostgresObjectKinds::sequences($target),
                CreateSequence::class, DropSequence::class, AlterSequence::class
            ),
            // getViews() reads pg_views, which holds only ordinary views.
            $this->diffNamedObjects(
                PostgresObjectKinds::materializedViews($source),
                PostgresObjectKinds::materializedViews($target),
                CreateMatView::class, DropMatView::class, AlterMatView::class
            ),
            // The table flag and the policies are set by separate statements,
            // and a table with policies but the flag left off enforces none.
            $this->diffRowSecurity(
                PostgresObjectKinds::rowSecurity($source),
                PostgresObjectKinds::rowSecurity($target),
                $sourceTables,
                $targetTables
            ),
            $this->diffPolicies(
                PostgresObjectKinds::policies($source),
                PostgresObjectKinds::policies($target),
                $sourceTables,
                $targetTables
            )
        );
    }

    /**
     * Diff two [name => 'CREATE ...'] maps into Create/Drop/Alter diffs.
     *
     * Sequences, composite types, domains and materialised views are all keyed
     * by name and compared by their rendered definition, so they share one
     * implementation rather than four copies of it.
     *
     * @param  class-string $createClass
     * @param  class-string $dropClass
     * @param  class-string $alterClass
     */
    private function diffNamedObjects(
        array $source,
        array $target,
        string $createClass,
        string $dropClass,
        string $alterClass
    ): array {
        $diffs = [];

        foreach (array_diff_key($source, $target) as $name => $def) {
            $diffs[] = new $createClass($name, $def);
        }
        foreach (array_diff_key($target, $source) as $name => $def) {
            $diffs[] = new $dropClass($name, $def);
        }
        foreach (array_intersect_key($source, $target) as $name => $srcDef) {
            if ($srcDef !== $target[$name]) {
                $diffs[] = new $alterClass($name, $srcDef, $target[$name]);
            }
        }

        return $diffs;
    }

    /**
     * Diff row level security policies between source and target databases.
     *
     * Policies are keyed "table.policy" by the adapter. Only policies whose
     * table survived the table filter are considered, so an ignored table does
     * not reappear through its policies.
     */
    private function diffPolicies(
        array $sourcePolicies,
        array $targetPolicies,
        array $sourceTables,
        array $targetTables
    ): array {
        $source = $this->filterByTable($sourcePolicies, $sourceTables);
        $target = $this->filterByTable($targetPolicies, $targetTables);
        $diffs  = [];

        foreach (array_diff_key($source, $target) as $key => $data) {
            $diffs[] = new CreatePolicy($data['name'], $data['table'], $data['definition']);
        }
        foreach (array_diff_key($target, $source) as $key => $data) {
            $diffs[] = new DropPolicy($data['name'], $data['table'], $data['definition']);
        }
        foreach (array_intersect_key($source, $target) as $key => $srcData) {
            if ($srcData['definition'] !== $target[$key]['definition']) {
                $diffs[] = new AlterPolicy(
                    $srcData['name'],
                    $srcData['table'],
                    $srcData['definition'],
                    $target[$key]['definition']
                );
            }
        }

        return $diffs;
    }

    /**
     * Diff per-table row level security flags.
     *
     * A table absent from the target side is being created, and CREATE TABLE
     * leaves both flags off — so its flags are compared against off rather than
     * skipped, which is what makes RLS survive on a newly added table.
     */
    private function diffRowSecurity(
        array $source,
        array $target,
        array $sourceTables,
        array $targetTables
    ): array {
        $off    = ['enabled' => false, 'forced' => false];
        $inTarget = array_flip($targetTables);
        $diffs  = [];

        foreach ($sourceTables as $table) {
            if (!isset($source[$table])) {
                continue;
            }
            $src = $source[$table];
            $tgt = $target[$table] ?? $off;
            if ($src === $tgt) {
                continue;
            }
            $diffs[] = new AlterRowSecurity(
                $table,
                $src['enabled'],
                $src['forced'],
                $tgt['enabled'],
                $tgt['forced'],
                !isset($inTarget[$table])
            );
        }

        return $diffs;
    }

    /**
     * Keep only entries whose 'table' is in the given filtered table list.
     */
    private function filterByTable(array $items, array $tables): array {
        $allowed = array_flip($tables);
        return array_filter(
            $items,
            fn(array $item): bool => isset($allowed[$item['table']])
        );
    }

    /**
     * Pre-scan: fetch a hash of every table's schema in two batch queries (one
     * per DB side) and keep only the tables whose hashes differ. Matching
     * hashes mean the table is identical on both sides, so it can skip the
     * per-table queries that would otherwise fire — critical for large
     * Supabase databases, where most tables are unchanged.
     *
     * Adapters that return no hashes simply yield every table, so this is
     * always an optimisation and never a filter.
     *
     * @param  string[] $commonTables Tables present on both sides.
     * @return string[] Tables that still need a full schema diff.
     */
    private function selectTablesNeedingDiff(array $commonTables): array {
        $sourceHashes = $this->manager->getSchemaHashMap('source', $commonTables);
        $targetHashes = $this->manager->getSchemaHashMap('target', $commonTables);

        $needingDiff = [];
        foreach ($commonTables as $table) {
            $identical = isset($sourceHashes[$table], $targetHashes[$table])
                && $sourceHashes[$table] === $targetHashes[$table];
            if (!$identical) {
                $needingDiff[] = $table;
            }
        }

        // Printed even when nothing was skipped. `skipped 0 / 100` is the
        // signature of a hash that never matches, and suppressing it is why
        // that went unnoticed for as long as it did (issue #189, noted again
        // in #229).
        $skipped = count($commonTables) - count($needingDiff);
        Logger::info("Pre-scan: skipped $skipped / " . count($commonTables) . " unchanged tables");

        return $needingDiff;
    }

    /**
     * Batch-fetch the full schema of every changed table in 7 queries per side
     * instead of 8 queries per table per side — O(1) round-trips instead of
     * O(N). Adapters without bulk support return empty maps, and TableSchema
     * then falls back to querying each table individually.
     *
     * @param  string[] $tables Tables needing a full diff.
     * @return array{array<string,array>, array<string,array>} [source, target]
     */
    private function bulkFetchSchemas(array $tables): array {
        if (empty($tables)) {
            return [[], []];
        }

        $adapter = $this->manager->getAdapter();
        if (!$adapter instanceof BulkSchemaAdapterInterface) {
            return [[], []];
        }

        $sourceDb = $this->manager->getDB('source');
        $targetDb = $this->manager->getDB('target');

        // Counted rather than stated. The line said "14 queries" from a
        // literal, which had already drifted from what the fetch actually runs
        // (issue #229) — and a number nobody can trust is worse than none.
        // The log is enabled only around this call and flushed after, so it
        // holds the fetch's own queries and nothing else.
        self::startCountingQueries($sourceDb);
        self::startCountingQueries($targetDb);

        try {
            $source = $adapter->getBulkTableSchema($sourceDb, $tables);
            $target = $adapter->getBulkTableSchema($targetDb, $tables);
            $queries = self::countedQueries($sourceDb) + self::countedQueries($targetDb);
        } finally {
            self::stopCountingQueries($sourceDb);
            self::stopCountingQueries($targetDb);
        }

        $n = count($tables);
        // No count when the connection cannot report one — a stub in a test, or
        // anything that is not an Illuminate connection. Better to say nothing
        // than to state a zero.
        Logger::info($queries > 0
            ? "Batch schema fetch: loaded $n changed table(s) in $queries queries"
            : "Batch schema fetch: loaded $n changed table(s)");

        return [$source, $target];
    }

    /**
     * Diff views between source and target databases.
     */
    private function diffViews(): array {
        $sourceViews = $this->manager->getViews('source');
        $targetViews = $this->manager->getViews('target');
        $diffs = [];

        // Views only in source → CreateView
        foreach (array_diff_key($sourceViews, $targetViews) as $name => $def) {
            $diffs[] = new CreateView($name, $def);
        }
        // Views only in target → DropView
        foreach (array_diff_key($targetViews, $sourceViews) as $name => $def) {
            $diffs[] = new DropView($name, $def);
        }
        // Views in both but different → AlterView
        foreach (array_intersect_key($sourceViews, $targetViews) as $name => $srcDef) {
            if ($srcDef !== $targetViews[$name]) {
                $diffs[] = new AlterView($name, $srcDef, $targetViews[$name]);
            }
        }
        return $diffs;
    }

    /**
     * Diff triggers between source and target databases.
     *
     * Trigger data is returned as [name => ['definition' => ..., 'table' => ...]].
     */
    private function diffTriggers(): array {
        $sourceTriggers = $this->manager->getTriggers('source');
        $targetTriggers = $this->manager->getTriggers('target');
        $diffs = [];

        // Keys are "table.trigger" so same-named triggers on different tables
        // stay distinct (issue #187); the emitted DDL uses the bare name the
        // adapter carries alongside, falling back to the key for adapters that
        // do not supply one.
        foreach (array_diff_key($sourceTriggers, $targetTriggers) as $key => $data) {
            $diffs[] = new CreateTrigger($data['name'] ?? $key, $data['table'], $data['definition']);
        }
        foreach (array_diff_key($targetTriggers, $sourceTriggers) as $key => $data) {
            $diffs[] = new DropTrigger($data['name'] ?? $key, $data['table'], $data['definition']);
        }
        foreach (array_intersect_key($sourceTriggers, $targetTriggers) as $key => $srcData) {
            $tgtData = $targetTriggers[$key];
            if ($srcData['definition'] !== $tgtData['definition']) {
                $diffs[] = new AlterTrigger(
                    $srcData['name'] ?? $key,
                    $srcData['table'],
                    $srcData['definition'],
                    $tgtData['definition']
                );
            }
        }
        return $diffs;
    }

    /**
     * Diff stored routines (procedures and functions) between source and target.
     */
    private function diffRoutines(): array {
        $sourceRoutines = $this->manager->getRoutines('source');
        $targetRoutines = $this->manager->getRoutines('target');
        $diffs = [];

        foreach (array_diff_key($sourceRoutines, $targetRoutines) as $name => $def) {
            $diffs[] = new CreateRoutine($name, $def);
        }
        foreach (array_diff_key($targetRoutines, $sourceRoutines) as $name => $def) {
            $diffs[] = new DropRoutine($name, $def);
        }
        foreach (array_intersect_key($sourceRoutines, $targetRoutines) as $name => $srcDef) {
            if ($srcDef !== $targetRoutines[$name]) {
                $diffs[] = new AlterRoutine($name, $srcDef, $targetRoutines[$name]);
            }
        }
        return $diffs;
    }

    /**
     * Diff enum types between source and target databases.
     */
    private function diffEnums(): array {
        $sourceEnums = $this->manager->getEnums('source');
        $targetEnums = $this->manager->getEnums('target');
        $diffs = [];

        foreach (array_diff_key($sourceEnums, $targetEnums) as $name => $def) {
            $diffs[] = new CreateEnum($name, $def);
        }
        foreach (array_diff_key($targetEnums, $sourceEnums) as $name => $def) {
            $diffs[] = new DropEnum($name, $def);
        }
        foreach (array_intersect_key($sourceEnums, $targetEnums) as $name => $srcDef) {
            if ($srcDef !== $targetEnums[$name]) {
                $diffs[] = new AlterEnum($name, $srcDef, $targetEnums[$name]);
            }
        }
        return $diffs;
    }

    /**
     * Give LOGGED/UNLOGGED changes a foreign-key order.
     *
     * A logged table cannot reference an unlogged one, so of two unlogged
     * tables joined by a foreign key the referenced one has to become logged
     * first, and the other way round going back:
     *
     *     ERROR:  could not change table "c" to logged because it references
     *             unlogged table "p"
     *
     * DiffSorter orders same-kind diffs by name otherwise, which put "c" before
     * "p". The rank here is parents-first; DiffSorter reads it ascending for
     * SET LOGGED and descending for SET UNLOGGED.
     *
     * @param array<int, object> $diffs
     */
    private function orderPersistenceChanges(array $diffs): void
    {
        $changes = array_filter($diffs, fn($d) => $d instanceof AlterTablePersistence);
        if (count($changes) < 2) {
            return;
        }

        $tables = array_values(array_unique(array_map(fn($d) => $d->table, $changes)));
        $rank   = array_flip(TableOrder::sort($tables, $this->manager->getForeignKeyMap('target')));
        foreach ($changes as $diff) {
            $diff->sortOrder = $rank[$diff->table] ?? null;
        }
    }

    /**
     * Query counting around the bulk fetch.
     *
     * The log is enabled only for this call and flushed after, so it holds the
     * fetch's own queries and nothing else. Every step is guarded: a connection
     * that cannot log — a test double, or anything that is not an Illuminate
     * connection — simply reports nothing rather than failing the diff.
     */
    private static function startCountingQueries($db): void
    {
        try {
            if (method_exists($db, 'flushQueryLog')) {
                $db->flushQueryLog();
            }
            if (method_exists($db, 'enableQueryLog')) {
                $db->enableQueryLog();
            }
        } catch (\Throwable $e) {
            // Counting is a log line, never a reason to fail.
        }
    }

    private static function countedQueries($db): int
    {
        try {
            if (!method_exists($db, 'getQueryLog')) {
                return 0;
            }
            $log = $db->getQueryLog();
            return is_array($log) ? count($log) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function stopCountingQueries($db): void
    {
        try {
            if (method_exists($db, 'disableQueryLog')) {
                $db->disableQueryLog();
            }
            if (method_exists($db, 'flushQueryLog')) {
                $db->flushQueryLog();
            }
        } catch (\Throwable $e) {
            // As above.
        }
    }
}

