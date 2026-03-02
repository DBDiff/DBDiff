<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class UpdateDataSQL implements SQLGenInterface {

    protected SQLDialectInterface $dialect;

    function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }
    
    public function getUp(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $d      = $this->dialect;
        $values = $this->obj->diff['diff'];
        array_walk($values, function (&$diff, $column) use ($d) {
            $q    = $d->quote($column);
            $diff = is_null($diff->getNewValue())
                ? "$q = NULL"
                : "$q = '" . addslashes($diff->getNewValue()) . "'";
        });
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = $d->quote($column) . " = '" . addslashes($value) . "'";
        });
        return "UPDATE $t SET " . implode(', ', $values) . ' WHERE ' . implode(' AND ', $keys) . ';';
    }

    public function getDown(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $d      = $this->dialect;
        $values = $this->obj->diff['diff'];
        array_walk($values, function (&$diff, $column) use ($d) {
            $diff = $d->quote($column) . " = '" . addslashes($diff->getOldValue()) . "'";
        });
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = $d->quote($column) . " = '" . addslashes($value) . "'";
        });
        return "UPDATE $t SET " . implode(', ', $values) . ' WHERE ' . implode(' AND ', $keys) . ';';
    }
}
