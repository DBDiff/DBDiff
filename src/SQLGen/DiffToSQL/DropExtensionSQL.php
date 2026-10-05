<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/** An extension the target has installed in the schema and the source has not. */
class DropExtensionSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return 'DROP EXTENSION IF EXISTS ' . $this->dialect->quote($this->obj->name) . ';';
    }

    public function getDown(): string {
        return $this->obj->definition . ';';
    }
}
