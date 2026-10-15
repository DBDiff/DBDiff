<?php namespace DBDiff\DB\Support;

use DBDiff\Logger;
use Illuminate\Database\Connection;

/**
 * The user's objects of kinds DBDiff does not compare, said out loud.
 *
 * A kind that is not read is not in the migration, and nothing used to say
 * so: a database whose operators or publications differed read as
 * identical. Each kind found on either side — the user's own, not built in
 * (oid below FirstNormalObjectId) or an extension's — is now named in a
 * warning, with how many each side has and a few names, so it can be
 * checked by hand. Kinds that belong to the database are read with
 * `public`, like the database-wide objects that are compared.
 */
final class PostgresUncompared {

    /** kind => [catalog, oid column, name expression, schema column or null] */
    private const KINDS = [
        'operator'                => ['pg_operator', 'o.oid', "o.oprname", 'o.oprnamespace', 'pg_operator o'],
        'operator class'          => ['pg_opclass', 'o.oid', 'o.opcname', 'o.opcnamespace', 'pg_opclass o'],
        'operator family'         => ['pg_opfamily', 'o.oid', 'o.opfname', 'o.opfnamespace', 'pg_opfamily o'],
        'text search parser'      => ['pg_ts_parser', 'o.oid', 'o.prsname', 'o.prsnamespace', 'pg_ts_parser o'],
        'text search template'    => ['pg_ts_template', 'o.oid', 'o.tmplname', 'o.tmplnamespace', 'pg_ts_template o'],
        'conversion'              => ['pg_conversion', 'o.oid', 'o.conname', 'o.connamespace', 'pg_conversion o'],
        'procedural language'     => ['pg_language', 'o.oid', 'o.lanname', null, 'pg_language o'],
        'transform'               => ['pg_transform', 'o.oid', "format_type(o.trftype, NULL) || ' for ' || (SELECT lanname FROM pg_language WHERE oid = o.trflang)", null, 'pg_transform o'],
        'access method'           => ['pg_am', 'o.oid', 'o.amname', null, 'pg_am o'],
        'publication'             => ['pg_publication', 'o.oid', 'o.pubname', null, 'pg_publication o'],
        'subscription'            => ['pg_subscription', 'o.oid', 'o.subname', null, 'pg_subscription o JOIN pg_database db ON db.oid = o.subdbid AND db.datname = current_database()'],
        'security label'          => ['pg_seclabel', 'o.objoid', "o.provider || ':' || o.objoid::text", null, 'pg_seclabel o'],
    ];

    /**
     * Warn about each uncompared kind either side has.
     *
     * @return array<string, array{source: string[], target: string[]}> what was found, for tests
     */
    public static function report(Connection $source, Connection $target): array {
        $found = [];
        foreach (['source' => $source, 'target' => $target] as $side => $connection) {
            foreach (self::find($connection) as $kind => $names) {
                $found[$kind][$side] = $names;
            }
        }
        ksort($found);
        foreach ($found as $kind => $sides) {
            $sides += ['source' => [], 'target' => []];
            $found[$kind] = $sides;
            $names = array_values(array_unique(array_merge($sides['source'], $sides['target'])));
            Logger::warning(sprintf(
                '%s objects are not compared (source %d, target %d%s) — check them by hand',
                ucfirst($kind),
                count($sides['source']),
                count($sides['target']),
                ': ' . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ', …' : '')
            ));
        }
        return $found;
    }

    /** @return array<string, string[]> kind => names */
    public static function find(Connection $connection): array {
        $database = SchemaScope::of($connection) === 'public';
        $parts = [];
        foreach (self::KINDS as $kind => [$catalog, $oid, $name, $schema, $from]) {
            if ($schema === null && !$database) {
                continue;
            }
            $inScope = $schema !== null ? "$schema = " . SchemaScope::oid($connection) : 'true';
            $parts[] = "SELECT " . self::literal($kind) . " AS kind, ($name)::text AS name
                          FROM $from
                         WHERE $oid >= 16384 AND $inScope
                           AND " . PostgresSchemaHelper::notExtensionMember($catalog, $oid);
        }
        $rows = $parts === [] ? [] : $connection->select(implode("\nUNION ALL\n", $parts) . "\nORDER BY 1, 2");
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[$row['kind']][] = $row['name'];
        }
        return $out;
    }

    private static function literal(string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
