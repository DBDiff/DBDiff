<?php namespace DBDiff\DB\Support;

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

    /** Whether this connection's scope is the one database-wide objects are read with. */
    private static function holdsDatabaseObjects(Connection $connection): bool {
        return SchemaScope::of($connection) === 'public';
    }

    private static function ident(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private static function literal(string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }

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
              WHERE t.typnamespace = " . SchemaScope::oid($connection) . "
                AND " . PostgresSchemaHelper::notExtensionMember('pg_type', 't.oid') . "
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

    /** What `ALTER DEFAULT PRIVILEGES` calls each kind pg_default_acl records. */
    private const DEFAULT_ACL_KINDS = ['r' => 'TABLES', 'S' => 'SEQUENCES', 'f' => 'FUNCTIONS', 'T' => 'TYPES', 'n' => 'SCHEMAS'];

    /**
     * Default privileges: what a role's new tables, sequences, functions,
     * types or schemas are granted when created — on Supabase, whether a new
     * table is reachable by `anon` at all.
     *
     * Keyed by role, schema and kind. An entry for every schema is compared
     * against PostgreSQL's built-in default for the kind (acldefault), one
     * for a single schema against nothing, since a schema's entry only adds
     * to the global one; each becomes the GRANTs and REVOKEs that make it,
     * and the ones that undo it.
     */
    public static function defaultPrivileges(Connection $connection): array {
        $global = self::holdsDatabaseObjects($connection);
        $rows = $connection->select(
            "SELECT pg_get_userbyid(d.defaclrole) AS role,
                    CASE WHEN d.defaclnamespace = 0 THEN NULL ELSE n.nspname END AS schema,
                    d.defaclobjtype::text AS kind,
                    (SELECT json_agg(json_build_array(
                                CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END,
                                a.privilege_type, a.is_grantable) ORDER BY 1, 2)
                       FROM aclexplode(d.defaclacl) a)::text AS have,
                    (SELECT json_agg(json_build_array(
                                CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END,
                                a.privilege_type, a.is_grantable) ORDER BY 1, 2)
                       FROM aclexplode(acldefault(d.defaclobjtype, d.defaclrole)) a)::text AS builtin
               FROM pg_default_acl d
               LEFT JOIN pg_namespace n ON n.oid = d.defaclnamespace
              WHERE d.defaclnamespace = " . SchemaScope::oid($connection) . "
                 OR (d.defaclnamespace = 0 AND " . ($global ? 'true' : 'false') . ")
              ORDER BY 1, 2 NULLS FIRST, 3"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $kind = self::DEFAULT_ACL_KINDS[$row['kind']] ?? null;
            if ($kind === null) {
                continue;
            }
            $have = self::aclRows($row['have']);
            // In one schema, an entry adds to what the global one gives.
            $base = $row['schema'] === null ? self::aclRows($row['builtin']) : [];
            $head = 'ALTER DEFAULT PRIVILEGES FOR ROLE ' . self::ident($row['role'])
                . ($row['schema'] !== null ? ' IN SCHEMA ' . self::ident($row['schema']) : '');
            $create = self::aclStatements($head, $kind, $base, $have);
            if ($create === []) {
                continue;
            }
            $key = $row['role'] . '.' . ($row['schema'] ?? '*') . '.' . strtolower($kind);
            $out[$key] = [
                'create' => implode(";\n", $create),
                'drop'   => implode(";\n", self::aclStatements($head, $kind, $have, $base)),
            ];
        }
        return $out;
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> keyed grantee|privilege */
    private static function aclRows(?string $json): array {
        $out = [];
        foreach (json_decode((string) $json, true) ?? [] as [$grantee, $privilege, $grantable]) {
            $out["$grantee|$privilege"] = [$grantee, $privilege, (bool) $grantable];
        }
        return $out;
    }

    /**
     * The GRANTs and REVOKEs that turn `$from` into `$to`, a privilege list
     * per grantee: REVOKE first, so a grant option being taken away is.
     *
     * @return list<string>
     */
    private static function aclStatements(string $head, string $kind, array $from, array $to): array {
        $grantee = fn(string $g) => $g === 'PUBLIC' ? 'PUBLIC' : self::ident($g);
        $revoke = [];
        $grant  = [];
        foreach ($from as $key => [$who, $privilege, $option]) {
            $wanted = $to[$key] ?? null;
            if ($wanted === null || ($option && !$wanted[2])) {
                $revoke[$who][] = $privilege;
            }
        }
        foreach ($to as $key => [$who, $privilege, $option]) {
            $had = $from[$key] ?? null;
            if ($had === null || ($option && !$had[2]) || (!$option && $had[2])) {
                $grant[$who . '|' . ($option ? 1 : 0)][] = $privilege;
            }
        }
        $out = [];
        foreach ($revoke as $who => $privileges) {
            $out[] = "$head REVOKE " . implode(', ', $privileges) . " ON $kind FROM " . $grantee($who);
        }
        foreach ($grant as $key => $privileges) {
            [$who, $option] = explode('|', $key);
            $out[] = "$head GRANT " . implode(', ', $privileges) . " ON $kind TO " . $grantee($who)
                . ($option === '1' ? ' WITH GRANT OPTION' : '');
        }
        return $out;
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
              WHERE p.pronamespace = " . SchemaScope::oid($connection) . "
                AND " . PostgresSchemaHelper::notExtensionMember('pg_event_trigger', 'e.oid') . "
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
     * Aggregates. pg_get_functiondef refuses one, so the definition is put
     * together from pg_aggregate — read through to_jsonb, whose columns grew
     * between versions — with each option written where it is set.
     */
    public static function aggregates(Connection $connection): array {
        $rows = $connection->select(
            "SELECT p.oid::regprocedure::text AS key,
                    p.proname AS name,
                    pg_get_function_arguments(p.oid) AS args,
                    pg_get_function_identity_arguments(p.oid) AS identity,
                    p.proparallel::text AS parallel,
                    to_jsonb(a) AS spec,
                    format_type(a.aggtranstype, NULL) AS stype,
                    CASE WHEN a.aggmtranstype <> 0 THEN format_type(a.aggmtranstype, NULL) END AS mstype,
                    CASE WHEN a.aggsortop <> 0 THEN
                        (SELECT 'OPERATOR(' || quote_ident(n.nspname) || '.' || o.oprname || ')'
                           FROM pg_operator o JOIN pg_namespace n ON n.oid = o.oprnamespace WHERE o.oid = a.aggsortop) END AS sortop,
                    (SELECT json_object_agg(f.k, f.v) FROM (
                        SELECT k, (to_jsonb(a) ->> k)::regproc::text AS v
                          FROM unnest(ARRAY['aggtransfn','aggfinalfn','aggcombinefn','aggserialfn','aggdeserialfn',
                                            'aggmtransfn','aggminvtransfn','aggmfinalfn']) AS k
                         WHERE COALESCE(to_jsonb(a) ->> k, '-') <> '-') f) AS functions
               FROM pg_aggregate a
               JOIN pg_proc p ON p.oid = a.aggfnoid
              WHERE p.pronamespace = " . SchemaScope::oid($connection) . "
                AND " . PostgresSchemaHelper::notExtensionMember('pg_proc', 'p.oid') . "
              ORDER BY 1"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $spec = json_decode((string) $row['spec'], true);
            $fn   = json_decode((string) ($row['functions'] ?? 'null'), true) ?? [];
            $modify = fn(?string $code) => ['r' => 'READ_ONLY', 's' => 'SHAREABLE', 'w' => 'READ_WRITE'][$code ?? ''] ?? null;
            $options = ['SFUNC = ' . $fn['aggtransfn'], 'STYPE = ' . $row['stype']];
            $add = function (string $option, $value) use (&$options) {
                if ($value !== null && $value !== '' && $value !== 0) {
                    $options[] = "$option = $value";
                }
            };
            $add('SSPACE', (int) ($spec['aggtransspace'] ?? 0) ?: null);
            $add('FINALFUNC', $fn['aggfinalfn'] ?? null);
            if (isset($fn['aggfinalfn'])) {
                if (!empty($spec['aggfinalextra'])) {
                    $options[] = 'FINALFUNC_EXTRA';
                }
                $add('FINALFUNC_MODIFY', $modify($spec['aggfinalmodify'] ?? null));
            }
            $add('COMBINEFUNC', $fn['aggcombinefn'] ?? null);
            $add('SERIALFUNC', $fn['aggserialfn'] ?? null);
            $add('DESERIALFUNC', $fn['aggdeserialfn'] ?? null);
            $add('INITCOND', isset($spec['agginitval']) ? self::literal($spec['agginitval']) : null);
            $add('MSFUNC', $fn['aggmtransfn'] ?? null);
            $add('MINVFUNC', $fn['aggminvtransfn'] ?? null);
            if (isset($fn['aggmtransfn'])) {
                $add('MSTYPE', $row['mstype']);
                $add('MSSPACE', (int) ($spec['aggmtransspace'] ?? 0) ?: null);
                $add('MFINALFUNC', $fn['aggmfinalfn'] ?? null);
                if (isset($fn['aggmfinalfn'])) {
                    if (!empty($spec['aggmfinalextra'])) {
                        $options[] = 'MFINALFUNC_EXTRA';
                    }
                    $add('MFINALFUNC_MODIFY', $modify($spec['aggmfinalmodify'] ?? null));
                }
                $add('MINITCOND', isset($spec['aggminitval']) ? self::literal($spec['aggminitval']) : null);
            }
            $add('SORTOP', $row['sortop']);
            $add('PARALLEL', ['s' => 'SAFE', 'r' => 'RESTRICTED'][$row['parallel']] ?? null);
            if (($spec['aggkind'] ?? 'n') === 'h') {
                $options[] = 'HYPOTHETICAL';
            }
            $name = SchemaScope::name($connection, $row['name']);
            $out[$row['key']] = [
                'create' => "CREATE AGGREGATE $name(" . $row['args'] . ') (' . implode(', ', $options) . ')',
                'drop'   => "DROP AGGREGATE IF EXISTS $name(" . $row['identity'] . ')',
            ];
        }
        return $out;
    }
}
