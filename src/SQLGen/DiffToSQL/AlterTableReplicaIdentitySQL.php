<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

class AlterTableReplicaIdentitySQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return 'ALTER TABLE ' . $this->dialect->qualify($this->obj->table) . ' REPLICA IDENTITY ' . $this->obj->identity . ';';
    }

    public function getDown(): string {
        return 'ALTER TABLE ' . $this->dialect->qualify($this->obj->table) . ' REPLICA IDENTITY ' . $this->obj->prevIdentity . ';';
    }
}
