<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;

/**
 * An adapter that can say which views read a given column.
 *
 * Optional, and implemented by PostgreSQL alone. `ALTER TABLE ... ALTER COLUMN
 * ... TYPE` is refused there while any view reads the column, so a type change
 * has to drop those views and put them back (issue #226). MySQL and SQLite have
 * no such restriction and do not implement this, which is why it is a capability
 * rather than part of DBAdapterInterface.
 */
interface ColumnDependencyAdapterInterface {

    /**
     * Views and materialised views reading `$table`.`$column`, with the depth
     * at which each sits so they can be dropped and recreated in order.
     *
     * @return array<int, array{name: string, kind: string, depth: int, definition: string, indexes: string[]}>
     */
    public function getColumnDependentViews(Connection $connection, string $table, string $column): array;
}
