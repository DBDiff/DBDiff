<?php namespace DBDiff\DB\Support;

use Illuminate\Database\Connection;

/**
 * Everything that has to stand aside while a column's type changes.
 *
 * PostgreSQL refuses `ALTER TABLE ... ALTER COLUMN ... TYPE` while anything
 * reads the column through a stored expression:
 *
 *     ERROR:  cannot alter type of a column used by a view or rule
 *     ERROR:  cannot alter type of a column used in a policy definition
 *     ERROR:  cannot alter type of a column used in a trigger definition
 *
 * so a type change on such a column produced a migration that could not run
 * (issue #226). The dependants are dropped around the change and put back, and
 * putting one back means reproducing all of it — not just its definition:
 *
 *   - a view's options. `security_invoker` above all: without it a view runs
 *     with its owner's rights and bypasses the row level security of the
 *     tables it reads, which on Supabase means exposing them to `anon`.
 *   - its grants, which DROP VIEW discards and CREATE VIEW does not restore.
 *   - its comments, its INSTEAD OF triggers, and a materialised view's
 *     indexes, none of which anything else recreates.
 *
 * Views in any schema are found, not just `public`: a view in `api` reading a
 * `public` column blocks the change just the same.
 */
final class PostgresColumnDependants {

    /**
     * The dependants of `$table`.`$column`, read from the given connection.
     *
     * `views` carry a depth — 1 reads the table, 2 reads such a view, and so
     * on — so they can be dropped deepest first and recreated shallowest
     * first. `defaultGrantees` are the roles the database's default privileges
     * grant to on a new relation, which a recreated view would pick up on top
     * of the grants it had.
     *
     * @return array{views: array<int, array<string, mixed>>, policies: array<int, array<string, string>>, triggers: array<int, array<string, string>>, generated: array<int, array<string, mixed>>, defaultGrantees: string[]}
     */
    public static function find(Connection $connection, string $table, string $column, bool $regenerate = false): array {
        $views    = self::views($connection, $table, $column);
        $viewOids = array_map(fn(array $v) => (int) $v['oid'], $views);

        $dependants = [
            'views'    => array_map([self::class, 'withoutOid'], $views),
            'policies' => self::policies($connection, $table, $column, $viewOids),
            'triggers' => self::triggers($connection, $table, $column),
            'generated' => self::generatedColumns($connection, $table, $column, $regenerate),
            'defaultGrantees' => [],
        ];

        if ($dependants['views'] !== []) {
            $dependants['defaultGrantees'] = array_column($connection->select(
                "SELECT DISTINCT CASE WHEN a.grantee = 0 THEN 'PUBLIC'
                                      ELSE quote_ident(pg_get_userbyid(a.grantee)) END AS grantee
                 FROM pg_default_acl d, aclexplode(d.defaclacl) a
                 WHERE d.defaclobjtype = 'r'
                 ORDER BY 1"
            ), 'grantee');
        }

        return $dependants;
    }

    /** True when there is nothing in the way. */
    public static function isEmpty(array $dependants): bool {
        return ($dependants['views'] ?? []) === []
            && ($dependants['policies'] ?? []) === []
            && ($dependants['triggers'] ?? []) === []
            && ($dependants['generated'] ?? []) === [];
    }

    private static function withoutOid(array $view): array {
        unset($view['oid']);
        return $view;
    }

    /** The column being changed, as `target_col (rel_oid, attnum)`. */
    /**
     * `g` reads the column `reads.refobjsubid` of its own table.
     *
     * Recorded two ways: up to PostgreSQL 17 the generated column depends on
     * the columns it reads; from 18 its generation expression (the pg_attrdef
     * entry) does. Either has to be found, or the column change runs into
     * "cannot alter type of a column used by a generated column".
     */
    private const GENERATED_READS = "EXISTS (
                     SELECT 1 FROM pg_depend reads
                     LEFT JOIN pg_attrdef ad ON ad.oid = reads.objid
                     WHERE reads.refclassid = 'pg_class'::regclass
                       AND reads.refobjid = g.attrelid
                       AND reads.refobjsubid = %s
                       AND ((reads.classid = 'pg_class'::regclass
                             AND reads.objid = g.attrelid AND reads.objsubid = g.attnum)
                         OR (reads.classid = 'pg_attrdef'::regclass
                             AND ad.adrelid = g.attrelid AND ad.adnum = g.attnum)))";

    /**
     * The column being changed, as `target_col (rel_oid, attnum)`, and every
     * column standing in for it:
     *
     *   - the same column in each partition or inheritance child. The parent's
     *     type change reaches them, so a view reading the column through a
     *     partition blocks it just the same (issue #232).
     *   - the stored generated columns that read it. They are dropped and
     *     re-added around the change, so whatever reads *them* has to stand
     *     aside too (issue #233).
     */
    private static function targetColumnCte(): string {
        return "target_col AS (
                 WITH RECURSIVE rels AS (
                     SELECT c.oid
                     FROM pg_class c
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public' AND c.relname = ?
                     UNION
                     SELECT i.inhrelid FROM pg_inherits i JOIN rels ON i.inhparent = rels.oid
                 ),
                 base AS (
                     SELECT a.attrelid AS rel_oid, a.attnum
                     FROM rels
                     JOIN pg_attribute a ON a.attrelid = rels.oid
                     WHERE a.attname = ? AND NOT a.attisdropped
                 )
                 SELECT rel_oid, attnum FROM base
                 UNION
                 SELECT g.attrelid, g.attnum
                 FROM base
                 JOIN pg_attribute g ON g.attrelid = base.rel_oid AND g.attgenerated = 's'
                 WHERE " . sprintf(self::GENERATED_READS, 'base.attnum') . "
             )";
    }

    /**
     * Views and materialised views reading the column, directly or through
     * another view, with everything needed to put each back as it was.
     */
    private static function views(Connection $connection, string $table, string $column): array {
        $rows = $connection->select(
            "WITH RECURSIVE " . self::targetColumnCte() . ",
             deps AS (
                 SELECT DISTINCT dependent.oid AS view_oid, 1 AS depth
                 FROM pg_depend d
                 JOIN pg_rewrite r       ON r.oid = d.objid
                 JOIN pg_class dependent ON dependent.oid = r.ev_class
                 JOIN target_col tc      ON tc.rel_oid = d.refobjid AND d.refobjsubid = tc.attnum
                 WHERE d.classid = 'pg_rewrite'::regclass
                   AND d.refclassid = 'pg_class'::regclass
                   AND dependent.oid <> tc.rel_oid

                 UNION

                 SELECT DISTINCT dependent.oid, deps.depth + 1
                 FROM deps
                 JOIN pg_depend d        ON d.refobjid = deps.view_oid
                 JOIN pg_rewrite r       ON r.oid = d.objid
                 JOIN pg_class dependent ON dependent.oid = r.ev_class
                 WHERE d.classid = 'pg_rewrite'::regclass
                   AND d.refclassid = 'pg_class'::regclass
                   AND dependent.oid <> deps.view_oid
             ),
             deepest AS (
                 SELECT view_oid, max(depth) AS depth FROM deps GROUP BY view_oid
             )
             SELECT c.oid,
                    n.nspname                          AS schema,
                    c.relname                          AS name,
                    c.relkind                          AS kind,
                    quote_ident(pg_get_userbyid(c.relowner)) AS owner,
                    deepest.depth,
                    pg_get_viewdef(c.oid, true)        AS definition,
                    array_to_string(c.reloptions, ', ') AS options,
                    (SELECT quote_literal(d.description)
                       FROM pg_description d
                      WHERE d.objoid = c.oid AND d.classoid = 'pg_class'::regclass
                        AND d.objsubid = 0)            AS comment,
                    (SELECT json_agg(json_build_object(
                                'column', a.attname, 'comment', quote_literal(d.description))
                              ORDER BY a.attnum)
                       FROM pg_description d
                       JOIN pg_attribute a ON a.attrelid = d.objoid AND a.attnum = d.objsubid
                      WHERE d.objoid = c.oid AND d.classoid = 'pg_class'::regclass
                        AND d.objsubid > 0)            AS column_comments,
                    (SELECT json_agg(json_build_object(
                                'name', t.tgname, 'definition', pg_get_triggerdef(t.oid))
                              ORDER BY t.tgname)
                       FROM pg_trigger t
                      WHERE t.tgrelid = c.oid AND NOT t.tgisinternal) AS triggers,
                    (SELECT json_agg(pg_get_indexdef(i.indexrelid)
                              ORDER BY i.indexrelid::regclass::text)
                       FROM pg_index i
                      WHERE i.indrelid = c.oid)        AS indexes,
                    (SELECT json_agg(json_build_object(
                                'grantee', CASE WHEN a.grantee = 0 THEN 'PUBLIC'
                                                ELSE quote_ident(pg_get_userbyid(a.grantee)) END,
                                'privilege', a.privilege_type,
                                'grantable', a.is_grantable)
                              ORDER BY a.grantee, a.privilege_type)
                       FROM aclexplode(c.relacl) a
                      WHERE a.grantee <> c.relowner)   AS grants
             FROM deepest
             JOIN pg_class c     ON c.oid = deepest.view_oid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             ORDER BY deepest.depth, n.nspname, c.relname",
            [$table, $column]
        );

        $views = [];
        foreach ($rows as $row) {
            $views[] = [
                'oid'            => $row['oid'],
                'schema'         => $row['schema'],
                'name'           => $row['name'],
                'kind'           => $row['kind'],
                'owner'          => $row['owner'],
                'depth'          => (int) $row['depth'],
                'definition'     => rtrim(trim($row['definition']), ';'),
                'options'        => ($row['options'] ?? '') !== '' ? $row['options'] : null,
                'comment'        => $row['comment'] ?? null,
                'columnComments' => self::json($row['column_comments'] ?? null),
                'triggers'       => self::json($row['triggers'] ?? null),
                'indexes'        => array_map(
                    fn(string $sql) => rtrim(trim($sql), ';'),
                    self::json($row['indexes'] ?? null)
                ),
                'grants'         => self::json($row['grants'] ?? null),
            ];
        }
        return $views;
    }

    /**
     * Policies reading the column, or reading one of the views that are about
     * to be dropped — DROP VIEW is refused while a policy depends on the view.
     *
     * @param int[] $viewOids
     */
    private static function policies(Connection $connection, string $table, string $column, array $viewOids): array {
        $viewList = $viewOids === [] ? '0' : implode(',', array_map('intval', $viewOids));

        $rows = $connection->select(
            "WITH " . self::targetColumnCte() . "
             SELECT DISTINCT n.nspname AS schema, c.relname AS table_name, p.polname AS name,
                    p.polpermissive AS permissive,
                    CASE p.polcmd WHEN 'r' THEN 'SELECT' WHEN 'a' THEN 'INSERT'
                                  WHEN 'w' THEN 'UPDATE' WHEN 'd' THEN 'DELETE'
                                  ELSE 'ALL' END AS command,
                    (SELECT string_agg(quote_ident(r.rolname), ', ' ORDER BY r.rolname)
                       FROM unnest(p.polroles) AS ro
                       JOIN pg_roles r ON r.oid = ro) AS roles,
                    pg_get_expr(p.polqual, p.polrelid) AS using_expr,
                    pg_get_expr(p.polwithcheck, p.polrelid) AS check_expr
             FROM pg_depend d
             JOIN pg_policy p    ON p.oid = d.objid
             JOIN pg_class c     ON c.oid = p.polrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN target_col tc ON tc.rel_oid = d.refobjid AND tc.attnum = d.refobjsubid
             WHERE d.classid = 'pg_policy'::regclass
               AND d.refclassid = 'pg_class'::regclass
               AND (tc.rel_oid IS NOT NULL OR d.refobjid IN ($viewList))
             ORDER BY n.nspname, c.relname, p.polname",
            [$table, $column]
        );

        $policies = [];
        foreach ($rows as $row) {
            $policies[] = [
                'schema'     => $row['schema'],
                'table'      => $row['table_name'],
                'name'       => $row['name'],
                'definition' => PostgresSchemaHelper::policyDefinition(
                    $row,
                    PostgresSchemaHelper::qualifiedName($row['schema'], $row['table_name'])
                ),
            ];
        }
        return $policies;
    }

    /** Triggers whose WHEN condition or column list names the column. */
    private static function triggers(Connection $connection, string $table, string $column): array {
        $rows = $connection->select(
            "WITH " . self::targetColumnCte() . "
             SELECT DISTINCT n.nspname AS schema, c.relname AS table_name, t.tgname AS name,
                    pg_get_triggerdef(t.oid) AS definition
             FROM pg_depend d
             JOIN target_col tc  ON tc.rel_oid = d.refobjid AND tc.attnum = d.refobjsubid
             JOIN pg_trigger t   ON t.oid = d.objid
             JOIN pg_class c     ON c.oid = t.tgrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE d.classid = 'pg_trigger'::regclass
               AND d.refclassid = 'pg_class'::regclass
               AND NOT t.tgisinternal
               -- A partition's copy of a trigger declared on the parent: it
               -- goes and comes back with the parent's, and dropping it on
               -- its own is refused.
               AND t.tgparentid = 0
             ORDER BY n.nspname, c.relname, t.tgname",
            [$table, $column]
        );

        return array_map(fn($row) => [
            'schema'     => $row['schema'],
            'table'      => $row['table_name'],
            'name'       => $row['name'],
            'definition' => $row['definition'],
        ], $rows);
    }

    /**
     * Stored generated columns on the table that read the column (issue #233).
     *
     * PostgreSQL refuses to retype a column a generated column reads, and has
     * no way to suspend the expression and restore it — DROP EXPRESSION makes
     * the column a plain one for good. So each is dropped and re-added, which
     * recomputes it from its expression: no data is lost. Dropping a column
     * also drops the indexes and constraints on it, so those are captured to
     * go back with it, along with its comment and column grants.
     *
     * Only the table's own: a partition's generated column is the parent's,
     * and goes and comes back with it.
     */
    private static function generatedColumns(Connection $connection, string $table, string $column, bool $itself = false): array {
        // Either the generated columns reading `$column`, or — when
        // `$column` is itself a generated column being regenerated — that
        // column alone, with what dropping it would take away.
        $which = $itself
            ? 'g.attname = src.attname'
            : sprintf(self::GENERATED_READS, 'src.attnum');
        // Regenerating a column includes one that is plain on the target and
        // becomes generated: it is dropped and re-added as the source has it.
        $itselfSql = $itself ? 'true' : 'false';
        $rows = $connection->select(
            "SELECT n.nspname AS schema, c.relname AS table_name, g.attname AS name,
                    format_type(g.atttypid, g.atttypmod) AS type,
                    CASE WHEN co.collname IS NOT NULL AND co.collname <> 'default'
                         THEN quote_ident(co.collname) END AS collation,
                    pg_get_expr(ad.adbin, ad.adrelid) AS expression,
                    g.attnotnull AS not_null,
                    quote_literal(col_description(c.oid, g.attnum)) AS comment,
                    (SELECT json_agg(pg_get_indexdef(i.indexrelid) ORDER BY i.indexrelid::regclass::text)
                       FROM pg_index i
                      WHERE i.indrelid = c.oid
                        AND (g.attnum = ANY (i.indkey::int2[])
                             OR EXISTS (SELECT 1 FROM pg_depend x
                                         WHERE x.classid = 'pg_class'::regclass AND x.objid = i.indexrelid
                                           AND x.refobjid = c.oid AND x.refobjsubid = g.attnum))
                        AND NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conindid = i.indexrelid)
                    ) AS indexes,
                    (SELECT json_agg(format('ALTER TABLE %s ADD CONSTRAINT %I %s',
                                            c.oid::regclass, k.conname, pg_get_constraintdef(k.oid))
                                     ORDER BY k.conname)
                       FROM pg_constraint k
                      WHERE k.conrelid = c.oid AND g.attnum = ANY (k.conkey)
                        AND k.contype IN ('c', 'u', 'x', 'f')
                    ) AS constraints,
                    (SELECT json_agg(json_build_object(
                                'grantee', CASE WHEN a.grantee = 0 THEN 'PUBLIC'
                                                ELSE quote_ident(pg_get_userbyid(a.grantee)) END,
                                'privilege', a.privilege_type,
                                'grantable', a.is_grantable)
                              ORDER BY a.grantee, a.privilege_type)
                       FROM aclexplode(g.attacl) a
                      WHERE a.grantee <> c.relowner) AS grants
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute src ON src.attrelid = c.oid AND src.attname = ? AND NOT src.attisdropped
             JOIN pg_attribute g ON g.attrelid = c.oid AND NOT g.attisdropped
                                AND (g.attgenerated = 's' OR $itselfSql)
             LEFT JOIN pg_attrdef ad ON ad.adrelid = c.oid AND ad.adnum = g.attnum
             LEFT JOIN pg_collation co ON co.oid = g.attcollation
             WHERE n.nspname = 'public' AND c.relname = ? AND g.attinhcount = 0
               AND $which
             ORDER BY g.attnum",
            [$column, $table]
        );

        return array_map(fn($row) => [
            'schema'      => $row['schema'],
            'table'       => $row['table_name'],
            'name'        => $row['name'],
            'type'        => $row['type'],
            'collation'   => $row['collation'],
            'expression'  => $row['expression'],
            'notNull'     => (bool) $row['not_null'],
            'comment'     => $row['comment'] ?? null,
            'indexes'     => self::json($row['indexes'] ?? null),
            'constraints' => self::json($row['constraints'] ?? null),
            'grants'      => self::json($row['grants'] ?? null),
        ], $rows);
    }

    private static function json(?string $value): array {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
