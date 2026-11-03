<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class CreateDomainSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    /**
     * CREATE DOMAIN does not accept NOT VALID, so a constraint the source holds
     * unvalidated is added after the domain exists, still unvalidated.
     */
    public function getUp(): string {
        [$head, $constraints] = AlterDomainSQL::splitConstraints($this->obj->definition);
        $create = $head;
        $later  = [];
        foreach ($constraints as $name => $body) {
            if (str_ends_with($body, ' NOT VALID')) {
                $later[] = 'ALTER DOMAIN ' . $this->dialect->qualify($this->obj->name) . " ADD CONSTRAINT $name $body;";
            } else {
                $create .= " CONSTRAINT $name $body";
            }
        }
        return implode("\n", [$create . ';', ...$later]);
    }

    public function getDown(): string {
        return 'DROP DOMAIN IF EXISTS ' . $this->dialect->qualify($this->obj->name) . ';';
    }
}
