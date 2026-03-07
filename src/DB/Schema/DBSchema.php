<?php namespace DBDiff\DB\Schema;

use Diff\Differ\ListDiffer;

use DBDiff\Params\ParamsFactory;
use DBDiff\Diff\SetDBCollation;
use DBDiff\Diff\SetDBCharset;
use DBDiff\Diff\DropTable;
use DBDiff\Diff\AddTable;
use DBDiff\Diff\AlterTable;



class DBSchema {

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

        if (isset($params->tablesToInclude)) {
            $sourceTables = array_value_includes($sourceTables, $params->tablesToInclude;
            $targetTables = array_value_includes($targetTables, $params->tablesToInclude;
        }

        if (isset($params->tablesToIgnore)) {
            $sourceTables = array_value_excludes($sourceTables, $params->tablesToIgnore);
            $targetTables = array_value_excludes($targetTables, $params->tablesToIgnore);
        }

        $addedTables = array_diff($sourceTables, $targetTables);
        foreach ($addedTables as $table) {
            $diffs[] = new AddTable($table, $this->manager, 'source');
        }

        $commonTables = array_intersect($sourceTables, $targetTables);
        foreach ($commonTables as $table) {
            $tableDiff = $tableSchema->getDiff($table);
            $diffs = array_merge($diffs, $tableDiff);
        }

        $deletedTables = array_diff($targetTables, $sourceTables);
        foreach ($deletedTables as $table) {
            $diffs[] = new DropTable($table, $this->manager, 'target');
        }

        return $diffs;
    }
}

