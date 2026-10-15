<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;

/**
 * SQL for the SchemaObject kinds: each carries the statements that create
 * and remove it, so creating one is its definition, dropping it its drop,
 * and a changed one is dropped as the side before has it and created as the
 * side after does.
 */
abstract class SchemaObjectSQL implements SQLGenInterface {

    protected $obj;

    public function __construct($obj, $dialect = null) {
        $this->obj = $obj;
    }

    protected static function statement(string $sql): string {
        return rtrim(trim($sql), ';') . ';';
    }
}
