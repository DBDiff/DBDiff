<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

class AlterTableRenameKeySQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->rename($this->obj->from, $this->obj->key);
    }

    public function getDown(): string {
        return $this->rename($this->obj->key, $this->obj->from);
    }

    private function rename(string $from, string $to): string {
        return 'ALTER INDEX ' . $this->qualifiedIndex($from) . ' RENAME TO ' . $this->dialect->quote($to) . ';';
    }

    /** An index lives in its table's schema. */
    private function qualifiedIndex(string $name): string {
        $table = $this->dialect->qualify($this->obj->table);
        $dot = strrpos($table, '".');
        return $dot === false ? $this->dialect->quote($name) : substr($table, 0, $dot + 2) . $this->dialect->quote($name);
    }
}
