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

    /**
     * Return column names whose type stores raw binary data
     * (BINARY, VARBINARY, BLOB variants).
     *
     * Used by the data-diff layer to avoid corrupting binary values
     * with text-encoding conversions and to emit UNHEX() in SQL output.
     *
     * Drivers that do not have binary-unsafe types may return [].
     */
    public function getBinaryColumns(Connection $connection, string $table): array;

    /**
     * Return the FK dependency map for all tables in the database.
     *
     * Returns [childTable => [parentTable1, parentTable2, …], …]
     * Used to topologically sort AddTable/DropTable diffs so parents
     * are created before children and children dropped before parents.
     */
    public function getForeignKeyMap(Connection $connection): array;

    /**
     * Return a map of view names to their normalised CREATE VIEW statements.
     *
     * Returns [viewName => 'CREATE VIEW ...']
     * MySQL definitions are stripped of DEFINER, ALGORITHM, SQL SECURITY.
     */
    public function getViews(Connection $connection): array;

    /**
     * Return a map of trigger names to their metadata.
     *
     * Returns [triggerName => ['definition' => 'CREATE TRIGGER ...', 'table' => 'tableName']]
     * MySQL definitions are stripped of DEFINER clauses.
     */
    public function getTriggers(Connection $connection): array;

    /**
     * Return a map of routine names to their normalised CREATE statements.
     *
     * Returns [routineName => 'CREATE PROCEDURE|FUNCTION ...']
     * MySQL definitions are stripped of DEFINER clauses.
     * SQLite returns [] (no stored routine support).
     */
    public function getRoutines(Connection $connection): array;

    /**
     * Return a map of enum type names to their CREATE TYPE definitions.
     *
     * Returns [typeName => 'CREATE TYPE ... AS ENUM (...)']
     * MySQL and SQLite return [] (enums are column-level, not standalone types).
     */
    public function getEnums(Connection $connection): array;

    /**
     * Return a map of standalone sequence names to their CREATE SEQUENCE
     * statements.
     *
     * Sequences owned by a serial or identity column are excluded — they belong
     * to that column and are created with it.
     *
     * Returns [sequenceName => 'CREATE SEQUENCE ...']
     * MySQL and SQLite return [] (no standalone sequence objects).
     */
    public function getSequences(Connection $connection): array;

    /**
     * Return a map of composite type names to their CREATE TYPE statements.
     *
     * Returns [typeName => 'CREATE TYPE ... AS (...)']
     * MySQL and SQLite return [] (no composite types).
     */
    public function getCompositeTypes(Connection $connection): array;

    /**
     * Return a map of domain names to their CREATE DOMAIN statements.
     *
     * Returns [domainName => 'CREATE DOMAIN ...']
     * MySQL and SQLite return [] (no domains).
     */
    public function getDomains(Connection $connection): array;

    /**
     * Return a map of materialised view names to their CREATE statements.
     *
     * Returns [viewName => 'CREATE MATERIALIZED VIEW ...']
     * MySQL and SQLite return [] (no materialised views).
     */
    public function getMaterializedViews(Connection $connection): array;

    /**
     * Return a map of row level security policies to their metadata.
     *
     * Keyed by "table.policy" because policy names are unique per table.
     *
     * Returns [key => ['name' => ..., 'table' => ..., 'definition' => 'CREATE POLICY ...']]
     * MySQL and SQLite return [] (no row level security).
     */
    public function getPolicies(Connection $connection): array;

    /**
     * Return per-table row level security flags.
     *
     * Returns [tableName => ['enabled' => bool, 'forced' => bool]]
     * MySQL and SQLite return [] (no row level security).
     */
    public function getRowSecurity(Connection $connection): array;

    /**
     * Return a hash-per-table map for schema pre-scan.
     *
     * Returns [tableName => hashString] covering columns, indexes, constraints,
     * engine, and collation. When source and target hashes match for a table,
     * the diff layer can skip all per-table queries for that table.
     *
     * When $tables is non-empty only those tables are included in the result.
     * Drivers that cannot implement an efficient batch hash (e.g. SQLite) should
     * return [] — the caller falls back to diffing all common tables normally.
     */
    public function getSchemaHashMap(Connection $connection, array $tables = []): array;
}
