<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;
use DBDiff\DB\Data\BinaryValue;
use Diff\DiffOp\DiffOpAdd;
use Diff\DiffOp\DiffOpRemove;


class UpdateDataSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }
    
    public function getUp(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $d      = $this->dialect;
        $values = $this->obj->diff['diff'];
        array_walk($values, function (&$diff, $column) use ($d) {
            $q = $d->quote($column);
            if ($diff instanceof DiffOpRemove) {
                $diff = "$q = NULL";
            } elseif (!method_exists($diff, 'getNewValue') || is_null($diff->getNewValue())) {
                $diff = "$q = NULL";
            } else {
                $diff = "$q = " . BinaryValue::formatSQL($diff->getNewValue());
            }
        });
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = BinaryValue::formatCondition($d->quote($column), $value);
        });
        return "UPDATE $t SET " . implode(', ', $values) . ' WHERE ' . implode(' AND ', $keys) . ';';
    }

    public function getDown(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $d      = $this->dialect;
        $values = $this->obj->diff['diff'];
        array_walk($values, function (&$diff, $column) use ($d) {
            $q = $d->quote($column);
            if ($diff instanceof DiffOpAdd) {
                $diff = "$q = NULL";
            } elseif (!method_exists($diff, 'getOldValue') || is_null($diff->getOldValue())) {
                $diff = "$q = NULL";
            } else {
                $diff = "$q = " . BinaryValue::formatSQL($diff->getOldValue());
            }
        });
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = BinaryValue::formatCondition($d->quote($column), $value);
        });
        return "UPDATE $t SET " . implode(', ', $values) . ' WHERE ' . implode(' AND ', $keys) . ';';
    }
}
