<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


class AlterRowSecuritySQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->statements($this->obj->sourceEnabled, $this->obj->sourceForced);
    }

    public function getDown(): string {
        // The table itself is dropped on the way down, so its flags need no
        // restoring — and an ALTER would fail against a relation that is gone.
        if ($this->obj->isNewTable) {
            return '';
        }
        return $this->statements($this->obj->targetEnabled, $this->obj->targetForced);
    }

    private function statements(bool $enabled, bool $forced): string {
        $table = $this->dialect->quote($this->obj->table);
        $lines = [
            'ALTER TABLE ' . $table . ($enabled ? ' ENABLE' : ' DISABLE') . ' ROW LEVEL SECURITY;',
            'ALTER TABLE ' . $table . ($forced ? ' FORCE' : ' NO FORCE') . ' ROW LEVEL SECURITY;',
        ];
        return implode("\n", $lines);
    }
}
