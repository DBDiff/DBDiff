<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableCollationSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        if (!$this->dialect->isMySQLOnly()) {
            return '';
        }
        $t = $this->dialect->quote($this->obj->table);
        return "ALTER TABLE $t DEFAULT COLLATE {$this->obj->collation};";
    }

    public function getDown(): string {
        if (!$this->dialect->isMySQLOnly()) {
            return '';
        }
        $t = $this->dialect->quote($this->obj->table);
        return "ALTER TABLE $t DEFAULT COLLATE {$this->obj->prevCollation};";
    }

}
