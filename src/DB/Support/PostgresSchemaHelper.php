<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;


/**
 * PostgreSQL schema-reconstruction helpers.
 *
 * These live outside PostgresAdapter for the same reason as QueryHelper: the
 * adapter sits on the 20-method-per-class ceiling, so behaviour has to be
 * extracted rather than added as another private method.
 *
 * Everything here answers one question — "what does this catalog row actually
 * mean when written back as DDL?" — which information_schema alone cannot say.
 */
class PostgresSchemaHelper {

    /** Integer types that have a serial spelling, keyed by information_schema name. */
    private const SERIAL_TYPES = [
        'smallint' => 'smallserial',
        'integer'  => 'serial',
        'bigint'   => 'bigserial',
    ];

    /**
     * Partitioning facts for a table: whether it is a partitioned parent (and on
     * what key), and whether it is itself a partition (of what, for which
     * bound). All null/false for an ordinary table.
     *
     * Needed because information_schema describes a partition as an ordinary
     * table. Rebuilding one from that view produces a detached copy: rows still
     * insert, so nothing errors, while the partitioning is silently gone.
     */
    public static function partitionMeta(Connection $connection, string $table): array {
        $rows = $connection->select(
            "SELECT c.relispartition,
                    CASE WHEN c.relkind = 'p'
                         THEN pg_get_partkeydef(c.oid) END AS partition_by,
                    parent.relname AS parent,
                    CASE WHEN c.relispartition
                         THEN pg_get_expr(c.relpartbound, c.oid) END AS bound
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
               LEFT JOIN pg_inherits i ON i.inhrelid = c.oid
               LEFT JOIN pg_class parent ON parent.oid = i.inhparent
              WHERE n.nspname = 'public' AND c.relname = ?",
            [$table]
        );
        $row = $rows[0] ?? null;

        return [
            'partition_by' => $row['partition_by'] ?? null,
            'is_partition' => (bool) ($row['relispartition'] ?? false),
            'parent'       => $row['parent'] ?? null,
            'bound'        => $row['bound'] ?? null,
        ];
    }

    /**
     * Quote a user-defined type, qualifying it with its schema when it does not
     * live in public.
     *
     * Supabase keeps extension types in "extensions", which is not usually on
     * the search_path of the session running the migration, so an unqualified
     * name would not resolve there.
     */
    public static function qualifiedUdt(array $col): string {
        $name   = '"' . $col['udt_name'] . '"';
        $schema = $col['udt_schema'] ?? null;

        return ($schema && !in_array($schema, ['public', 'pg_catalog'], true))
            ? '"' . $schema . '".' . $name
            : $name;
    }

    /**
     * Render one column's DDL, given its already-resolved type and NOT NULL
     * suffix.
     *
     * The four shapes a column can take (identity, generated, serial, plain)
     * are decided here rather than inline in the adapter's assembly loop, which
     * was already at the cognitive-complexity ceiling before serial was added.
     */
    public static function columnDefinition(array $row, string $type, string $notNull): string {
        $quoted = '"' . $row['column_name'] . '"';

        // serial carries its own type and implies NOT NULL, so it replaces both
        // the resolved type and the suffix rather than decorating them.
        $serialType = self::serialTypeFor($row);

        return $serialType !== null
            ? "$quoted $serialType"
            : $quoted . ' ' . $type . $notNull . self::columnSuffix($row);
    }

    /**
     * What follows the type on a non-serial column: an identity clause, a
     * generated-column expression, or a plain DEFAULT.
     */
    private static function columnSuffix(array $row): string {
        if ($row['is_identity'] === 'YES') {
            return ' GENERATED ' . ($row['identity_generation'] ?? 'BY DEFAULT') . ' AS IDENTITY';
        }

        if ($row['is_generated'] === 'ALWAYS') {
            return ' GENERATED ALWAYS AS (' . ($row['generation_expression'] ?? '') . ') STORED';
        }

        return $row['column_default'] !== null ? ' DEFAULT ' . $row['column_default'] : '';
    }

    /**
     * Return the serial spelling for a column, or null to keep its explicit
     * DEFAULT.
     *
     * A serial column's default is nextval('<table>_<col>_seq'), but that
     * sequence belongs to the table and is not emitted anywhere in a generated
     * migration, so replaying the CREATE TABLE failed with:
     *
     *   ERROR: relation "<table>_<col>_seq" does not exist
     *
     * Writing the column back as serial re-creates the owned sequence
     * implicitly and restores an identical default.
     *
     * Deliberately narrow. `owned_sequence` is null when the column merely
     * references a sequence rather than owning it, and serial implies NOT NULL,
     * so a nullable column keeps its default too — serial would change the
     * semantics rather than preserve them.
     */
    public static function serialTypeFor(array $row): ?string {
        $isOwnedSerial = !empty($row['owned_sequence'])
            && $row['is_nullable'] === 'NO'
            && preg_match('/^nextval\(/i', (string) $row['column_default']) === 1;

        return $isOwnedSerial
            ? (self::SERIAL_TYPES[$row['data_type']] ?? null)
            : null;
    }
}
