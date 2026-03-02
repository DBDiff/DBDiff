<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableAddKeySQL implements SQLGenInterface {

    protected SQLDialectInterface $dialect;

    function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        $table  = $this->obj->table;
        $t      = $this->dialect->quote($table);
        $schema = $this->obj->diff->getNewValue();
        // For MySQL, schema is an inline key definition (e.g. KEY `idx` (`col`))
        // For Postgres/SQLite, schema is a full CREATE INDEX statement
        if ($this->dialect->getDriver() === 'mysql') {
            return "ALTER TABLE $t ADD $schema;";
        }
        return $schema . ';';
    }

    public function getDown(): string {
        return $this->dialect->dropIndex($this->obj->table, $this->obj->key);
    }

}
