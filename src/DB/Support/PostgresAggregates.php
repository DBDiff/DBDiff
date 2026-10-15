<?php namespace DBDiff\DB\Support;

use DBDiff\DB\Support\Concerns\CatalogText;
use Illuminate\Database\Connection;

/** Aggregates, as SchemaObject diffs — see PostgresCatalogObjects. */
final class PostgresAggregates {
    use CatalogText;

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
              WHERE " . self::ownedIn($connection, 'p.pronamespace', 'pg_proc', 'p.oid') . "
              ORDER BY 1"
        );
        $out = [];
        foreach ($rows as $row) {
            $row  = (array) $row;
            $spec = json_decode((string) $row['spec'], true);
            $fn   = json_decode((string) ($row['functions'] ?? 'null'), true) ?? [];
            $options = array_merge(
                ['SFUNC = ' . $fn['aggtransfn'], 'STYPE = ' . $row['stype']],
                self::stateOptions($spec, $fn),
                self::movingOptions($row, $spec, $fn),
                self::set([
                    'SORTOP'   => $row['sortop'],
                    'PARALLEL' => ['s' => 'SAFE', 'r' => 'RESTRICTED'][$row['parallel']] ?? null,
                ]),
                ($spec['aggkind'] ?? 'n') === 'h' ? ['HYPOTHETICAL'] : []
            );
            $name = SchemaScope::name($connection, $row['name']);
            $out[$row['key']] = [
                'create' => "CREATE AGGREGATE $name(" . $row['args'] . ') (' . implode(', ', $options) . ')',
                'drop'   => "DROP AGGREGATE IF EXISTS $name(" . $row['identity'] . ')',
            ];
        }
        return $out;
    }

    /** The options for the plain state: its size, final function, combine and (de)serial functions and start. */
    private static function stateOptions(array $spec, array $fn): array {
        return array_merge(
            self::set([
                'SSPACE'    => (int) ($spec['aggtransspace'] ?? 0) ?: null,
                'FINALFUNC' => $fn['aggfinalfn'] ?? null,
            ]),
            isset($fn['aggfinalfn']) ? self::finalOptions('FINALFUNC', $spec['aggfinalextra'] ?? false, $spec['aggfinalmodify'] ?? null) : [],
            self::set([
                'COMBINEFUNC'  => $fn['aggcombinefn'] ?? null,
                'SERIALFUNC'   => $fn['aggserialfn'] ?? null,
                'DESERIALFUNC' => $fn['aggdeserialfn'] ?? null,
                'INITCOND'     => isset($spec['agginitval']) ? self::literal($spec['agginitval']) : null,
                'MSFUNC'       => $fn['aggmtransfn'] ?? null,
                'MINVFUNC'     => $fn['aggminvtransfn'] ?? null,
            ])
        );
    }

    /** The options for the moving-aggregate state, which only an aggregate with MSFUNC has. */
    private static function movingOptions(array $row, array $spec, array $fn): array {
        if (!isset($fn['aggmtransfn'])) {
            return [];
        }
        return array_merge(
            self::set([
                'MSTYPE'     => $row['mstype'],
                'MSSPACE'    => (int) ($spec['aggmtransspace'] ?? 0) ?: null,
                'MFINALFUNC' => $fn['aggmfinalfn'] ?? null,
            ]),
            isset($fn['aggmfinalfn']) ? self::finalOptions('MFINALFUNC', $spec['aggmfinalextra'] ?? false, $spec['aggmfinalmodify'] ?? null) : [],
            self::set(['MINITCOND' => isset($spec['aggminitval']) ? self::literal($spec['aggminitval']) : null])
        );
    }

    /** FINALFUNC_EXTRA and FINALFUNC_MODIFY, or their moving-state twins. */
    private static function finalOptions(string $prefix, $extra, ?string $modify): array {
        return array_merge(
            empty($extra) ? [] : [$prefix . '_EXTRA'],
            self::set([$prefix . '_MODIFY' => ['r' => 'READ_ONLY', 's' => 'SHAREABLE', 'w' => 'READ_WRITE'][$modify ?? ''] ?? null])
        );
    }

    /** `OPTION = value` for each option that is set, in order. */
    private static function set(array $options): array {
        $out = [];
        foreach ($options as $option => $value) {
            if ($value !== null && $value !== '' && $value !== 0) {
                $out[] = "$option = $value";
            }
        }
        return $out;
    }
}
