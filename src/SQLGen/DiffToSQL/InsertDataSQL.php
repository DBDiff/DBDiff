<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class InsertDataSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }
    
    public function getUp(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $values = $this->obj->diff['diff']->getNewValue();
        $values = array_map(function ($el) {
            return is_null($el) ? 'NULL' : "'" . addslashes($el) . "'";
        }, $values);
        return "INSERT INTO $t VALUES(" . implode(',', $values) . ");";
    }

    public function getDown(): string {
        $t    = $this->dialect->quote($this->obj->table);
        $d    = $this->dialect;
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = $d->quote($column) . " = '" . addslashes($value) . "'";
        });
        return "DELETE FROM $t WHERE " . implode(' AND ', $keys) . ';';
    }
}
