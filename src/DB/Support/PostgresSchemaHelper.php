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

    /**
     * A SQL predicate excluding objects an extension owns.
     *
     * `CREATE EXTENSION pg_trgm` installs 31 functions into whatever schema it
     * is given, usually `public`. Read as ordinary user objects, they were
     * diffed individually, so an extension present on one side only produced a
     * `CREATE OR REPLACE FUNCTION` per member — including the C-language ones,
     * which a non-superuser cannot create: the apply failed with
     * `permission denied for language c` and, being one transaction, rolled the
     * whole migration back. Applied as a superuser it succeeded and left the
     * members owned by nobody, after which `CREATE EXTENSION` itself failed
     * with `function "set_limit" already exists` (issue #221).
     *
     * An extension's members are the extension's business: they arrive with
     * `CREATE EXTENSION` and are versioned with it. pg_dump skips them for the
     * same reason.
     *
     * Note what this does not do. DBDiff compares no extensions of its own, so
     * an extension present on one side only is now reported nowhere by DBDiff
     * itself — previously it was reported wrongly, as a pile of member objects
     * that could not be applied. Consumers that track extensions separately,
     * such as SupaForge's extensions check, still see the difference. Comparing
     * `pg_extension` here would be the complete answer and is a new object kind
     * rather than a fix to this one.
     *
     * `pg_depend.deptype = 'e'` is the catalog's own record of that ownership.
     *
     * @param string $classid  The catalog holding the object — pg_proc, pg_type,
     *                         pg_class — as it appears in pg_depend.
     * @param string $oidExpr  SQL expression for the object's oid in the caller's
     *                         query, e.g. `p.oid`.
     */
    public static function notExtensionMember(string $classid, string $oidExpr): string {
        return "NOT EXISTS (
                    SELECT 1 FROM pg_depend ext_dep
                     WHERE ext_dep.classid = '$classid'::regclass
                       AND ext_dep.objid = $oidExpr
                       AND ext_dep.deptype = 'e'
                )";
    }

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
     * The WITH (...) clause for a relation's reloptions, or nothing when there
     * are none.
     *
     * Tables carry fillfactor and autovacuum thresholds here, views carry
     * security_barrier, security_invoker and the WITH CHECK OPTION, and
     * materialised views carry both kinds. All three render the clause the same
     * way, so they render it from here.
     *
     * @param string|null $options Already comma-joined, as array_to_string gives.
     */
    public static function withOptions(?string $options): string {
        return ($options === null || $options === '')
            ? ''
            : ' WITH (' . $options . ')';
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

    /**
     * Which domains carry their own NOT NULL.
     *
     * A column of such a domain must not repeat NOT NULL: the constraint
     * belongs to the type, and emitting it on the column too makes the two
     * sides read as different when they are not.
     *
     * @return array<string, bool>
     */
    public static function domainNotNullMap(Connection $connection): array {
        $rows = $connection->select(
            "SELECT t.typname, t.typnotnull
             FROM pg_type t
             JOIN pg_namespace n ON t.typnamespace = n.oid
             WHERE n.nspname = 'public' AND t.typtype = 'd'"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['typname']] = (bool) $row['typnotnull'];
        }
        return $out;
    }

    /**
     * Per-column attributes that information_schema cannot express.
     *
     * Storage, compression, and whether a collation was set explicitly or
     * merely inherited, are all catalog-only. One query covers every table
     * given, so the caller's round-trip count stays independent of how many
     * tables are involved.
     *
     * @param  list<string> $tables
     * @return array<string, array<string, array<string, mixed>>> keyed table → column
     */
    public static function attributeMeta(Connection $connection, array $tables): array {
        if ($tables === []) {
            return [];
        }

        $rows = $connection->select(
            "SELECT c.relname AS table_name, a.attname AS column_name,
                    CASE WHEN co.collname IS NOT NULL AND co.collname <> 'default'
                         THEN co.collname END AS explicit_collation,
                    a.attstorage::text AS att_storage,
                    t.typstorage::text  AS type_storage,
                    NULLIF(a.attcompression::text, '') AS att_compression,
                    -- -1 means the column was declared without a type modifier.
                    -- information_schema cannot express that: a bare timestamptz
                    -- and a timestamptz(6) both report datetime_precision = 6,
                    -- so a precision nobody asked for was emitted (issue #215).
                    a.atttypmod,
                    -- What the column was actually declared as, rendered by the
                    -- server. The only reliable source for the types whose
                    -- modifier is more than a number, such as
                    -- `interval day to second(3)`.
                    format_type(a.atttypid, a.atttypmod) AS formatted_type,
                    -- Inherited from a parent: a partition's columns, or a
                    -- child's under INHERITS. Its type follows the parent's and
                    -- cannot be changed on its own (issue #232).
                    a.attinhcount > 0 AS inherited
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_type t ON t.oid = a.atttypid
             LEFT JOIN pg_collation co ON co.oid = a.attcollation
             WHERE c.relnamespace = 'public'::regnamespace
               AND c.relname IN (" . QueryHelper::placeholders($tables) . ")
               AND a.attnum > 0 AND NOT a.attisdropped",
            $tables
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['table_name']][$row['column_name']] = $row;
        }
        return $out;
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
        foreach (self::columnStorage($attrByCol) as $column => $storage) {
            if ($storage['actual'] !== $storage['default']) {
                $out[] = "ALTER TABLE \"$table\" ALTER COLUMN \"$column\" SET STORAGE {$storage['actual']}";
            }
        }
        return $out;
    }

    /**
     * Each column's storage strategy, and its type's default, as the keywords
     * `SET STORAGE` takes — what the comparison reads for a table that
     * exists on both sides (issue #225).
     *
     * @return array<string, array{actual: string, default: string}>
     */
    public static function columnStorage(array $attrByCol): array {
        $word = fn(?string $code) => match ($code) {
            'p' => 'PLAIN', 'e' => 'EXTERNAL', 'm' => 'MAIN', 'x' => 'EXTENDED', default => null,
        };
        $out = [];
        foreach ($attrByCol as $column => $attr) {
            $actual  = $word($attr['att_storage'] ?? null);
            $default = $word($attr['type_storage'] ?? null);
            if ($actual !== null && $default !== null) {
                $out[$column] = ['actual' => $actual, 'default' => $default];
            }
        }
        // By name: the bulk and per-table fetches read columns in different
        // orders, and the schema has to be the same either way.
        ksort($out);
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
     * Durability and storage parameters of public tables, by name.
     *
     * Both were already read for rendering a *new* table and never compared
     * for one that exists on both sides, so switching a table between LOGGED
     * and UNLOGGED, or changing its fillfactor, was reported as "Databases are
     * identical" (issue #229). UNLOGGED is not decoration: an unlogged table is
     * not crash-safe and is emptied on recovery.
     *
     * @param string[] $tables
     * @return array<string, array{unlogged: bool, reloptions: ?string}>
     */
    public static function relationMeta(Connection $connection, array $tables): array {
        $ph = QueryHelper::placeholders($tables);
        $rows = $connection->select(
            "SELECT c.relname AS table_name,
                    c.relpersistence,
                    " . self::canonicalReloptions('c.reloptions', ', ') . " AS reloptions
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public' AND c.relname IN ($ph)",
            $tables
        );
        $meta = [];
        foreach ($rows as $row) {
            $meta[$row['table_name']] = [
                'unlogged'   => ($row['relpersistence'] ?? 'p') === 'u',
                'reloptions' => ($row['reloptions'] ?? '') !== '' ? $row['reloptions'] : null,
            ];
        }
        return $meta;
    }

    /**
     * The sequence a public table's serial column defaults to — its name as
     * `pg_get_serial_sequence` gives it (`public.t_id_seq`) and its type — or
     * null when the column has none.
     *
     * @return array{name: string, type: string}|null
     */
    public static function serialSequence(Connection $connection, string $table, string $column): ?array {
        $rows = $connection->select(
            "SELECT s.seq AS name, q.seqtypid::regtype::text AS type
               FROM (SELECT pg_get_serial_sequence(?, ?) AS seq) s
               JOIN pg_sequence q ON q.seqrelid = s.seq::regclass",
            ['public.' . '"' . str_replace('"', '""', $table) . '"', $column]
        );
        return isset($rows[0]['name']) ? ['name' => $rows[0]['name'], 'type' => $rows[0]['type']] : null;
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

    /**
     * A table constraint rendered as `CONSTRAINT "name" ...`.
     *
     * Moved off PostgresAdapter, which had grown past the 20-method ceiling the
     * project enforces. It reads nothing and holds no state — it turns one
     * constraint row into one string — so it belongs with the other renderers
     * here rather than on the adapter.
     *
     * Returns null for a constraint type rendered elsewhere: CHECK, EXCLUDE and
     * NOT NULL come from pg_get_constraintdef.
     */
    public static function constraintDefinition(string $name, array $c): ?string {
        $defer = '';
        if (($c['is_deferrable'] ?? 'NO') === 'YES') {
            $defer = ($c['initially_deferred'] ?? 'NO') === 'YES'
                ? ' DEFERRABLE INITIALLY DEFERRED'
                : ' DEFERRABLE INITIALLY IMMEDIATE';
        }

        $notValid = '';
        if (isset($c['convalidated']) && !$c['convalidated']) {
            $notValid = ' NOT VALID';
        }

        $cols = implode('", "', $c['columns']);
        $type = $c['constraint_type'];

        if ($type === 'FOREIGN KEY') {
            $matchMap  = ['FULL' => ' MATCH FULL', 'PARTIAL' => ' MATCH PARTIAL'];
            $match     = $matchMap[$c['match_option'] ?? 'NONE'] ?? '';
            return "CONSTRAINT \"$name\" FOREIGN KEY (\"$cols\")" .
                " REFERENCES \"{$c['foreign_table']}\" (\"{$c['foreign_column']}\")" .
                $match .
                " ON UPDATE {$c['update_rule']} ON DELETE {$c['delete_rule']}" .
                $defer . $notValid;
        }

        if ($type === 'UNIQUE' || $type === 'PRIMARY KEY') {
            return "CONSTRAINT \"$name\" {$type} (\"$cols\")" . $defer;
        }

        return null;
    }

    /**
     * A policy row rendered as `CREATE POLICY ...` on `$relation`.
     *
     * `$relation` arrives quoted, and qualified where it needs to be: the
     * policies the diff compares are all in `public` and rendered bare, while
     * one recreated around a column type change can sit on a table in any
     * schema. The row carries name, permissive, command, roles, using_expr and
     * check_expr.
     */
    public static function policyDefinition(array $row, string $relation): string {
        $sql = 'CREATE POLICY "' . $row['name'] . '" ON ' . $relation;
        if (!$row['permissive']) {
            $sql .= ' AS RESTRICTIVE';
        }
        $sql .= ' FOR ' . $row['command'];
        // polroles of {0} means PUBLIC, which no pg_roles row matches; the
        // clause is then omitted and PostgreSQL applies its PUBLIC default.
        if (!empty($row['roles'])) {
            $sql .= ' TO ' . $row['roles'];
        }
        if ($row['using_expr'] !== null && $row['using_expr'] !== '') {
            $sql .= ' USING (' . $row['using_expr'] . ')';
        }
        if ($row['check_expr'] !== null && $row['check_expr'] !== '') {
            $sql .= ' WITH CHECK (' . $row['check_expr'] . ')';
        }
        return $sql;
    }

    /**
     * `"name"` in `public`, `"schema"."name"` anywhere else.
     *
     * Bare for `public` because that is how every other statement DBDiff emits
     * names its objects, so a recreated view reads like the rest of the file.
     */
    public static function qualifiedName(string $schema, string $name): string {
        $quote = fn(string $id) => '"' . str_replace('"', '""', $id) . '"';
        return $schema === 'public' ? $quote($name) : $quote($schema) . '.' . $quote($name);
    }

    /**
     * SQL rendering a `reloptions` array in a canonical form, joined by `$separator`.
     *
     * The catalog keeps options in the order they were set — `SET (a=1)` then
     * `SET (b=2)` stores `{a=1,b=2}`, the other way round `{b=2,a=1}` — and
     * keeps a boolean's value as it was spelled. So two tables with the same
     * storage parameters compared unequal, with nothing to migrate, and the
     * pre-scan never skipped them. Sorted, with the boolean spellings
     * PostgreSQL accepts folded to `true` and `false`.
     */
    public static function canonicalReloptions(string $column, string $separator): string {
        return "array_to_string(ARRAY(
                    SELECT regexp_replace(regexp_replace(o,
                               '=(on|true|yes)$', '=true', 'i'),
                               '=(off|false|no)$', '=false', 'i')
                    FROM unnest($column) AS o
                    ORDER BY 1), '$separator')";
    }
}
