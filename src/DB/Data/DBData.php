<?php namespace DBDiff\DB\Data;

use DBDiff\Params\ParamsFactory;
use DBDiff\Diff\SetDBCollation;
use DBDiff\Exceptions\DataException;
use DBDiff\Logger;


class DBData {

    function __construct($manager) {
        $this->manager = $manager;
    }
    
    function getDiff() {
        $params = ParamsFactory::get();

        $diffSequence = [];

        // Tables
        $tableData = new TableData($this->manager);

        $sourceTables = $this->manager->getTables('source');
        $targetTables = $this->manager->getTables('target');

	    //Only get tables that start with a certain prefix, useful on shared DB
	    if (isset($params->prefix)) {

	    	$prefix = $params->prefix.'_';

		    $sourceTables  = preg_grep('/^'.$prefix.'.*/i', $sourceTables);
		    $targetTables  = preg_grep('/^'.$prefix.'.*/i', $targetTables);

		    $tables = array();

		    foreach($params->tablesToIgnore as $table) {

		    	$tables[] = $prefix.$table;
		    }

		    $params->tablesToIgnore = array_merge($params->tablesToIgnore, $tables);
	    }

	    //Only certain Data Tables
	    if (isset($params->tablesDataToIgnore)) {

		    $sourceTables = $this->ignoreDataTables($sourceTables, $params->tablesDataToIgnore);
		    $targetTables = $this->ignoreDataTables($targetTables, $params->tablesDataToIgnore);
	    }

        if (isset($params->tablesToIgnore)) {
            $sourceTables = array_diff($sourceTables, $params->tablesToIgnore);
            $targetTables = array_diff($targetTables, $params->tablesToIgnore);
        }

        $commonTables = array_intersect($sourceTables, $targetTables);
        foreach ($commonTables as $table) {
            try {
                $diffs = $tableData->getDiff($table);
                $diffSequence = array_merge($diffSequence, $diffs);
            } catch (DataException $e) {
                Logger::error($e->getMessage());
            }
        }

        $addedTables = array_diff($sourceTables, $targetTables);
        foreach ($addedTables as $table) {
            $diffs = $tableData->getNewData($table);
            $diffSequence = array_merge($diffSequence, $diffs);
        }

        $deletedTables = array_diff($targetTables, $sourceTables);
        foreach ($deletedTables as $table) {
            $diffs = $tableData->getOldData($table);
            $diffSequence = array_merge($diffSequence, $diffs);
        }

        return $diffSequence;
    }

    function ignoreDataTables($tables_array, $exclude_array) {

	    $tables = array();

	    $pattern = implode('|', $exclude_array);

	    foreach($tables_array as $table) {

		    if(preg_match('/.*'.$pattern.'.*/', $table)) {

			    //skip

		    } else {

			    $tables[] = $table;
		    }
	    }

	    return $tables;
    }

}
