<?php namespace DBDiff\Diff;

use DBDiff\Diff\Concerns\ChangedSchemaObject;

/** See PostgresTextSearch::textSearchDictionaries(). */
class AlterTextSearchDictionary {
    use ChangedSchemaObject;
}
