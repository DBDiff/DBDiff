<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;


interface BulkSchemaAdapterInterface {

    /**
     * Fetch the full schema for multiple tables in a fixed number of queries,
     * regardless of how many tables are requested.
     *
     * Returns the same structure as getTableSchema() but keyed by table name:
     *
     *   [ tableName => [
     *       'engine'      => null,
     *       'collation'   => null,
     *       'columns'     => [ colName => ddl_fragment, ... ],
     *       'keys'        => [ keyName => ddl_fragment, ... ],
     *       'constraints' => [ constraintName => ddl_fragment, ... ],
     *     ], ... ]
     *
     * Every requested table gets an entry. A table that does not exist (or has
     * no columns, indexes or constraints) yields empty sub-arrays rather than
     * being omitted, so callers can index the result without an isset() guard.
     *
     * An empty $tables list returns an empty array without issuing any query.
     */
    public function getBulkTableSchema(Connection $connection, array $tables): array;
}
