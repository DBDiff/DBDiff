<?php namespace DBDiff\DB\Support;

use DBDiff\Diff\AlterComment;
use DBDiff\Diff\AlterCompositeType;
use DBDiff\Diff\AlterDomain;
use DBDiff\Diff\AlterMatView;
use DBDiff\Diff\AlterPolicy;
use DBDiff\Diff\AlterRoutine;
use DBDiff\Diff\AlterTableChangeConstraint;
use DBDiff\Diff\AlterTableChangeKey;
use DBDiff\Diff\AlterTrigger;
use DBDiff\Diff\AlterView;
use Illuminate\Database\Connection;

/**
 * Comments — COMMENT ON — on everything in the schema being compared.
 *
 * Not read at all before, so a comment added, changed or removed was no
 * drift, and an object created by a migration arrived without its comment.
 * Every kind is read in one place and compared by the object's identity as
 * PostgreSQL names it (`pg_identify_object`), and each difference is one
 * change: `COMMENT ON <object> IS ...`, after everything else is made.
 *
 * An object another change drops and recreates — a view replaced, a routine,
 * a trigger, policy, index or constraint redefined — loses its comment with
 * it, so its comment is set again even where both sides agree on it.
 *
 * A materialized view's comment travels with its definition
 * (PostgresObjectKinds::materializedViews) and is left out here.
 */
final class PostgresComments
{
    /**
     * The changes that bring the target's comments to the source's.
     *
     * @param  array<int, object> $diffs    the other changes, for what they recreate
     * @param  list<string>       $excluded tables the diff leaves out, and so their comments
     * @return list<AlterComment>
     */
    public static function diff(Connection $source, Connection $target, array $diffs, array $excluded = []): array
    {
        $outside = array_flip($excluded);
        $kept = fn(array $row) => !isset($outside[$row['relation'] ?? '']) && !isset($outside[$row['table'] ?? '']);
        $from = array_filter(self::read($source), $kept);
        $to   = array_filter(self::read($target), $kept);
        [$keys, $relations] = self::recreated($diffs);

        $changes = [];
        foreach (array_keys($from + $to) as $identity) {
            $s = $from[$identity] ?? null;
            $t = $to[$identity] ?? null;
            $any = $s ?? $t;
            $recreated = isset($keys[$any['key']]) || ($any['relation'] !== null && isset($relations[$any['relation']]));

            $up   = self::setting($s, $t, $recreated);
            $down = self::setting($t, $s, $recreated);
            if ($up !== null || $down !== null) {
                $changes[] = new AlterComment($any['on'], $up, $down);
            }
        }
        return $changes;
    }

    /**
     * What `$want`'s comment is set to, as an SQL literal or NULL; null for no
     * statement. Where the object is not there to want one — it is dropped —
     * or the other side already has it, there is none to make.
     */
    private static function setting(?array $want, ?array $have, bool $recreated): ?string
    {
        if ($want === null) {
            return null;
        }
        $comment = $want['comment'];
        if ($have === null || $recreated) {
            return $comment;
        }
        return $comment === $have['comment'] ? null : ($comment ?? 'NULL');
    }

    /**
     * What the other changes drop and recreate: by key, and every comment on
     * a member of a recreated relation (a view's columns, a domain's
     * constraints, a materialized view's indexes).
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private static function recreated(array $diffs): array
    {
        $keys = [];
        $relations = [];
        foreach ($diffs as $diff) {
            $key = match (true) {
                $diff instanceof AlterView                  => 'view|' . $diff->name,
                $diff instanceof AlterRoutine               => 'routine|' . $diff->name,
                $diff instanceof AlterTrigger               => "trigger|{$diff->table}.{$diff->name}",
                $diff instanceof AlterPolicy                => "policy|{$diff->table}.{$diff->name}",
                $diff instanceof AlterDomain,
                $diff instanceof AlterCompositeType         => 'type|' . $diff->name,
                $diff instanceof AlterTableChangeKey        => 'index|' . $diff->key,
                $diff instanceof AlterTableChangeConstraint => "constraint|{$diff->table}.{$diff->name}",
                default                                     => null,
            };
            if ($key !== null) {
                $keys[$key] = true;
            }
            if ($diff instanceof AlterView || $diff instanceof AlterMatView
                || $diff instanceof AlterDomain || $diff instanceof AlterCompositeType) {
                $relations[$diff->name] = true;
            }
        }
        return [$keys, $relations];
    }

    /**
     * Every commentable object in the schema, keyed by its identity: what to
     * write after COMMENT ON, its comment as an SQL literal (or null), the key
     * the other changes name it by, and the relation it belongs to.
     *
     * @return array<string, array{on: string, comment: ?string, key: string, relation: ?string, table: ?string}>
     */
    public static function read(Connection $connection): array
    {
        $out = [];
        foreach ($connection->select(self::sql($connection)) as $row) {
            $row = (array) $row;
            $on = self::target(
                $row['type'], $row['identity'],
                json_decode($row['names'], true) ?? [], json_decode($row['args'], true) ?? []
            );
            if ($on !== null) {
                $out[$row['type'] . ' ' . $row['identity']] = [
                    'on' => $on, 'comment' => $row['comment'], 'key' => $row['key'], 'relation' => $row['relation'],
                    // A table names itself: --tables and --ignore-tables leave it out too.
                    'table' => str_starts_with($row['key'], 'relation|') ? substr($row['key'], 9) : null,
                ];
            }
        }
        return $out;
    }

    /** What follows COMMENT ON for an object PostgreSQL identifies so. */
    public static function target(string $type, string $identity, array $names, array $args): ?string
    {
        $q = fn(string $name) => '"' . str_replace('"', '""', $name) . '"';
        $onTable = fn() => ' ON ' . $q($names[0]) . '.' . $q($names[1]);
        return match ($type) {
            'table', 'view', 'index', 'sequence', 'type', 'function', 'procedure', 'aggregate', 'schema'
                => strtoupper($type) . ' ' . $identity,
            'foreign table' => 'FOREIGN TABLE ' . $identity,
            'table column', 'view column', 'foreign table column', 'composite type column' => 'COLUMN ' . $identity,
            'table constraint' => 'CONSTRAINT ' . $q($names[2]) . $onTable(),
            'trigger'          => 'TRIGGER ' . $q($names[2]) . $onTable(),
            'policy'           => 'POLICY ' . $q($names[2]) . $onTable(),
            'domain constraint' => 'CONSTRAINT ' . $q($args[0]) . ' ON DOMAIN ' . $names[0],
            default => null,
        };
    }

    private static function sql(Connection $connection): string
    {
        $s = SchemaScope::oid($connection);
        return "WITH objects (classid, objid, objsubid, key, relation) AS (
                SELECT 'pg_class'::regclass, c.oid, 0,
                       CASE WHEN c.relkind = 'v' THEN 'view|' WHEN c.relkind IN ('i', 'I') THEN 'index|' ELSE 'relation|' END || c.relname,
                       (SELECT t.relname FROM pg_index x JOIN pg_class t ON t.oid = x.indrelid WHERE x.indexrelid = c.oid)
                  FROM pg_class c WHERE c.relnamespace = $s AND c.relkind IN ('r', 'p', 'v', 'f', 'i', 'I', 'S')
                UNION ALL
                SELECT 'pg_class'::regclass, c.oid, a.attnum, 'column|' || c.relname || '.' || a.attname, c.relname
                  FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
                 WHERE c.relnamespace = $s AND c.relkind IN ('r', 'p', 'v', 'f', 'c') AND a.attnum > 0 AND NOT a.attisdropped
                UNION ALL
                SELECT 'pg_proc'::regclass, p.oid, 0, 'routine|' || p.oid::regprocedure::text, NULL
                  FROM pg_proc p WHERE p.pronamespace = $s
                UNION ALL
                SELECT 'pg_type'::regclass, t.oid, 0, 'type|' || t.typname, NULL
                  FROM pg_type t LEFT JOIN pg_class r ON r.oid = t.typrelid
                 WHERE t.typnamespace = $s AND (t.typtype IN ('e', 'd') OR r.relkind = 'c')
                UNION ALL
                SELECT 'pg_constraint'::regclass, k.oid, 0,
                       'constraint|' || coalesce(r.relname, d.typname) || '.' || k.conname, coalesce(r.relname, d.typname)
                  FROM pg_constraint k LEFT JOIN pg_class r ON r.oid = k.conrelid LEFT JOIN pg_type d ON d.oid = k.contypid
                 WHERE k.connamespace = $s AND coalesce(r.relkind, '') <> 'm'
                UNION ALL
                SELECT 'pg_trigger'::regclass, g.oid, 0, 'trigger|' || c.relname || '.' || g.tgname, c.relname
                  FROM pg_trigger g JOIN pg_class c ON c.oid = g.tgrelid
                 WHERE c.relnamespace = $s AND NOT g.tgisinternal AND g.tgparentid = 0
                UNION ALL
                SELECT 'pg_policy'::regclass, p.oid, 0, 'policy|' || c.relname || '.' || p.polname, c.relname
                  FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid WHERE c.relnamespace = $s
                UNION ALL
                SELECT 'pg_namespace'::regclass, n.oid, 0, 'schema|' || n.nspname, NULL
                  FROM pg_namespace n WHERE n.oid = $s
            )
            SELECT i.type, i.identity, to_json(a.object_names)::text AS names, to_json(a.object_args)::text AS args,
                   quote_literal(d.description) AS comment, o.key, o.relation
              FROM objects o
              CROSS JOIN LATERAL pg_identify_object(o.classid, o.objid, o.objsubid) i
              CROSS JOIN LATERAL pg_identify_object_as_address(o.classid, o.objid, o.objsubid) a
              LEFT JOIN pg_description d ON d.classoid = o.classid AND d.objoid = o.objid AND d.objsubid = o.objsubid
             WHERE NOT EXISTS (
                     SELECT 1 FROM pg_depend e
                      WHERE e.deptype = 'e' AND e.classid = o.classid AND e.objid = o.objid)";
    }
}
