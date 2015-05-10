<?php namespace DBDiff\DB;

use DBDiff\DB\Schema\DBSchema;
use DBDiff\DB\Schema\TableSchema;
use DBDiff\DB\Data\DBData;
use DBDiff\DB\Data\TableData;


class DiffCalculator {

    function __construct() {
        $this->manager = new DBManager;
    }
    
    public function getDiff($params) {
        
        // Connect and test accessibility
        $this->manager->connect($params);
        $this->manager->testResources($params);

        // Schema diff
        $schemaDiff = [];
        if ($params->type !== 'data') {
            if ($params->input['kind'] === 'db') {
                $dbSchema = new DBSchema($this->manager);
                $schemaDiff = $dbSchema->getDiff();
            } else {
                $tableSchema = new TableSchema($this->manager);
                $schemaDiff = $tableSchema->getDiff($params->input['source']['table']);
            }
        }

        // Data diff
        $dataDiff = [];
        if ($params->type !== 'schema') {
            if ($params->input['kind'] === 'db') {
                $dbData = new DBData($this->manager);
                $dataDiff = $dbData->getDiff();
            } else {
                $tableData = new TableData($this->manager);
                $dataDiff = $tableData->getDiff($params->input['source']['table']);
            }
        }

        return [
            'schema' => $schemaDiff,
            'data'   => $dataDiff,
        ];

    }
}
