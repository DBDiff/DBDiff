<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;


/**
 * The PostgreSQL object kinds no other driver has.
 *
 * Composite types, domains, materialised views, row level security and
 * standalone sequences exist in PostgreSQL and in none of the other drivers
 * DBDiff supports, so they are read here rather than through DBAdapterInterface.
 * Declaring them on that interface obliged MySQL and SQLite to carry six
 * identical methods returning an empty array, which said nothing and had to be
 * kept in step by hand.
 *
 * DBSchema reaches for this only when the driver is pgsql, the same way it asks
 * about collation and charset only for MySQL.
 *
 * Every method takes the connection it should read, so one instance is never
 * tied to a side of the diff.
 */
final class PostgresObjectKinds {

    /**
     * Standalone sequences, keyed by name.
     *
     * A sequence owned by a serial or identity column is excluded: PostgreSQL
     * creates and drops it with that column, so emitting a CREATE SEQUENCE for
     * it makes the migration fail with "relation already exists" against any
     * table declaring one.
     *
     * Both dependency kinds have to be excluded, and they differ: a serial
     * column's sequence depends on it with deptype 'a' (auto), an identity
     * column's with deptype 'i' (internal). Filtering on 'a' alone left every
     * identity column's sequence looking standalone.
     *
     * Every option is rendered, not only those differing from the default,
     * because the MINVALUE and MAXVALUE defaults follow the sequence's type: a
     * bigint sequence recreated as an integer one is a different sequence.
     */
    public static function sequences(Connection $connection): array {
        $result = $connection->select(
            "SELECT c.relname AS name,
                    format_type(sq.seqtypid, NULL) AS data_type,
                    sq.seqstart, sq.seqincrement, sq.seqmin, sq.seqmax,
                    sq.seqcache, sq.seqcycle
             FROM pg_sequence sq
             JOIN pg_class c ON c.oid = sq.seqrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public'
               AND NOT EXISTS (
                   SELECT 1 FROM pg_depend dep
                   WHERE dep.objid = c.oid
                     AND dep.classid = 'pg_class'::regclass
                     AND dep.refclassid = 'pg_class'::regclass
                     AND dep.refobjsubid > 0
                     AND dep.deptype IN ('a', 'i')
               )
             ORDER BY c.relname"
        );
        $sequences = [];
        foreach ($result as $row) {
            $sequences[$row['name']] = 'CREATE SEQUENCE "' . $row['name'] . '"'
                . ' AS ' . $row['data_type']
                . ' INCREMENT BY ' . $row['seqincrement']
                . ' MINVALUE ' . $row['seqmin']
                . ' MAXVALUE ' . $row['seqmax']
                . ' START WITH ' . $row['seqstart']
                . ' CACHE ' . $row['seqcache']
                . ($row['seqcycle'] ? ' CYCLE' : ' NO CYCLE');
        }
        return $sequences;
    }

    /**
     * Composite types, keyed by name.
     *
     * Every table, view and materialised view also owns a composite type
     * describing its row shape. Those are excluded by requiring the associated
     * pg_class entry to be a standalone composite (relkind 'c'); without that
     * test each table would produce a spurious CREATE TYPE.
     */
    public static function compositeTypes(Connection $connection): array {
        $result = $connection->select(
            "SELECT t.typname AS name,
                    (SELECT string_agg(
                                format('%I %s', a.attname, format_type(a.atttypid, a.atttypmod)),
                                ', ' ORDER BY a.attnum)
                       FROM pg_attribute a
                      WHERE a.attrelid = t.typrelid
                        AND a.attnum > 0 AND NOT a.attisdropped) AS attributes
             FROM pg_type t
             JOIN pg_namespace n ON n.oid = t.typnamespace
             WHERE n.nspname = 'public'
               AND t.typtype = 'c'
               AND NOT EXISTS (
                   SELECT 1 FROM pg_class c
                   WHERE c.oid = t.typrelid AND c.relkind <> 'c'
               )
             ORDER BY t.typname"
        );
        $types = [];
        foreach ($result as $row) {
            $types[$row['name']] = 'CREATE TYPE "' . $row['name'] . '" AS ('
                . ($row['attributes'] ?? '') . ')';
        }
        return $types;
    }

    /**
     * Domains, keyed by name.
     *
     * Constraints are emitted with their catalogue names. PostgreSQL would
     * otherwise derive them, and a domain carrying several CHECKs would have
     * them renamed on every recreation.
     *
     * Only CHECK constraints (contype 'c') are read. PostgreSQL 17 began
     * recording a domain's NOT NULL as a named constraint of its own as well,
     * so taking every row here emitted the nullability twice — which PG 17
     * rejects as "constraint already exists" and PG 18 as "redundant NOT NULL
     * constraint definition". typnotnull already carries it.
     */
    public static function domains(Connection $connection): array {
        $result = $connection->select(
            "SELECT t.typname AS name,
                    format_type(t.typbasetype, t.typtypmod) AS base_type,
                    t.typnotnull AS not_null,
                    pg_get_expr(t.typdefaultbin, 0) AS default_expr,
                    co.collname AS collation,
                    (SELECT string_agg(
                                format('CONSTRAINT %I %s', con.conname, pg_get_constraintdef(con.oid)),
                                ' ' ORDER BY con.conname)
                       FROM pg_constraint con
                      WHERE con.contypid = t.oid
                        AND con.contype = 'c') AS constraints
             FROM pg_type t
             JOIN pg_namespace n ON n.oid = t.typnamespace
             LEFT JOIN pg_collation co ON co.oid = t.typcollation
                                     AND co.collname <> 'default'
             WHERE n.nspname = 'public' AND t.typtype = 'd'
             ORDER BY t.typname"
        );
        $domains = [];
        foreach ($result as $row) {
            $sql = 'CREATE DOMAIN "' . $row['name'] . '" AS ' . $row['base_type'];
            if (!empty($row['collation'])) {
                $sql .= ' COLLATE "' . $row['collation'] . '"';
            }
            if ($row['default_expr'] !== null && $row['default_expr'] !== '') {
                $sql .= ' DEFAULT ' . $row['default_expr'];
            }
            if ($row['not_null']) {
                $sql .= ' NOT NULL';
            }
            if (!empty($row['constraints'])) {
                $sql .= ' ' . $row['constraints'];
            }
            $domains[$row['name']] = $sql;
        }
        return $domains;
    }

    /**
     * Materialised views, keyed by name.
     *
     * PostgresAdapter::getViews() reads pg_views, which holds only ordinary views, so these are
     * a separate kind rather than a filter on that result.
     *
     * A matview's indexes are carried in its definition rather than through the
     * table index path: that path takes its relations from getTables(), which
     * reads pg_tables and so never lists a matview — leaving a unique index on
     * one invisible. Keeping them here also means a changed index shows up as a
     * changed definition, and DROP MATERIALIZED VIEW takes them with it.
     */
    public static function materializedViews(Connection $connection): array {
        $result = $connection->select(
            "SELECT c.relname AS name,
                    pg_get_viewdef(c.oid, true) AS definition,
                    array_to_string(c.reloptions, ', ') AS options,
                    c.relispopulated AS populated
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind = 'm'
             ORDER BY c.relname"
        );
        $indexes = self::matviewIndexes($connection);
        $matviews = [];
        foreach ($result as $row) {
            $sql = 'CREATE MATERIALIZED VIEW "' . $row['name'] . '"'
                . PostgresSchemaHelper::withOptions($row['options'])
                . ' AS ' . rtrim(trim($row['definition']), ';');
            // An unpopulated matview cannot be reproduced by a plain CREATE:
            // that would run the query and populate it.
            if (!$row['populated']) {
                $sql .= ' WITH NO DATA';
            }
            foreach ($indexes[$row['name']] ?? [] as $indexDef) {
                $sql .= ";\n" . $indexDef;
            }
            $matviews[$row['name']] = $sql;
        }
        return $matviews;
    }

    /**
     * Index definitions per materialised view, keyed by the view's name.
     *
     * @return array<string, string[]>
     */
    private static function matviewIndexes(Connection $connection): array {
        $rows = $connection->select(
            "SELECT c.relname AS name, i.indexdef
             FROM pg_indexes i
             JOIN pg_class c ON c.relname = i.tablename
             JOIN pg_namespace n ON n.oid = c.relnamespace
                               AND n.nspname = i.schemaname
             WHERE i.schemaname = 'public' AND c.relkind = 'm'
             ORDER BY c.relname, i.indexname"
        );
        $byView = [];
        foreach ($rows as $row) {
            $byView[$row['name']][] = rtrim(trim($row['indexdef']), ';');
        }
        return $byView;
    }

    /**
     * Row level security policies, keyed by "table.policy".
     *
     * Policy names are unique per table rather than per schema, so two tables
     * may legitimately share one — keying on the bare name would drop all but
     * the last, the same way trigger names did in issue #187.
     */
    public static function policies(Connection $connection): array {
        $result = $connection->select(
            "SELECT c.relname AS table_name, p.polname AS name,
                    p.polpermissive AS permissive,
                    CASE p.polcmd WHEN 'r' THEN 'SELECT' WHEN 'a' THEN 'INSERT'
                                  WHEN 'w' THEN 'UPDATE' WHEN 'd' THEN 'DELETE'
                                  ELSE 'ALL' END AS command,
                    (SELECT string_agg(quote_ident(r.rolname), ', ' ORDER BY r.rolname)
                       FROM unnest(p.polroles) AS ro
                       JOIN pg_roles r ON r.oid = ro) AS roles,
                    pg_get_expr(p.polqual, p.polrelid) AS using_expr,
                    pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
             FROM pg_policy p
             JOIN pg_class c ON c.oid = p.polrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public'
             ORDER BY c.relname, p.polname"
        );
        $policies = [];
        foreach ($result as $row) {
            $sql = 'CREATE POLICY "' . $row['name'] . '" ON "' . $row['table_name'] . '"';
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
            $policies[$row['table_name'] . '.' . $row['name']] = [
                'name'       => $row['name'],
                'table'      => $row['table_name'],
                'definition' => $sql,
            ];
        }
        return $policies;
    }

    /**
     * Per-table row level security flags, keyed by table name.
     *
     * Enabling RLS is a property of the table, not of any policy: a table with
     * policies but RLS left off does not enforce them, and the two are set by
     * separate statements.
     *
     * Returns [table => ['enabled' => bool, 'forced' => bool]]
     */
    public static function rowSecurity(Connection $connection): array {
        $result = $connection->select(
            "SELECT c.relname AS table_name,
                    c.relrowsecurity AS enabled,
                    c.relforcerowsecurity AS forced
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
             ORDER BY c.relname"
        );
        $settings = [];
        foreach ($result as $row) {
            $settings[$row['table_name']] = [
                'enabled' => (bool) $row['enabled'],
                'forced'  => (bool) $row['forced'],
            ];
        }
        return $settings;
    }
}
