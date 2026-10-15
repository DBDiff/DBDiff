<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\InSchema;

/**
 * A table's REPLICA IDENTITY: what logical replication sends of a changed
 * or deleted row. Supabase Realtime sends the old record only under FULL.
 */
class AlterTableReplicaIdentity {
    use InSchema;

    public $table;
    public $column = null;
    public $key = null;
    public $name = null;
    public $diff = null;
    public $source = null;
    public $target = null;
    public ?int $sortOrder = null;

    public function __construct(string $table, public string $identity, public string $prevIdentity) {
        $this->table = $table;
    }
}
