<?php namespace DBDiff\DB\Support;

use DBDiff\DB\Support\Concerns\CatalogText;
use Illuminate\Database\Connection;

/**
 * Foreign servers, user mappings and foreign tables, as SchemaObject diffs —
 * see PostgresCatalogObjects.
 */
final class PostgresForeignData {
    use CatalogText;

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
              WHERE " . self::ownedIn($connection, 'c.relnamespace', 'pg_class', 'c.oid') . "
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
