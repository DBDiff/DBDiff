<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

class AlterTableRenameConstraintSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->rename($this->obj->from, $this->obj->name);
    }

    public function getDown(): string {
        return $this->rename($this->obj->name, $this->obj->from);
    }

    private function rename(string $from, string $to): string {
        return 'ALTER TABLE ' . $this->dialect->qualify($this->obj->table) . ' RENAME CONSTRAINT ' . $this->dialect->quote($from) . ' TO ' . $this->dialect->quote($to) . ';';
    }
}
