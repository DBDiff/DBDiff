<?php namespace DBDiff\DB\Support;

use DBDiff\DB\Support\Concerns\CatalogText;
use Illuminate\Database\Connection;

/**
 * Object kinds read straight from the catalog, each as
 * [key => ['create' => ..., 'drop' => ..., 'table' => ?string]] — see the
 * SchemaObject diffs.
 *
 * Kinds that belong to the database rather than a schema — an event
 * trigger, a cast, a foreign server — are read with the schema their
 * function or type is in, or with `public`, so a diff over several schemas
 * names each once.
 */
final class PostgresCatalogObjects {
    use CatalogText;

    /**
     * Range types: `CREATE TYPE r AS RANGE (subtype = ...)`.
     *
     * Each option is written only where it is not what PostgreSQL would pick
     * by itself, so a type reads the same however it was declared. Read
     * through to_jsonb: the multirange column arrived in PostgreSQL 14.
     */
    public static function rangeTypes(Connection $connection): array {
        $rows = $connection->select(
            "SELECT t.typname AS name,
                    format_type(r.rngsubtype, NULL) AS subtype,
                    CASE WHEN r.rngsubopc <> (SELECT o.oid FROM pg_opclass o JOIN pg_am a ON a.oid = o.opcmethod
                                               WHERE a.amname = 'btree' AND o.opcintype = r.rngsubtype AND o.opcdefault LIMIT 1)
                         THEN (SELECT quote_ident(n2.nspname) || '.' || quote_ident(o.opcname) FROM pg_opclass o
                                 JOIN pg_namespace n2 ON n2.oid = o.opcnamespace WHERE o.oid = r.rngsubopc) END AS opclass,
                    CASE WHEN r.rngcollation <> 0 AND r.rngcollation <> st.typcollation
                         THEN (SELECT quote_ident(cn.nspname) || '.' || quote_ident(c.collname) FROM pg_collation c
                                 JOIN pg_namespace cn ON cn.oid = c.collnamespace WHERE c.oid = r.rngcollation) END AS collation,
                    NULLIF(r.rngcanonical, 0)::regproc::text AS canonical,
                    NULLIF(r.rngsubdiff, 0)::regproc::text AS subdiff,
                    (SELECT mt.typname FROM pg_type mt WHERE mt.oid = (to_jsonb(r) ->> 'rngmultitypid')::oid) AS multirange
               FROM pg_range r
               JOIN pg_type t ON t.oid = r.rngtypid
               JOIN pg_type st ON st.oid = r.rngsubtype
              WHERE " . self::ownedIn($connection, 't.typnamespace', 'pg_type', 't.oid') . "
              ORDER BY t.typname"
        );
        $types = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $options = ['subtype = ' . $row['subtype']];
            if ($row['opclass'] !== null) {
                $options[] = 'subtype_opclass = ' . $row['opclass'];
            }
            if ($row['collation'] !== null) {
                $options[] = 'collation = ' . $row['collation'];
            }
            if ($row['canonical'] !== null) {
                $options[] = 'canonical = ' . $row['canonical'];
            }
            if ($row['subdiff'] !== null) {
                $options[] = 'subtype_diff = ' . $row['subdiff'];
            }
            if ($row['multirange'] !== null && $row['multirange'] !== self::defaultMultirangeName($row['name'])) {
                $options[] = 'multirange_type_name = ' . self::ident($row['multirange']);
            }
            $name = SchemaScope::name($connection, $row['name']);
            $types[$row['name']] = [
                'create' => "CREATE TYPE $name AS RANGE (" . implode(', ', $options) . ')',
                'drop'   => "DROP TYPE IF EXISTS $name",
            ];
        }
        return $types;
    }

    /** What PostgreSQL names a range type's multirange when not told. */
    private static function defaultMultirangeName(string $range): string {
        $at = strpos($range, 'range');
        return $at === false
            ? $range . '_multirange'
            : substr($range, 0, $at) . 'multirange' . substr($range, $at + 5);
    }

    /**
     * Event triggers, with the schema of the function they run: one fires on
     * DDL in the whole database, so it belongs to no schema of its own.
     */
    public static function eventTriggers(Connection $connection): array {
        $rows = $connection->select(
            "SELECT e.evtname AS name, e.evtevent AS event, e.evtenabled::text AS enabled,
                    e.evtfoid::regproc::text AS function,
                    array_to_json(e.evttags)::text AS tags
               FROM pg_event_trigger e
               JOIN pg_proc p ON p.oid = e.evtfoid
              WHERE " . self::ownedIn($connection, 'p.pronamespace', 'pg_event_trigger', 'e.oid') . "
              ORDER BY e.evtname"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $name = self::ident($row['name']);
            $tags = json_decode((string) $row['tags'], true) ?? [];
            $create = "CREATE EVENT TRIGGER $name ON " . $row['event']
                . ($tags !== [] ? ' WHEN TAG IN (' . implode(', ', array_map([self::class, 'literal'], $tags)) . ')' : '')
                . ' EXECUTE FUNCTION ' . $row['function'] . '()';
            $state = ['D' => 'DISABLE', 'R' => 'ENABLE REPLICA', 'A' => 'ENABLE ALWAYS'][$row['enabled']] ?? null;
            if ($state !== null) {
                $create .= ";\nALTER EVENT TRIGGER $name $state";
            }
            $out[$row['name']] = ['create' => $create, 'drop' => "DROP EVENT TRIGGER IF EXISTS $name"];
        }
        return $out;
    }

    /**
     * Rewrite rules on the schema's tables and views — not the `_RETURN`
     * rule that is a view's own definition. Keyed `table.rule`: a rule's
     * name is unique only on its relation.
     */
    public static function rules(Connection $connection): array {
        $rows = $connection->select(
            "SELECT c.relname AS relation, r.rulename AS name, pg_get_ruledef(r.oid, true) AS definition
               FROM pg_rewrite r
               JOIN pg_class c ON c.oid = r.ev_class
              WHERE c.relnamespace = " . SchemaScope::oid($connection) . "
                AND r.rulename <> '_RETURN'
                AND " . PostgresSchemaHelper::notExtensionMember('pg_class', 'c.oid') . "
              ORDER BY c.relname, r.rulename"
        );
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[$row['relation'] . '.' . $row['name']] = [
                'create' => rtrim(trim($row['definition']), ';'),
                'drop'   => 'DROP RULE IF EXISTS ' . self::ident($row['name']) . ' ON ' . SchemaScope::name($connection, $row['relation']),
                'table'  => $row['relation'],
            ];
        }
        return $out;
    }

    /**
     * Extended statistics objects (`CREATE STATISTICS`), the planner's
     * cross-column statistics, with a statistics target where one is set
     * (-1, or NULL from PostgreSQL 17, is the default).
     */
    public static function statistics(Connection $connection): array {
        $rows = $connection->select(
            "SELECT s.stxname AS name, c.relname AS relation,
                    pg_get_statisticsobjdef(s.oid) AS definition,
                    COALESCE((to_jsonb(s) ->> 'stxstattarget')::int, -1) AS target
               FROM pg_statistic_ext s
               JOIN pg_class c ON c.oid = s.stxrelid
              WHERE " . self::ownedIn($connection, 's.stxnamespace', 'pg_statistic_ext', 's.oid') . "
              ORDER BY s.stxname"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $name = SchemaScope::name($connection, $row['name']);
            $create = $row['definition'];
            if ((int) $row['target'] >= 0) {
                $create .= ";\nALTER STATISTICS $name SET STATISTICS " . (int) $row['target'];
            }
            $out[$row['name']] = [
                'create' => $create,
                'drop'   => "DROP STATISTICS IF EXISTS $name",
                'table'  => $row['relation'],
            ];
        }
        return $out;
    }

    /**
     * User-defined casts. A cast belongs to the database, so it is read with
     * the schema of its function, or of its source type when it has none;
     * the built-in ones (oid below FirstNormalObjectId) and an extension's
     * are not the user's.
     */
    public static function casts(Connection $connection): array {
        $scope = SchemaScope::oid($connection);
        $rows = $connection->select(
            "SELECT format_type(c.castsource, NULL) AS source, format_type(c.casttarget, NULL) AS target,
                    c.castmethod::text AS method, c.castcontext::text AS context,
                    CASE WHEN c.castfunc <> 0 THEN c.castfunc::regprocedure::text END AS function
               FROM pg_cast c
               LEFT JOIN pg_proc p ON p.oid = c.castfunc
               JOIN pg_type st ON st.oid = c.castsource
              WHERE c.oid >= 16384
                AND COALESCE(p.pronamespace, st.typnamespace) = $scope
                -- What PostgreSQL makes with a type: a range's cast to its multirange.
                AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_cast'::regclass
                                   AND d.objid = c.oid AND d.deptype = 'i')
                AND " . PostgresSchemaHelper::notExtensionMember('pg_cast', 'c.oid') . "
              ORDER BY 1, 2"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $pair = "({$row['source']} AS {$row['target']})";
            $how  = match ($row['method']) {
                'f'     => ' WITH FUNCTION ' . $row['function'],
                'i'     => ' WITH INOUT',
                default => ' WITHOUT FUNCTION',
            };
            $context = ['a' => ' AS ASSIGNMENT', 'i' => ' AS IMPLICIT'][$row['context']] ?? '';
            $out[$row['source'] . ' AS ' . $row['target']] = [
                'create' => "CREATE CAST $pair$how$context",
                'drop'   => "DROP CAST IF EXISTS $pair",
            ];
        }
        return $out;
    }
}
