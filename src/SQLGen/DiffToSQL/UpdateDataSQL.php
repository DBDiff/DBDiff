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

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }
    
    /*
     * A column only one side's rows have is a column only that side's table
     * has. The UP drops one only the target has before the rows are updated,
     * and the DOWN one only the source has, so naming it in the UPDATE failed
     * with "unknown column": it is left out, and an UPDATE with nothing left
     * to set is not written.
     */
    public function getUp(): string {
        $t      = $this->dialect->qualify($this->obj->table);
        $d      = $this->dialect;
        $values = array_filter($this->obj->diff['diff'], fn($diff) => !$diff instanceof DiffOpRemove);
        if ($values === []) {
            return '';
        }
        array_walk($values, function (&$diff, $column) use ($d) {
            $q = $d->quote($column);
            if (!method_exists($diff, 'getNewValue') || is_null($diff->getNewValue())) {
                $diff = "$q = NULL";
            } else {
                $diff = "$q = " . BinaryValue::formatSQL($diff->getNewValue(), $d);
            }
        });
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = BinaryValue::formatCondition($d->quote($column), $value, $d);
        });
        return "UPDATE $t SET " . implode(', ', $values) . ' WHERE ' . implode(' AND ', $keys) . ';';
    }

    public function getDown(): string {
        $t      = $this->dialect->qualify($this->obj->table);
        $d      = $this->dialect;
        $values = array_filter($this->obj->diff['diff'], fn($diff) => !$diff instanceof DiffOpAdd);
        if ($values === []) {
            return '';
        }
        array_walk($values, function (&$diff, $column) use ($d) {
            $q = $d->quote($column);
            if (!method_exists($diff, 'getOldValue') || is_null($diff->getOldValue())) {
                $diff = "$q = NULL";
            } else {
                $diff = "$q = " . BinaryValue::formatSQL($diff->getOldValue(), $d);
            }
        });
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = BinaryValue::formatCondition($d->quote($column), $value, $d);
        });
        return "UPDATE $t SET " . implode(', ', $values) . ' WHERE ' . implode(' AND ', $keys) . ';';
    }
}
