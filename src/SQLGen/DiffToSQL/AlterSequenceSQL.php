<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/**
 * Sequence option changes, applied in place.
 *
 * Unlike a view or an enum, a sequence carries a value. Dropping and recreating
 * it would reset the counter, so the CREATE is rewritten to an ALTER: every
 * clause the adapter emits (AS, INCREMENT BY, MINVALUE, MAXVALUE, START WITH,
 * CACHE, CYCLE) is accepted by ALTER SEQUENCE unchanged.
 */
class AlterSequenceSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->toAlter($this->obj->sourceDefinition);
    }

    public function getDown(): string {
        return $this->toAlter($this->obj->targetDefinition);
    }

    private function toAlter(string $createStatement): string {
        return preg_replace(
            '/^CREATE SEQUENCE\b/',
            'ALTER SEQUENCE',
            $createStatement,
            1
        ) . ';';
    }
}
