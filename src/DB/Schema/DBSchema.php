<?php namespace DBDiff\DB\Schema;

use Diff\Differ\ListDiffer;

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
    
        $diffs = [];

        // Collation
        $dbName = $this->manager->getDB('target')->getDatabaseName();
        $sourceCollation = $this->getDBVariable('source', 'collation_database');
        $targetCollation = $this->getDBVariable('target', 'collation_database');
        if ($sourceCollation !== $targetCollation) {
            $diffs[] = new SetDBCollation($dbName, $sourceCollation, $targetCollation);
        }

        // Charset
        $sourceCharset = $this->getDBVariable('source', 'character_set_database');
        $targetCharset = $this->getDBVariable('target', 'character_set_database');
        if ($sourceCharset !== $targetCharset) {
            $diffs[] = new SetDBCharset($dbName, $sourceCharset, $targetCharset);
        }
        
        // Tables
        $tableSchema = new TableSchema($this->manager);

        $sourceTables = $this->manager->getTables('source');
        $targetTables = $this->manager->getTables('target');

        $addedTables = array_diff($sourceTables, $targetTables);
        foreach ($addedTables as $table) {
            $diffs[] = new AddTable($table, $this->manager->getDB('source'));
        }

        $commonTables = array_intersect($sourceTables, $targetTables);
        foreach ($commonTables as $table) {
            $tableDiff = $tableSchema->getDiff($table);
            $diffs = array_merge($diffs, $tableDiff);
        }

        $deletedTables = array_diff($targetTables, $sourceTables);
        foreach ($deletedTables as $table) {
            $diffs[] = new DropTable($table, $this->manager->getDB('target'));
        }

        return $diffs;
    }

    protected function getDBVariable($connection, $var) {
        $result = $this->manager->getDB($connection)->select("show variables like '$var'");
        return $result[0]['Value'];
    }

}
