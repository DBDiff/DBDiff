<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Everything in one database that uses an enum type, sorted into what an enum
 * swap can carry across and what it cannot (issue #237).
 *
 * Removing or reordering a label means a new type: the columns are moved to
 * it, the old type is dropped and the new one takes its name. PostgreSQL
 * records every user of the type in pg_depend, and DROP TYPE succeeds only if
 * each of them has been dealt with, so the same records drive the plan:
 *
 *   - `columns`: table columns of the type or an array of it, retyped with
 *     `USING col::text::new`. A default goes first — it cannot be cast — and
 *     comes back after.
 *   - `constraints` and `indexes` whose definitions are parsed again when a
 *     column they use is retyped: those with a literal of the type
 *     (`CHECK (s <> 'c')`, `WHERE s = 'b'`), which would compare the new type
 *     to the old, and foreign keys between two tables' columns of the type,
 *     which cannot be retyped one table at a time. They are dropped and
 *     recreated from their definitions.
 *   - `readers`: the views, policies, triggers and generated columns found to
 *     use the type. The column dependant lookup (PostgresColumnDependants)
 *     finds and carries those that read a retyped column; any it does not
 *     find blocks the swap.
 *   - `others`: what the swap cannot carry — a routine taking or returning
 *     the type, a domain, composite or range over it, a column outside the
 *     public schema, a partition key. Any of these stops the swap, and the migration falls
 *     back to replacing the type, which PostgreSQL refuses with a message
 *     naming them.
 */
final class PostgresEnumUsage {

    /** The enum type itself, in the connection's schema, as `ty`. */
    private static function typeCte(Connection $connection): string {
        return "WITH ty AS (
        SELECT t.oid, t.typarray
          FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
         WHERE n.nspname = " . SchemaScope::literal($connection) . " AND t.typname = ?
    )";
    }

    /**
     * @return array{
     *   columns: list<array{table: string, column: string, array: bool, inherited: bool, partitionKey: bool, default: ?string}>,
     *   constraints: list<array{table: string, name: string, definition: string, comment: ?string}>,
     *   indexes: list<array{table: string, name: string, definition: string, comment: ?string}>,
     *   readers: list<array{kind: string, key: string, description: string}>,
     *   others: string[],
     *   comment: ?string,
     *   grants: list<array{grantee: string, privilege: string, grantable: bool}>,
     *   publicRevoked: bool
     * }
     */
    public static function find(Connection $connection, string $type): array {
        $usage = [
            'columns'     => self::columns($connection, $type),
            'constraints' => self::constraints($connection, $type),
            'indexes'     => self::indexes($connection, $type),
            'readers'     => [],
            'others'      => [],
        ] + self::typeMetadata($connection, $type);

        $retyped = [];
        foreach ($usage['columns'] as $column) {
            $retyped[$column['table'] . '.' . $column['column']] = true;
            if ($column['partitionKey']) {
                $usage['others'][] = "partition key of table {$column['table']}";
            }
        }
        foreach (self::users($connection, $type) as $user) {
            if ($user['kind'] === 'other'
                || ($user['kind'] === 'default' && !isset($retyped[$user['key']]))) {
                $usage['others'][] = $user['description'];
            } elseif (in_array($user['kind'], ['view', 'policy', 'trigger', 'generated'], true)) {
                $usage['readers'][] = $user;
            }
        }
        $usage['others'] = array_values(array_unique($usage['others']));
        return $usage;
    }

    /**
     * The readers of the type the dependant lookup did not find — a view
     * selecting a literal of the type without reading a column of it, say.
     *
     * @return string[]
     */
    public static function uncarried(array $usage, array $dependants): array {
        $found = [];
        foreach ($dependants['views'] ?? [] as $view) {
            $found['view:' . $view['schema'] . '.' . $view['name']] = true;
        }
        foreach (['policies' => 'policy', 'triggers' => 'trigger', 'generated' => 'generated'] as $kind => $as) {
            foreach ($dependants[$kind] ?? [] as $object) {
                $found[$as . ':' . $object['schema'] . '.' . $object['table'] . '.' . $object['name']] = true;
            }
        }
        $missing = [];
        foreach ($usage['readers'] as $reader) {
            if (!isset($found[$reader['kind'] . ':' . $reader['key']])) {
                // By name: a view's columns and its rule are one view.
                $missing[] = ($reader['kind'] === 'generated' ? 'generated column' : $reader['kind'])
                    . ' ' . $reader['key'];
            }
        }
        return array_values(array_unique($missing));
    }

    /**
     * Plain columns of public tables. A column a child inherits is retyped by
     * its parent's statement, and only listed for its own default, which the
     * parent's DROP DEFAULT also clears; parents come first.
     */
    private static function columns(Connection $connection, string $type): array {
        $rows = $connection->select(self::typeCte($connection) . "
            SELECT c.relname AS table_name, a.attname AS column_name,
                   a.atttypid = ty.typarray AS is_array,
                   a.attinhcount > 0 AS inherited,
                   pk.partrelid IS NOT NULL AS partition_key,
                   pg_get_expr(ad.adbin, ad.adrelid) AS default_expr
              FROM ty
              JOIN pg_attribute a ON a.atttypid IN (ty.oid, ty.typarray)
              JOIN pg_class c ON c.oid = a.attrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
              LEFT JOIN pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
              LEFT JOIN pg_partitioned_table pk ON pk.partrelid = c.oid
                                               AND a.attnum = ANY (pk.partattrs::int2[])
             WHERE n.nspname = " . SchemaScope::literal($connection) . " AND c.relkind IN ('r', 'p')
               AND NOT a.attisdropped AND a.attnum > 0
               AND a.attgenerated = ''
             ORDER BY a.attinhcount > 0, c.relname, a.attnum",
            [$type]
        );
        return array_map(fn($r) => [
            'table'        => $r['table_name'],
            'column'       => $r['column_name'],
            'array'        => (bool) $r['is_array'],
            'inherited'    => (bool) $r['inherited'],
            'partitionKey' => (bool) $r['partition_key'],
            'default'      => $r['default_expr'],
        ], $rows);
    }

    /**
     * Table constraints a retype parses again: one with a literal of the type,
     * and a foreign key between columns of the type in two tables. Not those
     * of a generated column, which go and come back with it, nor those a
     * child inherits from its parent's.
     */
    private static function constraints(Connection $connection, string $type): array {
        $rows = $connection->select(self::typeCte($connection) . "
            SELECT c.relname AS table_name, k.conname AS name,
                   pg_get_constraintdef(k.oid) AS definition,
                   quote_literal(obj_description(k.oid, 'pg_constraint')) AS comment
              FROM ty, pg_constraint k
              JOIN pg_class c ON c.oid = k.conrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = " . SchemaScope::literal($connection) . " AND k.conislocal AND k.conparentid = 0
               AND (EXISTS (SELECT 1 FROM pg_depend d
                             WHERE d.classid = 'pg_constraint'::regclass AND d.objid = k.oid
                               AND d.refobjid IN (ty.oid, ty.typarray))
                    OR (k.contype = 'f' AND k.conrelid <> k.confrelid
                        AND EXISTS (SELECT 1 FROM pg_attribute a
                                     WHERE a.attrelid = k.conrelid AND a.attnum = ANY (k.conkey)
                                       AND a.atttypid IN (ty.oid, ty.typarray))))
               AND NOT EXISTS (SELECT 1 FROM pg_attribute g
                                WHERE g.attrelid = k.conrelid AND g.attnum = ANY (k.conkey)
                                  AND g.attgenerated = 's')
             ORDER BY c.relname, k.conname",
            [$type]
        );
        return self::definitions($rows);
    }

    /**
     * Indexes with a literal of the type in an expression or predicate, not
     * backing a constraint, not on a generated column, and not a partition's
     * copy of its parent's.
     */
    private static function indexes(Connection $connection, string $type): array {
        $rows = $connection->select(self::typeCte($connection) . "
            SELECT t.relname AS table_name, i.relname AS name,
                   -- A partitioned table's index renders ON ONLY, which would
                   -- come back without its partitions' indexes.
                   CASE WHEN i.relkind = 'I'
                        THEN regexp_replace(pg_get_indexdef(i.oid), ' ON ONLY ', ' ON ')
                        ELSE pg_get_indexdef(i.oid) END AS definition,
                   quote_literal(obj_description(i.oid, 'pg_class')) AS comment
              FROM ty, pg_index x
              JOIN pg_class i ON i.oid = x.indexrelid
              JOIN pg_class t ON t.oid = x.indrelid
              JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = " . SchemaScope::literal($connection) . "
               AND EXISTS (SELECT 1 FROM pg_depend d
                            WHERE d.classid = 'pg_class'::regclass AND d.objid = i.oid
                              AND d.refobjid IN (ty.oid, ty.typarray))
               AND NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conindid = i.oid)
               AND NOT EXISTS (SELECT 1 FROM pg_inherits h WHERE h.inhrelid = i.oid)
               AND NOT EXISTS (SELECT 1 FROM pg_attribute g
                                WHERE g.attrelid = t.oid AND g.attgenerated = 's'
                                  AND (g.attnum = ANY (x.indkey::int2[])
                                       OR EXISTS (SELECT 1 FROM pg_depend gd
                                                   WHERE gd.classid = 'pg_class'::regclass AND gd.objid = i.oid
                                                     AND gd.refobjid = t.oid AND gd.refobjsubid = g.attnum)))
             ORDER BY t.relname, i.relname",
            [$type]
        );
        return self::definitions($rows);
    }

    private static function definitions(array $rows): array {
        return array_map(fn($r) => [
            'table'      => $r['table_name'],
            'name'       => $r['name'],
            'definition' => $r['definition'],
            'comment'    => $r['comment'] ?? null,
        ], $rows);
    }

    /**
     * Every pg_depend record on the type or its array type, each classified:
     * `column`, `index`, `constraint` and `default` the swap handles itself;
     * `view`, `policy`, `trigger` and `generated` it carries if the dependant
     * lookup found them (keyed as ColumnDependantPlan keys them); `other` it
     * cannot carry.
     *
     * @return list<array{kind: string, key: string, description: string}>
     */
    private static function users(Connection $connection, string $type): array {
        return $connection->select(self::typeCte($connection) . "
            SELECT CASE
                     WHEN d.classid = 'pg_class'::regclass AND c.relkind IN ('i', 'I') THEN 'index'
                     WHEN d.classid = 'pg_class'::regclass AND c.relkind IN ('v', 'm') THEN 'view'
                     WHEN d.classid = 'pg_class'::regclass AND n.nspname <> " . SchemaScope::literal($connection) . " THEN 'other'
                     WHEN d.classid = 'pg_class'::regclass AND c.relkind IN ('r', 'p') AND d.objsubid > 0
                          AND a.attgenerated = 's' THEN 'generated'
                     -- objsubid 0 on a table is its partition key, which no
                     -- ALTER can retype.
                     WHEN d.classid = 'pg_class'::regclass AND c.relkind IN ('r', 'p') AND d.objsubid > 0
                          THEN 'column'
                     WHEN d.classid = 'pg_attrdef'::regclass AND da.attgenerated = 's' THEN 'generated'
                     WHEN d.classid = 'pg_attrdef'::regclass THEN 'default'
                     WHEN d.classid = 'pg_constraint'::regclass AND k.conrelid <> 0 THEN 'constraint'
                     WHEN d.classid = 'pg_rewrite'::regclass THEN 'view'
                     WHEN d.classid = 'pg_policy'::regclass THEN 'policy'
                     WHEN d.classid = 'pg_trigger'::regclass THEN 'trigger'
                     ELSE 'other'
                   END AS kind,
                   CASE
                     WHEN d.classid = 'pg_class'::regclass AND c.relkind IN ('v', 'm')
                          THEN n.nspname || '.' || c.relname
                     WHEN d.classid = 'pg_class'::regclass AND a.attgenerated = 's'
                          THEN n.nspname || '.' || c.relname || '.' || a.attname
                     WHEN d.classid = 'pg_attrdef'::regclass AND da.attgenerated = 's'
                          THEN 'public.' || dc.relname || '.' || da.attname
                     WHEN d.classid = 'pg_attrdef'::regclass THEN dc.relname || '.' || da.attname
                     WHEN d.classid = 'pg_rewrite'::regclass
                          THEN rn.nspname || '.' || rc.relname
                     WHEN d.classid = 'pg_policy'::regclass
                          THEN pn.nspname || '.' || pc.relname || '.' || p.polname
                     WHEN d.classid = 'pg_trigger'::regclass
                          THEN tn.nspname || '.' || tc.relname || '.' || tg.tgname
                     ELSE ''
                   END AS key,
                   pg_describe_object(d.classid, d.objid, d.objsubid) AS description
              FROM ty
              JOIN pg_depend d ON d.refobjid IN (ty.oid, ty.typarray) AND d.deptype = 'n'
              LEFT JOIN pg_class c ON d.classid = 'pg_class'::regclass AND c.oid = d.objid
              LEFT JOIN pg_namespace n ON n.oid = c.relnamespace
              LEFT JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = d.objsubid AND d.objsubid > 0
              LEFT JOIN pg_attrdef ad ON d.classid = 'pg_attrdef'::regclass AND ad.oid = d.objid
              LEFT JOIN pg_attribute da ON da.attrelid = ad.adrelid AND da.attnum = ad.adnum
              LEFT JOIN pg_class dc ON dc.oid = ad.adrelid
              LEFT JOIN pg_constraint k ON d.classid = 'pg_constraint'::regclass AND k.oid = d.objid
              LEFT JOIN pg_rewrite r ON d.classid = 'pg_rewrite'::regclass AND r.oid = d.objid
              LEFT JOIN pg_class rc ON rc.oid = r.ev_class
              LEFT JOIN pg_namespace rn ON rn.oid = rc.relnamespace
              LEFT JOIN pg_policy p ON d.classid = 'pg_policy'::regclass AND p.oid = d.objid
              LEFT JOIN pg_class pc ON pc.oid = p.polrelid
              LEFT JOIN pg_namespace pn ON pn.oid = pc.relnamespace
              LEFT JOIN pg_trigger tg ON d.classid = 'pg_trigger'::regclass AND tg.oid = d.objid
              LEFT JOIN pg_class tc ON tc.oid = tg.tgrelid
              LEFT JOIN pg_namespace tn ON tn.oid = tc.relnamespace
             WHERE NOT (d.classid = 'pg_type'::regclass AND d.objid = ty.typarray)
             ORDER BY 3",
            [$type]
        );
    }

    /** The type's comment and grants, which DROP TYPE takes with it. */
    private static function typeMetadata(Connection $connection, string $type): array {
        $rows = $connection->select(self::typeCte($connection) . "
            SELECT quote_literal(obj_description(t.oid, 'pg_type')) AS comment,
                   t.typacl IS NOT NULL
                     AND NOT EXISTS (SELECT 1 FROM aclexplode(t.typacl) a WHERE a.grantee = 0) AS public_revoked,
                   " . PostgresAcl::grantsJson('t.typacl', 't.typowner', true) . " AS grants
              FROM ty JOIN pg_type t ON t.oid = ty.oid",
            [$type]
        );
        $row = $rows[0] ?? [];
        $grants = json_decode($row['grants'] ?? 'null', true);
        return [
            'comment'       => $row['comment'] ?? null,
            'grants'        => is_array($grants) ? $grants : [],
            'publicRevoked' => (bool) ($row['public_revoked'] ?? false),
        ];
    }
}
