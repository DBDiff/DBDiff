<?php namespace DBDiff\DB\Adapters;

use Illuminate\Database\Connection;

/**
 * An adapter that can say what reads a given column.
 *
 * Optional, and implemented by PostgreSQL alone. `ALTER TABLE ... ALTER COLUMN
 * ... TYPE` is refused there while any view, policy or trigger condition reads
 * the column, so a type change has to drop those and put them back (issue
 * #226). MySQL and SQLite have no such restriction and do not implement this,
 * which is why it is a capability rather than part of DBAdapterInterface.
 */
interface ColumnDependencyAdapterInterface {

    /**
     * Everything reading `$table`.`$column` — see PostgresColumnDependants.
     *
     * @return array{views: array<int, array<string, mixed>>, policies: array<int, array<string, string>>, triggers: array<int, array<string, string>>, defaultGrantees: string[]}
     */
    public function getColumnDependants(Connection $connection, string $table, string $column): array;
}
