<?php namespace DBDiff\DB\Support;

use DBDiff\DB\Support\Concerns\CatalogText;
use Illuminate\Database\Connection;

/** Text search dictionaries and configurations, as SchemaObject diffs — see PostgresCatalogObjects. */
final class PostgresTextSearch {
    use CatalogText;

    /** Text search dictionaries: a template and its options. */
    public static function textSearchDictionaries(Connection $connection): array {
        $rows = $connection->select(
            "SELECT d.dictname AS name,
                    (SELECT quote_ident(n.nspname) || '.' || quote_ident(t.tmplname) FROM pg_ts_template t
                       JOIN pg_namespace n ON n.oid = t.tmplnamespace WHERE t.oid = d.dicttemplate) AS template,
                    d.dictinitoption AS options
               FROM pg_ts_dict d
              WHERE " . self::ownedIn($connection, 'd.dictnamespace', 'pg_ts_dict', 'd.oid') . "
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
              WHERE " . self::ownedIn($connection, 'c.cfgnamespace', 'pg_ts_config', 'c.oid') . "
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
}
