<?php namespace DBDiff\DB\Support;

use DBDiff\Diff\AlterMatView;
use DBDiff\Diff\AlterView;
use Illuminate\Database\Connection;

/**
 * The grants a view the migration replaces has to keep.
 *
 * A changed view is dropped and created again, which discards its grants, and
 * where the target has default privileges the new view picks those up instead.
 * Each replaced view is given the source's grants for the UP and the target's
 * for the DOWN, with the target's owner and default grantees — what
 * GrantSQL::restore needs to put them back exactly.
 */
final class PostgresViewGrants {

    /** @param array<int, object> $diffs */
    public static function apply(array $diffs, Connection $source, Connection $target): void {
        $views = array_filter($diffs, fn($d) => $d instanceof AlterView || $d instanceof AlterMatView);
        if ($views === []) {
            return;
        }
        $defaultGrantees = array_column($target->select(
            "SELECT DISTINCT CASE WHEN a.grantee = 0 THEN 'PUBLIC'
                                  ELSE quote_ident(pg_get_userbyid(a.grantee)) END AS grantee
             FROM pg_default_acl d, aclexplode(d.defaclacl) a
             WHERE d.defaclobjtype = 'r'
             ORDER BY 1"
        ), 'grantee');

        foreach ($views as $diff) {
            $up   = self::read($source, $diff);
            $down = self::read($target, $diff);
            if ($up === null || $down === null) {
                continue;
            }
            $diff->privileges = [
                'up'              => $up['grants'],
                'down'            => $down['grants'],
                'owner'           => $down['owner'],
                'defaultGrantees' => $defaultGrantees,
            ];
        }
    }

    /** @return array{owner: string, grants: array}|null */
    private static function read(Connection $connection, object $diff): ?array {
        $name = PostgresSchemaHelper::qualifiedName($diff->schema ?? SchemaScope::of($connection), $diff->name);
        $row = $connection->selectOne(
            'SELECT quote_ident(pg_get_userbyid(c.relowner)) AS owner, '
            . PostgresAcl::grantsJson('c.relacl', 'c.relowner') . ' AS grants
               FROM pg_class c WHERE c.oid = to_regclass(?)',
            [$name]
        );
        if ($row === null) {
            return null;
        }
        return ['owner' => $row['owner'], 'grants' => json_decode((string) $row['grants'], true) ?? []];
    }
}
