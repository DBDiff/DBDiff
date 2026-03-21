<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class DropTableSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }
    
    public function getUp(): string {
        $t = $this->dialect->quote($this->obj->table);
        return "DROP TABLE $t;";
    }

    public function getDown(): string {
        $table = $this->obj->table;
        return $this->obj->manager->getCreateStatement($this->obj->connectionName, $table) . ';';
    }

}
