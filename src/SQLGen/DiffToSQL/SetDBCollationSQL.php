<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class SetDBCollationSQL implements SQLGenInterface {

    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        if (!$this->dialect->isMySQLOnly()) {
            return '';
        }
        $db = $this->dialect->quote($this->obj->db);
        return "ALTER DATABASE $db COLLATE {$this->obj->collation};";
    }

    public function getDown(): string {
        if (!$this->dialect->isMySQLOnly()) {
            return '';
        }
        $db = $this->dialect->quote($this->obj->db);
        return "ALTER DATABASE $db COLLATE {$this->obj->prevCollation};";
    }

}
