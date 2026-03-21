<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableDropKeySQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->dialect->dropIndex($this->obj->table, $this->obj->key);
    }

    public function getDown(): string {
        $table  = $this->obj->table;
        $schema = $this->obj->diff->getOldValue();
        if ($this->dialect->getDriver() === 'mysql') {
            $t = $this->dialect->quote($table);
            return "ALTER TABLE $t ADD $schema;";
        }
        return $schema . ';';
    }

}
