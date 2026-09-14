<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/**
 * Policy changes, applied by recreation.
 *
 * ALTER POLICY cannot move a policy between commands (a SELECT policy cannot
 * become an INSERT one), so the whole policy is replaced rather than amended.
 */
class AlterPolicySQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->drop() . "\n" . $this->obj->sourceDefinition . ';';
    }

    public function getDown(): string {
        return $this->drop() . "\n" . $this->obj->targetDefinition . ';';
    }

    private function drop(): string {
        return 'DROP POLICY IF EXISTS ' . $this->dialect->quote($this->obj->name)
            . ' ON ' . $this->dialect->quote($this->obj->table) . ';';
    }
}
