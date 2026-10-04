<?php namespace DBDiff\DB;

use DBDiff\DB\Schema\DBSchema;
use DBDiff\DB\Schema\TableSchema;
use DBDiff\DB\Schema\MultiSchemaDiff;
use DBDiff\DB\Support\SchemaSelection;
use DBDiff\DB\Data\DBData;
use DBDiff\DB\Data\TableData;
use DBDiff\SQLGen\Dialect\DialectRegistry;


class DiffCalculator {

    protected $manager;

    function __construct() {
        $this->manager = new DBManager;
    }
    
    public function getDiff($params) {
        
        // Connect and test accessibility
        $this->manager->connect($params);
        $this->manager->testResources($params);

        // Initialise the SQL dialect for this run so all DiffToSQL classes
        // generate correctly-quoted SQL for the target driver.
        DialectRegistry::setForDriver($this->manager->getDriver());

        if ($this->manager->getDriver() === 'pgsql' && $params->input['kind'] === 'db') {
            $schemas = SchemaSelection::resolve($params, $this->manager->getDB('source'), $this->manager->getDB('target'));
            if (!SchemaSelection::isDefault($schemas)) {
                return (new MultiSchemaDiff($this->manager))->getDiff($schemas, fn() => $this->diffOne($params), $params->type !== 'data');
            }
        }

        [$schemaDiff, $dataDiff] = $this->diffOne($params);

        return [
            'schema' => $schemaDiff,
            'data'   => $dataDiff,
        ];

    }

    /** The schema and data diff of the connections as they stand. */
    private function diffOne($params): array {
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

        return [$schemaDiff, $dataDiff];
    }
}
