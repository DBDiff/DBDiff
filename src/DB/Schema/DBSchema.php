<?php namespace DBDiff\DB\Schema;

use Diff\Differ\ListDiffer;

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

        $commonTables = array_intersect($sourceTables, $targetTables);
        foreach ($commonTables as $table) {
            $tableDiff = $tableSchema->getDiff($table);
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

        return $diffs;
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

        foreach (array_diff_key($sourceTriggers, $targetTriggers) as $name => $data) {
            $diffs[] = new CreateTrigger($name, $data['table'], $data['definition']);
        }
        foreach (array_diff_key($targetTriggers, $sourceTriggers) as $name => $data) {
            $diffs[] = new DropTrigger($name, $data['table'], $data['definition']);
        }
        foreach (array_intersect_key($sourceTriggers, $targetTriggers) as $name => $srcData) {
            $tgtData = $targetTriggers[$name];
            if ($srcData['definition'] !== $tgtData['definition']) {
                $diffs[] = new AlterTrigger($name, $srcData['table'], $srcData['definition'], $tgtData['definition']);
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

