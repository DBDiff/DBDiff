<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresCatalogObjects::foreignTables(). */
class AlterForeignTable {
    use ChangedSchemaObject;
}
