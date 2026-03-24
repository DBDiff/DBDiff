<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableChangeKeySQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    private function buildChange(string $table, string $key, string $schema): string {
        $drop = $this->dialect->dropIndex($table, $key);
        if ($this->dialect->getDriver() === 'mysql') {
            $t = $this->dialect->quote($table);
            return "$drop\nALTER TABLE $t ADD $schema;";
        }
        return "$drop\n$schema;";
    }

    public function getUp(): string {
        return $this->buildChange(
            $this->obj->table,
            $this->obj->key,
            $this->obj->diff->getNewValue()
        );
    }

    public function getDown(): string {
        return $this->buildChange(
            $this->obj->table,
            $this->obj->key,
            $this->obj->diff->getOldValue()
        );
    }

}
