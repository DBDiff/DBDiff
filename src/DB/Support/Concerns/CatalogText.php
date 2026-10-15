<?php namespace DBDiff\DB\Support\Concerns;

use DBDiff\DB\Support\PostgresSchemaHelper;
use DBDiff\DB\Support\SchemaScope;
use Illuminate\Database\Connection;

/** Quoting and scoping shared by the catalog readers. */
trait CatalogText {

    /** Whether this connection's scope is the one database-wide objects are read with. */
    protected static function holdsDatabaseObjects(Connection $connection): bool {
        return SchemaScope::of($connection) === 'public';
    }

    /** `$column` is this connection's schema, and the object is not an extension's. */
    protected static function ownedIn(Connection $connection, string $column, string $classid, string $oidExpr): string {
        return $column . ' = ' . SchemaScope::oid($connection)
            . ' AND ' . PostgresSchemaHelper::notExtensionMember($classid, $oidExpr);
    }

    protected static function ident(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    protected static function literal(string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
