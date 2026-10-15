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
              WHERE s.stxnamespace = " . SchemaScope::oid($connection) . "
                AND " . PostgresSchemaHelper::notExtensionMember('pg_statistic_ext', 's.oid') . "
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

    /** Text search dictionaries: a template and its options. */
    public static function textSearchDictionaries(Connection $connection): array {
        $rows = $connection->select(
            "SELECT d.dictname AS name,
                    (SELECT quote_ident(n.nspname) || '.' || quote_ident(t.tmplname) FROM pg_ts_template t
                       JOIN pg_namespace n ON n.oid = t.tmplnamespace WHERE t.oid = d.dicttemplate) AS template,
                    d.dictinitoption AS options
               FROM pg_ts_dict d
              WHERE d.dictnamespace = " . SchemaScope::oid($connection) . "
                AND " . PostgresSchemaHelper::notExtensionMember('pg_ts_dict', 'd.oid') . "
              ORDER BY d.dictname"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $name = SchemaScope::name($connection, $row['name']);
            $options = ($row['options'] ?? '') !== '' ? ', ' . $row['options'] : '';
            $out[$row['name']] = [
                'create' => "CREATE TEXT SEARCH DICTIONARY $name (TEMPLATE = {$row['template']}$options)",
                'drop'   => "DROP TEXT SEARCH DICTIONARY IF EXISTS $name",
            ];
        }
        return $out;
    }

    /**
     * Text search configurations: a parser, and which dictionaries each
     * token type goes through. A changed one keeping its parser is changed
     * in place — its mappings dropped and added again — since an index or a
     * generated column over it would stop it being dropped.
     */
    public static function textSearchConfigurations(Connection $connection): array {
        $rows = $connection->select(
            "SELECT c.cfgname AS name,
                    (SELECT quote_ident(n.nspname) || '.' || quote_ident(p.prsname) FROM pg_ts_parser p
                       JOIN pg_namespace n ON n.oid = p.prsnamespace WHERE p.oid = c.cfgparser) AS parser,
                    (SELECT json_agg(json_build_array(t.alias, m.dicts) ORDER BY t.tokid)
                       FROM (SELECT maptokentype, string_agg(mapdict::regdictionary::text, ', ' ORDER BY mapseqno) AS dicts
                               FROM pg_ts_config_map WHERE mapcfg = c.oid GROUP BY maptokentype) m
                       JOIN ts_token_type(c.cfgparser) t ON t.tokid = m.maptokentype)::text AS mappings
               FROM pg_ts_config c
              WHERE c.cfgnamespace = " . SchemaScope::oid($connection) . "
                AND " . PostgresSchemaHelper::notExtensionMember('pg_ts_config', 'c.oid') . "
              ORDER BY c.cfgname"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $name = SchemaScope::name($connection, $row['name']);
            $byDicts = [];
            foreach (json_decode((string) $row['mappings'], true) ?? [] as [$token, $dicts]) {
                $byDicts[$dicts][] = $token;
            }
            $fill = [];
            foreach ($byDicts as $dicts => $tokens) {
                $fill[] = "ALTER TEXT SEARCH CONFIGURATION $name ADD MAPPING FOR " . implode(', ', $tokens) . " WITH $dicts";
            }
            $mapped = array_merge(...array_values($byDicts ?: [[]]));
            $clear = $mapped === [] ? '' : "ALTER TEXT SEARCH CONFIGURATION $name DROP MAPPING IF EXISTS FOR " . implode(', ', $mapped);
            $out[$row['name']] = [
                'create'  => implode(";\n", array_merge(["CREATE TEXT SEARCH CONFIGURATION $name (PARSER = {$row['parser']})"], $fill)),
                'drop'    => "DROP TEXT SEARCH CONFIGURATION IF EXISTS $name",
                'inPlace' => ['same' => $row['parser'], 'clear' => $clear, 'fill' => implode(";\n", $fill)],
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

    /** An option holding a credential: never written into a migration. */
    private const SECRET_OPTION = '/pass|secret|token|key|credential/i';

    /**
     * `OPTIONS (...)` contents as [key => value], from a catalog `k=v` array.
     *
     * @return array<string, string>
     */
    private static function options(?string $json): array {
        $out = [];
        foreach (json_decode((string) $json, true) ?? [] as $option) {
            [$key, $value] = array_pad(explode('=', (string) $option, 2), 2, '');
            $out[$key] = $value;
        }
        ksort($out);
        return $out;
    }

    /**
     * The options a migration may carry, and the credential ones it may not,
     * by name only, with the statement to set each by hand.
     *
     * @return array{0: string, 1: list<string>} the OPTIONS clause, and notes
     */
    private static function optionsClause(array $options, string $alterTarget): array {
        $plain = [];
        $notes = [];
        foreach ($options as $key => $value) {
            if (preg_match(self::SECRET_OPTION, $key)) {
                $notes[] = "-- $key is a credential and is not copied: ALTER $alterTarget OPTIONS (ADD " . self::ident($key) . " '...')";
            } else {
                $plain[] = self::ident($key) . ' ' . self::literal($value);
            }
        }
        return [$plain === [] ? '' : ' OPTIONS (' . implode(', ', $plain) . ')', $notes];
    }

    /**
     * Change one object's options where it stands: drop the plain ones it
     * has, add the ones wanted. Credentials are left as they are.
     */
    private static function optionsInPlace(array $options, string $alterTarget, string $same): array {
        $plain = array_filter($options, fn($k) => !preg_match(self::SECRET_OPTION, $k), ARRAY_FILTER_USE_KEY);
        return [
            'same'  => $same,
            'clear' => $plain === [] ? '' : "ALTER $alterTarget OPTIONS (" . implode(', ', array_map(fn($k) => 'DROP ' . self::ident($k), array_keys($plain))) . ')',
            'fill'  => $plain === [] ? '' : "ALTER $alterTarget OPTIONS (" . implode(', ', array_map(fn($k, $v) => 'ADD ' . self::ident($k) . ' ' . self::literal($v), array_keys($plain), $plain)) . ')',
        ];
    }

    /** What two sides are compared on: credentials by presence, not value. */
    private static function comparable(array $options): array {
        foreach ($options as $key => $value) {
            if (preg_match(self::SECRET_OPTION, $key)) {
                $options[$key] = '(set)';
            }
        }
        return $options;
    }

    /**
     * Foreign servers — Supabase Wrappers' among them — read with `public`,
     * as they belong to the database. Credential options are not copied.
     */
    public static function foreignServers(Connection $connection): array {
        if (!self::holdsDatabaseObjects($connection)) {
            return [];
        }
        $rows = $connection->select(
            "SELECT s.srvname AS name, w.fdwname AS wrapper, s.srvtype AS type, s.srvversion AS version,
                    array_to_json(s.srvoptions)::text AS options
               FROM pg_foreign_server s
               JOIN pg_foreign_data_wrapper w ON w.oid = s.srvfdw
              WHERE " . PostgresSchemaHelper::notExtensionMember('pg_foreign_server', 's.oid') . "
              ORDER BY s.srvname"
        );
        $out = [];
        foreach ($rows as $row) {
            $row     = (array) $row;
            $name    = self::ident($row['name']);
            $options = self::options($row['options']);
            [$clause, $notes] = self::optionsClause($options, "SERVER $name");
            $create = "CREATE SERVER $name"
                . ($row['type'] !== null ? ' TYPE ' . self::literal($row['type']) : '')
                . ($row['version'] !== null ? ' VERSION ' . self::literal($row['version']) : '')
                . ' FOREIGN DATA WRAPPER ' . self::ident($row['wrapper']) . $clause;
            $inPlace = self::optionsInPlace($options, "SERVER $name", $row['wrapper'] . '|' . ($row['type'] ?? ''));
            // ALTER SERVER sets a version but cannot take one away.
            $inPlace['sticky'] = $row['version'] !== null;
            if ($row['version'] !== null) {
                $inPlace['fill'] = trim($inPlace['fill'] . ";\nALTER SERVER $name VERSION " . self::literal($row['version']), ";\n");
            }
            $out[$row['name']] = [
                'create'  => implode(";\n", array_merge([$create], $notes)),
                'drop'    => "DROP SERVER IF EXISTS $name",
                'inPlace' => $inPlace,
                'compare' => json_encode([$row['wrapper'], $row['type'], $row['version'], self::comparable($options)]),
            ];
        }
        return $out;
    }

    /**
     * User mappings, read with `public`. A role sees another's options only
     * with privileges over the server; a password is never copied.
     */
    public static function userMappings(Connection $connection): array {
        if (!self::holdsDatabaseObjects($connection)) {
            return [];
        }
        $rows = $connection->select(
            "SELECT m.usename AS username, m.srvname AS server, array_to_json(m.umoptions)::text AS options
               FROM pg_user_mappings m
              ORDER BY m.srvname, m.usename"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $who  = $row['username'] === 'public' ? 'PUBLIC' : self::ident($row['username']);
            $what = "USER MAPPING FOR $who SERVER " . self::ident($row['server']);
            $options = self::options($row['options']);
            [$clause, $notes] = self::optionsClause($options, $what);
            $out[$row['username'] . '@' . $row['server']] = [
                'create'  => implode(";\n", array_merge(["CREATE $what$clause"], $notes)),
                'drop'    => "DROP USER MAPPING IF EXISTS FOR $who SERVER " . self::ident($row['server']),
                'inPlace' => self::optionsInPlace($options, $what, ''),
                'compare' => json_encode(self::comparable($options)),
            ];
        }
        return $out;
    }

    /** Foreign tables: their columns, server and options. */
    public static function foreignTables(Connection $connection): array {
        $rows = $connection->select(
            "SELECT c.relname AS name, s.srvname AS server, array_to_json(f.ftoptions)::text AS options,
                    (SELECT json_agg(json_build_array(a.attname, format_type(a.atttypid, a.atttypmod), a.attnotnull,
                                pg_get_expr(d.adbin, d.adrelid), array_to_json(a.attfdwoptions),
                                CASE WHEN a.attcollation <> t.typcollation THEN a.attcollation::regcollation::text END)
                              ORDER BY a.attnum)
                       FROM pg_attribute a
                       JOIN pg_type t ON t.oid = a.atttypid
                       LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
                      WHERE a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped)::text AS columns
               FROM pg_foreign_table f
               JOIN pg_class c ON c.oid = f.ftrelid
               JOIN pg_foreign_server s ON s.oid = f.ftserver
              WHERE c.relnamespace = " . SchemaScope::oid($connection) . "
                AND " . PostgresSchemaHelper::notExtensionMember('pg_class', 'c.oid') . "
              ORDER BY c.relname"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $name = SchemaScope::name($connection, $row['name']);
            $columns = [];
            foreach (json_decode((string) $row['columns'], true) ?? [] as [$col, $type, $notNull, $default, $fdw, $collation]) {
                $colOptions = self::options(json_encode($fdw));
                $columns[] = '  ' . self::ident($col) . ' ' . $type
                    . ($colOptions !== [] ? self::optionsClause($colOptions, "FOREIGN TABLE $name")[0] : '')
                    . ($collation !== null ? ' COLLATE ' . $collation : '')
                    . ($notNull ? ' NOT NULL' : '')
                    . ($default !== null ? ' DEFAULT ' . $default : '');
            }
            [$clause] = self::optionsClause(self::options($row['options']), "FOREIGN TABLE $name");
            $out[$row['name']] = [
                'create' => "CREATE FOREIGN TABLE $name (\n" . implode(",\n", $columns) . "\n) SERVER " . self::ident($row['server']) . $clause,
                'drop'   => "DROP FOREIGN TABLE IF EXISTS $name",
            ];
        }
        return $out;
    }
}
