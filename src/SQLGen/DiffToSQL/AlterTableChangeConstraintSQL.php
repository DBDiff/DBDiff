<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableChangeConstraintSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    private function buildChange(string $dropSchema, string $addSchema): string {
        $t    = $this->dialect->quote($this->obj->table);
        $drop = $this->dialect->dropConstraint($this->obj->table, $this->obj->name, $dropSchema);
        return "$drop\nALTER TABLE $t ADD $addSchema;";
    }

    public function getUp(): string {
        return $this->buildChange($this->obj->diff->getOldValue(), $this->obj->diff->getNewValue());
    }

    public function getDown(): string {
        return $this->buildChange($this->obj->diff->getNewValue(), $this->obj->diff->getOldValue());
    }

}
