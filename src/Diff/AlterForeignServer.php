<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresCatalogObjects::foreignServers(). */
class AlterForeignServer {
    use ChangedSchemaObject;
}
