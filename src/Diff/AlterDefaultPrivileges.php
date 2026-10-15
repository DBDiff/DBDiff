<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresCatalogObjects::defaultPrivileges(). */
class AlterDefaultPrivileges {
    use ChangedSchemaObject;
}
