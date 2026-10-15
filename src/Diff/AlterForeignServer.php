<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresForeignData::foreignServers(). */
class AlterForeignServer {
    use ChangedSchemaObject;
}
