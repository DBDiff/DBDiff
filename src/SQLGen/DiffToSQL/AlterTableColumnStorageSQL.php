<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableColumnStorageSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->statement($this->obj->storage);
    }

    public function getDown(): string {
        return $this->obj->newColumn ? '' : $this->statement($this->obj->prevStorage);
    }

    private function statement(string $storage): string {
        return 'ALTER TABLE ' . $this->dialect->qualify($this->obj->table)
            . ' ALTER COLUMN ' . $this->dialect->quote($this->obj->column) . " SET STORAGE $storage;";
    }
}
