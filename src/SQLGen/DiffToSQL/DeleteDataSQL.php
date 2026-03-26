<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class DeleteDataSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }
    
    public function getUp(): string {
        $t    = $this->dialect->quote($this->obj->table);
        $d    = $this->dialect;
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = $d->quote($column) . " = '" . addslashes($value) . "'";
        });
        return "DELETE FROM $t WHERE " . implode(' AND ', $keys) . ';';
    }

    public function getDown(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $d      = $this->dialect;
        $row    = $this->obj->diff['diff']->getOldValue();
        $cols   = implode(',', array_map(fn($c) => $d->quote($c), array_keys($row)));
        $values = array_map(function ($el) {
            return is_null($el) ? 'NULL' : "'" . addslashes($el) . "'";
        }, $row);
        return "INSERT INTO $t ($cols) VALUES(" . implode(',', $values) . ");";
    }

}
