<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;


interface DBAdapterInterface {

    /**
     * Build the Illuminate connection configuration array for this driver.
     *
     * @param array  $server  Connection details (host, port, user, password).
     *                        For SQLite pass an empty array and put the file
     *                        path in $db instead.
     * @param string $db      Database name or absolute file path (SQLite).
     */
    public function buildConnectionConfig(array $server, string $db): array;

    /**
     * Return a flat list of user table names visible on the connection.
     */
    public function getTables(Connection $connection): array;

    /**
     * Return column names for the given table.
     */
    public function getColumns(Connection $connection, string $table): array;

    /**
     * Return the primary-key column name(s) for the given table.
     */
    public function getPrimaryKey(Connection $connection, string $table): array;

    /**
     * Return a normalised schema map used by TableSchema::getDiff():
     *
     *   [
     *     'engine'      => string|null,
     *     'collation'   => string|null,
     *     'columns'     => [ colName => ddl_fragment, ... ],
     *     'keys'        => [ keyName => ddl_fragment, ... ],
     *     'constraints' => [ constraintName => ddl_fragment, ... ],
     *   ]
     *
     * Column and key DDL fragments are dialect-specific strings that are
     * compared between source and target to detect changes and also inlined
     * directly into ALTER / ADD statements.
     */
    public function getTableSchema(Connection $connection, string $table): array;

    /**
     * Return a complete CREATE TABLE statement for the given table.
     * Used by AddTableSQL / DropTableSQL.
     */
    public function getCreateStatement(Connection $connection, string $table): string;

    /**
     * Return a single server-level variable value, or null if the concept
     * does not exist for this driver (e.g. collation on Postgres/SQLite).
     */
    public function getDBVariable(Connection $connection, string $variable): ?string;
}
