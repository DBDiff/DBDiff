<?php namespace DBDiff\DB\Support;

/**
 * Reading PostgreSQL access control lists — for objects a migration drops
 * and recreates, whose grants have to come back exactly (views, generated
 * columns, swapped enum types). GrantSQL writes them back.
 */
final class PostgresAcl {

    /**
     * A subquery giving an ACL's grants as a JSON array of
     * `{grantee, privilege, grantable}` — what GrantSQL renders back. The
     * owner's own entry is left out, as is PUBLIC's when `$exceptPublic`.
     *
     * @param string $acl   the ACL column, e.g. `c.relacl`
     * @param string $owner the owner's oid column, e.g. `c.relowner`
     */
    public static function grantsJson(string $acl, string $owner, bool $exceptPublic = false): string {
        $except = $exceptPublic ? "a.grantee NOT IN (0, $owner)" : "a.grantee <> $owner";
        return "(SELECT json_agg(json_build_object(
                        'grantee', CASE WHEN a.grantee = 0 THEN 'PUBLIC'
                                        ELSE quote_ident(pg_get_userbyid(a.grantee)) END,
                        'privilege', a.privilege_type,
                        'grantable', a.is_grantable)
                      ORDER BY a.grantee, a.privilege_type)
               FROM aclexplode($acl) a
              WHERE $except)";
    }
}
