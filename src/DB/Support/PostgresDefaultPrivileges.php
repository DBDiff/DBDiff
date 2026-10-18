<?php namespace DBDiff\DB\Support;

use DBDiff\DB\Support\Concerns\CatalogText;
use Illuminate\Database\Connection;

/** Default privileges (`ALTER DEFAULT PRIVILEGES`), as SchemaObject diffs — see PostgresCatalogObjects. */
final class PostgresDefaultPrivileges {
    use CatalogText;

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
            // Read as both servers can hold it: an older one has no MAINTAIN.
            if (!PostgresPeers::bothHave($privilege)) {
                continue;
            }
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
        $out = [];
        foreach (self::revokes($from, $to) as $who => $privileges) {
            $out[] = "$head REVOKE " . implode(', ', $privileges) . " ON $kind FROM " . self::grantee($who);
        }
        foreach (self::grants($from, $to) as $key => $privileges) {
            [$who, $option] = explode('|', $key);
            $out[] = "$head GRANT " . implode(', ', $privileges) . " ON $kind TO " . self::grantee($who)
                . ($option === '1' ? ' WITH GRANT OPTION' : '');
        }
        return $out;
    }

    /** Per grantee, what `$to` no longer gives, or gives without the grant option. */
    private static function revokes(array $from, array $to): array {
        $revoke = [];
        foreach ($from as $key => [$who, $privilege, $option]) {
            $wanted = $to[$key] ?? null;
            if ($wanted === null || ($option && !$wanted[2])) {
                $revoke[$who][] = $privilege;
            }
        }
        return $revoke;
    }

    /** Per grantee and grant option, what `$to` gives that `$from` did not, or not like that. */
    private static function grants(array $from, array $to): array {
        $grant = [];
        foreach ($to as $key => [$who, $privilege, $option]) {
            $had = $from[$key] ?? null;
            if ($had === null || $option !== $had[2]) {
                $grant[$who . '|' . ($option ? 1 : 0)][] = $privilege;
            }
        }
        return $grant;
    }

    private static function grantee(string $name): string {
        return $name === 'PUBLIC' ? 'PUBLIC' : self::ident($name);
    }
}
