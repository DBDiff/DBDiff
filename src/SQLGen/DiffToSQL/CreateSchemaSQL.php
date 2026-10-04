<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/**
 * A schema the source has and the target does not. Its objects are created
 * by changes of their own, after it; its DOWN runs after they are dropped.
 */
class CreateSchemaSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return 'CREATE SCHEMA IF NOT EXISTS ' . $this->dialect->quote($this->obj->name) . ';';
    }

    public function getDown(): string {
        return 'DROP SCHEMA IF EXISTS ' . $this->dialect->quote($this->obj->name) . ';';
    }
}
