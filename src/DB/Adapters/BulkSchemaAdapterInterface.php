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
     * Tables absent from the result (e.g. those not found in the database)
     * are simply omitted — the caller falls back to individual queries for them.
     */
    public function getBulkTableSchema(Connection $connection, array $tables): array;
}
