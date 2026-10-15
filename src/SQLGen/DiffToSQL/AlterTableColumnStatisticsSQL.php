<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

class AlterTableColumnStatisticsSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->statement($this->obj->statistics);
    }

    public function getDown(): string {
        // A column the UP added goes again in the DOWN, its target with it.
        return $this->obj->newColumn ? '' : $this->statement($this->obj->prevStatistics);
    }

    private function statement(int $target): string {
        return 'ALTER TABLE ' . $this->dialect->qualify($this->obj->table)
            . ' ALTER COLUMN ' . $this->dialect->quote($this->obj->column) . " SET STATISTICS $target;";
    }
}
