<?php namespace DBDiff\SQLGen;

use DBDiff\SQLGen\Schema\SchemaSQLGen;
use DBDiff\SQLGen\DiffSorter;
use DBDiff\Logger;
use DBDiff\DB\Support\MySqlTimestamps;


class SQLGenerator implements SQLGenInterface {

    protected $diffSorter;
    protected $diff;
    /** Whether the SQL writes MySQL TIMESTAMP values, and so must run in UTC. */
    private bool $utc;

    /**
     * @param bool        $units      mark each change as one unit (see MigrationGenerator::UNIT_BEGIN)
     * @param string|null $delimiter  MySQL: put routine bodies between DELIMITER lines (see MySqlDelimiter)
     */
    public function __construct($diff, private bool $units = false, private ?string $delimiter = null) {
        $this->diffSorter = new DiffSorter;
        $this->diff = array_merge($diff['schema'], $diff['data']);
        $this->utc  = !empty($diff['utc']);
    }
    
    public function getUp() {
        Logger::info("Now generating UP migration");
        $diff = $this->diffSorter->sort($this->diff, 'up');
        return $this->inUtc(MigrationGenerator::generate($diff, 'getUp', $this->units, $this->delimiter));
    }

    public function getDown() {
        Logger::info("Now generating DOWN migration");
        $diff = $this->diffSorter->sort($this->diff, 'down');
        return $this->inUtc(MigrationGenerator::generate($diff, 'getDown', $this->units, $this->delimiter));
    }

    private function inUtc(string $sql): string {
        return $this->utc && $sql !== '' ? MySqlTimestamps::wrap($sql) : $sql;
    }
}
