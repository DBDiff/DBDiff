<?php namespace DBDiff\SQLGen;

use DBDiff\SQLGen\Schema\SchemaSQLGen;
use DBDiff\SQLGen\Schema\SchemaDiffSorter;
use DBDiff\Logger;


class SQLGenerator implements SQLGenInterface {

    function __construct($diff) {
        $diffSorter = new SchemaDiffSorter;
        $this->schemaDiff = $diffSorter->sort($diff['schema']);
        $this->dataDiff   = $diff['data'];
    }
    
    public function getUp() {
        Logger::info("Now generating UP migration");
        $up = "";
        $up .= MigrationGenerator::generate($this->schemaDiff, 'getUp', 'schema');
        $up .= MigrationGenerator::generate($this->dataDiff, 'getUp', 'data');
        return $up;
    }

    public function getDown() {
        Logger::info("Now generating DOWN migration");
        $down = "";
        $down .= MigrationGenerator::generate($this->schemaDiff, 'getDown', 'schema');
        $down .= MigrationGenerator::generate($this->dataDiff, 'getDown', 'data');
        return $down;
    }
}
