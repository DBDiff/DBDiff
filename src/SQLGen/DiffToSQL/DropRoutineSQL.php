<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class DropRoutineSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->buildDrop($this->obj->definition, $this->obj->name);
    }

    public function getDown(): string {
        return $this->obj->definition . ';';
    }

    private function buildDrop(string $definition, string $name): string {
        $type = 'FUNCTION';
        if (preg_match('/\bPROCEDURE\b/i', $definition)) {
            $type = 'PROCEDURE';
        }
        return "DROP $type IF EXISTS " . $this->dialect->quote($name) . ';';
    }
}
