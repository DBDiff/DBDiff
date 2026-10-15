<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Tables pg_partman makes and drops itself.
 *
 * A partition set's children — premade ahead of time, retired by retention,
 * and its default partition — are pg_partman's to manage, but they are
 * ordinary tables with no tie to the extension in pg_depend, so they were
 * compared like any other: two databases whose sets were made on different
 * days differed by a handful of partitions, and the migration dropped the
 * extra ones with their rows. A table is pg_partman's when its parent, at
 * any depth, is registered in its part_config; its template tables too.
 */
final class PostgresPartman {

    /** @var array<int, array<string, true>> per connection */
    private static array $managed = [];

    /**
     * The scope's tables pg_partman manages, by name; none without it.
     *
     * @return array<string, true>
     */
    public static function managedTables(Connection $connection): array {
        $key = spl_object_id($connection) . '|' . SchemaScope::of($connection);
        if (isset(self::$managed[$key])) {
            return self::$managed[$key];
        }
        $schema = $connection->selectOne(
            "SELECT quote_ident(n.nspname) AS schema FROM pg_extension e
               JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_partman'"
        );
        if ($schema === null) {
            return self::$managed[$key] = [];
        }
        $config = ((array) $schema)['schema'] . '.part_config';
        $rows = $connection->select(
            "WITH RECURSIVE children AS (
                 SELECT i.inhrelid AS oid FROM $config pc
                   JOIN pg_inherits i ON i.inhparent = to_regclass(pc.parent_table)
                 UNION
                 SELECT i.inhrelid FROM pg_inherits i JOIN children c ON i.inhparent = c.oid
             )
             SELECT c.relname FROM pg_class c
              WHERE c.relnamespace = " . SchemaScope::oid($connection) . "
                AND (c.oid IN (SELECT oid FROM children)
                     OR c.oid IN (SELECT to_regclass(pc.template_table) FROM $config pc))"
        );
        $out = [];
        foreach ($rows as $row) {
            $out[((array) $row)['relname']] = true;
        }
        return self::$managed[$key] = $out;
    }
}
