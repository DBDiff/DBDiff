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
                         THEN pg_get_expr(c.relpartbound, c.oid) END AS bound,
                    -- UNLOGGED is not decoration: an unlogged table is not
                    -- crash-safe and is emptied on recovery.
                    c.relpersistence,
                    array_to_string(c.reloptions, ', ') AS reloptions
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
            'unlogged'     => ($row['relpersistence'] ?? 'p') === 'u',
            'reloptions'   => ($row['reloptions'] ?? '') !== '' ? $row['reloptions'] : null,
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

        if ($serialType !== null) {
            return "$quoted $serialType";
        }

        // COLLATE decides comparison and sort order. Dropping it produces a
        // column that applies cleanly and orders its rows differently, which is
        // the worst shape of bug this renderer can emit. Only an explicit,
        // non-default collation is written: spelling out the inherited one
        // would make every column read as changed.
        $collate = isset($row['explicit_collation']) && $row['explicit_collation'] !== null
            ? ' COLLATE "' . $row['explicit_collation'] . '"'
            : '';

        // Compression is per-column and only meaningful when set away from the
        // server default, which is what NULLIF on attcompression captures.
        $compression = self::compressionClause($row);

        return $quoted . ' ' . $type . $collate . $notNull . $compression . self::columnSuffix($row);
    }

    /** `COMPRESSION <method>`, or empty when the column uses the default. */
    private static function compressionClause(array $row): string {
        $method = $row['att_compression'] ?? null;
        if ($method === null || $method === '') {
            return '';
        }

        return match ($method) {
            'l' => ' COMPRESSION lz4',
            'p' => ' COMPRESSION pglz',
            default => '',
        };
    }

    /**
     * `ALTER TABLE ... SET STORAGE` for any column whose storage differs from
     * its type's default.
     *
     * Emitted separately because SET STORAGE inside CREATE TABLE only arrived
     * in PostgreSQL 16, and this has to keep working against 14 and 15.
     *
     * @return list<string>
     */
    public static function storageStatements(string $table, array $attrByCol): array {
        $out = [];
        foreach ($attrByCol as $column => $attr) {
            $actual  = $attr['att_storage'] ?? null;
            $default = $attr['type_storage'] ?? null;
            if ($actual === null || $default === null || $actual === $default) {
                continue;
            }
            $word = match ($actual) {
                'p' => 'PLAIN', 'e' => 'EXTERNAL', 'm' => 'MAIN', 'x' => 'EXTENDED',
                default => null,
            };
            if ($word !== null) {
                $out[] = "ALTER TABLE \"$table\" ALTER COLUMN \"$column\" SET STORAGE $word";
            }
        }
        return $out;
    }

    /**
     * What follows the type on a non-serial column: an identity clause, a
     * generated-column expression, or a plain DEFAULT.
     */
    private static function columnSuffix(array $row): string {
        if ($row['is_identity'] === 'YES') {
            return ' GENERATED ' . ($row['identity_generation'] ?? 'BY DEFAULT') . ' AS IDENTITY'
                . self::identityOptions($row);
        }

        if ($row['is_generated'] === 'ALWAYS') {
            return ' GENERATED ALWAYS AS (' . ($row['generation_expression'] ?? '') . ') STORED';
        }

        return $row['column_default'] !== null ? ' DEFAULT ' . $row['column_default'] : '';
    }

    /**
     * The sequence options attached to an identity column, as a parenthesised
     * clause — or an empty string when every option is the default.
     *
     * These are part of the column definition, not decoration. Recreating
     * `GENERATED ALWAYS AS IDENTITY (INCREMENT 10 START 100)` without its
     * options yields a column that increments by one from one, so the next
     * insert collides with rows the migration was meant to preserve.
     *
     * Only non-default options are emitted, because the defaults depend on the
     * column's type — MAXVALUE for an `integer` identity is not the MAXVALUE for
     * a `bigint` one — and spelling out an inherited default would turn a
     * type change into a false difference.
     */
    private static function identityOptions(array $row): string {
        $ascending = ($row['identity_increment'] ?? '1')[0] !== '-';
        $limits    = self::identityLimits($row['data_type'] ?? 'bigint', $ascending);

        $parts = [];
        foreach ([
            'INCREMENT BY' => ['identity_increment', '1'],
            'MINVALUE'     => ['identity_minimum',   $limits['min']],
            'MAXVALUE'     => ['identity_maximum',   $limits['max']],
            'START WITH'   => ['identity_start',     $ascending ? $limits['min'] : $limits['max']],
        ] as $keyword => [$column, $default]) {
            $value = $row[$column] ?? null;
            if ($value !== null && $value !== '' && (string) $value !== (string) $default) {
                $parts[] = "$keyword $value";
            }
        }

        if (($row['identity_cycle'] ?? 'NO') === 'YES') {
            $parts[] = 'CYCLE';
        }

        return $parts === [] ? '' : ' (' . implode(' ', $parts) . ')';
    }

    /** Default MINVALUE/MAXVALUE for an identity column of the given type. */
    private static function identityLimits(string $dataType, bool $ascending): array {
        // Written out rather than derived: bigint's floor cannot be computed in
        // native PHP integers without overflow, and bcmath is not a dependency.
        [$floor, $ceiling] = match (strtolower($dataType)) {
            'smallint' => ['-32768',                '32767'],
            'integer'  => ['-2147483648',           '2147483647'],
            default    => ['-9223372036854775808',  '9223372036854775807'],
        };

        return $ascending
            ? ['min' => '1',    'max' => $ceiling]
            : ['min' => $floor, 'max' => '-1'];
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
