<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresForeignData::foreignTables(). */
class AlterForeignTable {
    use ChangedSchemaObject;
}
