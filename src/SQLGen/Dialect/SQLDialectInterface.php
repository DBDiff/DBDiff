<?php namespace DBDiff\SQLGen\Dialect;


interface SQLDialectInterface {

    /** Quote a single identifier (table, column, index name). */
    public function quote(string $name): string;

    /** The PDO driver string: mysql | pgsql | sqlite */
    public function getDriver(): string;

    /**
     * Whether this dialect is MySQL-only.
     * When true, MySQL-specific diff objects (Engine, Charset, Collation)
     * will be handled; when false those DiffToSQL classes return empty strings.
     */
    public function isMySQLOnly(): bool;

    /**
     * DROP INDEX statement.
     * MySQL: ALTER TABLE `t` DROP INDEX `key`
     * Postgres/SQLite: DROP INDEX "key"
     */
    public function dropIndex(string $table, string $key): string;

    /**
     * DROP TRIGGER statement.
     * MySQL:          DROP TRIGGER IF EXISTS `trigger`
     * Postgres:       DROP TRIGGER IF EXISTS "trigger" ON "table"
     * SQLite:         DROP TRIGGER IF EXISTS "trigger"
     */
    public function dropTrigger(string $trigger, string $table): string;

    /**
     * ADD COLUMN statement.
     * $colDef is the full column DDL fragment (already quoted), e.g.
     *   MySQL:         `col` varchar(255) NOT NULL
     *   Postgres/SQLite: "col" varchar(255) NOT NULL
     *
     * MySQL omits the COLUMN keyword for backwards-compat with existing baselines.
     * Postgres and SQLite always include COLUMN.
     */
    public function addColumn(string $table, string $colDef): string;

    /**
     * DROP COLUMN statement.
     * $col is the bare (unquoted) column name.
     *
     * MySQL omits the COLUMN keyword for backwards-compat with existing baselines.
     * Postgres and SQLite always include COLUMN.
     */
    public function dropColumn(string $table, string $col): string;

    /**
     * ALTER COLUMN / CHANGE COLUMN.
     * $col is the bare column name.
     * $newDef is the full column DDL fragment produced by the adapter,
     *   e.g.  `col` varchar(255) NOT NULL   (MySQL)
     *   or    "col" varchar(255) NOT NULL   (Postgres/SQLite)
     *
     * Returns the complete statement(s) including trailing semicolons.
     */
    public function changeColumn(string $table, string $col, string $newDef): string;
}
