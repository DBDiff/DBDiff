<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresCatalogObjects::rules(). */
class AlterRule {
    use ChangedSchemaObject;
}
