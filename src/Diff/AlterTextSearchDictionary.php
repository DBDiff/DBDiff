<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresCatalogObjects::textSearchDictionaries(). */
class AlterTextSearchDictionary {
    use ChangedSchemaObject;
}
