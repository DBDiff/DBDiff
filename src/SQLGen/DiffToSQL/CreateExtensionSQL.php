<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/** An extension the source has installed in the schema and the target has not. */
class CreateExtensionSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->obj->definition . ';';
    }

    public function getDown(): string {
        return 'DROP EXTENSION IF EXISTS ' . $this->dialect->quote($this->obj->name) . ';';
    }
}
