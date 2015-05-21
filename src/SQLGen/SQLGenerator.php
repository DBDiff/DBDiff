<?php namespace DBDiff\SQLGen;

use DBDiff\SQLGen\Schema\SchemaSQLGen;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\Logger;


class SQLGenerator implements SQLGenInterface {

    function __construct($diff) {
        $this->diffSorter = new DiffSorter;
        $this->diff = array_merge($diff['schema'], $diff['data']);
    }
    
    public function getUp() {
        Logger::info("Now generating UP migration");
        $diff = $this->diffSorter->sort($this->diff, 'up');
        return MigrationGenerator::generate($diff, 'getUp');
    }

    public function getDown() {
        Logger::info("Now generating DOWN migration");
        $diff = $this->diffSorter->sort($this->diff, 'down');
        return MigrationGenerator::generate($diff, 'getDown');
    }
}
