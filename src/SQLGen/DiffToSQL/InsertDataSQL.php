<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;
use DBDiff\DB\Data\BinaryValue;


class InsertDataSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }
    
    public function getUp(): string {
        $t      = $this->dialect->quote($this->obj->table);
        $d      = $this->dialect;
        $row    = $this->obj->diff['diff']->getNewValue();
        $cols   = implode(',', array_map(fn($c) => $d->quote($c), array_keys($row)));
        $values = array_map(fn($el) => BinaryValue::formatSQL($el), $row);
        return "INSERT INTO $t ($cols) VALUES(" . implode(',', $values) . ");";
    }

    public function getDown(): string {
        $t    = $this->dialect->quote($this->obj->table);
        $d    = $this->dialect;
        $keys = $this->obj->diff['keys'];
        array_walk($keys, function (&$value, $column) use ($d) {
            $value = BinaryValue::formatCondition($d->quote($column), $value);
        });
        return "DELETE FROM $t WHERE " . implode(' AND ', $keys) . ';';
    }
}
