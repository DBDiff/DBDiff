<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/**
 * A schema the target has and the source does not. Its objects are dropped
 * by changes of their own, before it; its DOWN runs before they are made.
 */
class DropSchemaSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return 'DROP SCHEMA IF EXISTS ' . $this->dialect->quote($this->obj->name) . ';';
    }

    public function getDown(): string {
        return 'CREATE SCHEMA IF NOT EXISTS ' . $this->dialect->quote($this->obj->name) . ';';
    }
}
