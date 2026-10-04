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
        $t = $this->dialect->qualify($this->obj->table);
        return "DROP TABLE $t;";
    }

    public function getDown(): string {
        $table = $this->obj->table;
        // Rendered now, from the schema the table is in: a diff over several
        // schemas has left the connections pointing at the last one.
        if ($this->obj->schema !== null) {
            $this->obj->manager->useSchema($this->obj->schema);
        }
        return $this->obj->manager->getCreateStatement($this->obj->connectionName, $table) . ';';
    }

}
