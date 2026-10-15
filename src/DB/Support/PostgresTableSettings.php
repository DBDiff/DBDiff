<?php namespace DBDiff\DB\Support;

/**
 * A table's replica identity and per-column statistics targets: settings
 * CREATE TABLE has no clause for, so they trail it as ALTER TABLEs.
 */
final class PostgresTableSettings {

    /**
     * What follows `REPLICA IDENTITY`: DEFAULT, FULL, NOTHING or USING INDEX.
     * Logical replication — Supabase Realtime among it — sends a changed or
     * deleted row's old values only under FULL.
     */
    public static function replicaIdentity(string $code, ?string $index): string {
        return match ($code) {
            'f' => 'FULL',
            'n' => 'NOTHING',
            'i' => $index !== null ? 'USING INDEX "' . str_replace('"', '""', $index) . '"' : 'DEFAULT',
            default => 'DEFAULT',
        };
    }

    /**
     * Per-column statistics targets set with `ALTER COLUMN ... SET STATISTICS`.
     *
     * @return array<string, int>
     */
    public static function columnStatistics(array $attrByCol): array {
        $out = [];
        foreach ($attrByCol as $column => $attr) {
            $target = (int) ($attr['stattarget'] ?? -1);
            if ($target >= 0) {
                $out[$column] = $target;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The statements that put a new table's replica identity and statistics
     * targets as they are; `$table` arrives quoted.
     *
     * @return list<string>
     */
    public static function tableSettingStatements(string $table, string $replicaIdentity, array $statistics): array {
        $out = [];
        if ($replicaIdentity !== 'DEFAULT') {
            $out[] = "ALTER TABLE $table REPLICA IDENTITY $replicaIdentity";
        }
        foreach ($statistics as $column => $target) {
            $out[] = "ALTER TABLE $table ALTER COLUMN \"" . str_replace('"', '""', $column) . "\" SET STATISTICS $target";
        }
        return $out;
    }
}
