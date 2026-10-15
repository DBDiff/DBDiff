<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresDefaultPrivileges::defaultPrivileges(). */
class AlterDefaultPrivileges {
    use ChangedSchemaObject;
}
