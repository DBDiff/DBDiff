<?php namespace DBDiff\DB\Schema;

use Diff\Differ\ListDiffer;

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
use DBDiff\DB\Adapters\BulkSchemaAdapterInterface;
use DBDiff\DB\Support\PostgresObjectKinds;



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

        $sourceTables = TableFilter::filterTables($sourceTables, $params, 'schema');
        $targetTables = TableFilter::filterTables($targetTables, $params, 'schema');

        $addedTables = array_values(array_diff($sourceTables, $targetTables));
        $deletedTables = array_values(array_diff($targetTables, $sourceTables));

        // Topological sort so parents are created before children
        if (!empty($addedTables)) {
            $sourceFkMap  = $this->manager->getForeignKeyMap('source');
            $addedTables  = $this->topologicalSort($addedTables, $sourceFkMap);
        }
        if (!empty($deletedTables)) {
            $targetFkMap    = $this->manager->getForeignKeyMap('target');
            $deletedTables  = $this->topologicalSort($deletedTables, $targetFkMap);
        }

        foreach ($addedTables as $i => $table) {
            $diff = new AddTable($table, $this->manager, 'source');
            $diff->sortOrder = $i;
            $diffs[] = $diff;
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

        foreach ($deletedTables as $i => $table) {
            $diff = new DropTable($table, $this->manager, 'target');
            $diff->sortOrder = $i;
            $diffs[] = $diff;
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
        }

        return $diffs;
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

        return array_merge(
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

        $skipped = count($commonTables) - count($needingDiff);
        if ($skipped > 0) {
            Logger::info("Pre-scan: skipped $skipped / " . count($commonTables) . " unchanged tables");
        }

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

        $source = $adapter->getBulkTableSchema($this->manager->getDB('source'), $tables);
        $target = $adapter->getBulkTableSchema($this->manager->getDB('target'), $tables);

        $n = count($tables);
        Logger::info("Batch schema fetch: loaded $n changed table(s) in 14 queries");

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
     * Topological sort using Kahn's algorithm.
     *
     * Returns tables ordered so that parent tables (referenced by FKs)
     * come before their children. Peers (no dependency between them)
     * are sorted alphabetically for deterministic output.
     *
     * Cycles are broken gracefully — remaining tables are appended.
     */
    private function topologicalSort(array $tables, array $fkMap): array
    {
        [$deps, $children] = $this->buildAdjacency($tables, $fkMap);

        $inDegree = array_map('count', $deps);

        $queue = [];
        foreach ($inDegree as $t => $degree) {
            if ($degree === 0) {
                $queue[] = $t;
            }
        }
        sort($queue);

        $sorted = [];
        while (!empty($queue)) {
            $current  = array_shift($queue);
            $sorted[] = $current;
            foreach ($children[$current] as $child) {
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                    sort($queue);
                }
            }
        }

        // Append any remaining tables (cycles) alphabetically
        $remaining = array_diff($tables, $sorted);
        sort($remaining);
        return array_merge($sorted, $remaining);
    }

    /**
     * Build adjacency maps for topological sort.
     *
     * @return array{array<string,string[]>, array<string,string[]>}
     */
    private function buildAdjacency(array $tables, array $fkMap): array
    {
        $tableSet = array_flip($tables);
        $deps     = array_fill_keys($tables, []);
        $children = array_fill_keys($tables, []);
        foreach ($tables as $table) {
            foreach ($fkMap[$table] ?? [] as $parent) {
                if (isset($tableSet[$parent]) && $parent !== $table) {
                    $deps[$table][]      = $parent;
                    $children[$parent][] = $table;
                }
            }
        }
        return [$deps, $children];
    }
}

