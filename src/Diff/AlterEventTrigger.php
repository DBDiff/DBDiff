<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresCatalogObjects::eventTriggers(). */
class AlterEventTrigger {
    use ChangedSchemaObject;
}
