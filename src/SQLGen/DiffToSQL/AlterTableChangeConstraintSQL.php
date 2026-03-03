<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterTableChangeConstraintSQL implements SQLGenInterface {

    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    private function buildChange(string $schema): string {
        $t    = $this->dialect->quote($this->obj->table);
        $name = $this->dialect->quote($this->obj->name);
        return "ALTER TABLE $t DROP CONSTRAINT $name;\nALTER TABLE $t ADD $schema;";
    }

    public function getUp(): string {
        return $this->buildChange($this->obj->diff->getNewValue());
    }

    public function getDown(): string {
        return $this->buildChange($this->obj->diff->getOldValue());
    }

}
