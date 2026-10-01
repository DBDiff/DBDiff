<?php namespace DBDiff\DB\Support;

/**
 * An identity column's sequence options, as PostgresSchemaHelper renders them
 * inside `AS IDENTITY (...)` — only those that differ from their defaults:
 * `INCREMENT BY 10 MINVALUE 5 MAXVALUE 500 START WITH 5 CYCLE`.
 *
 * Changing one is an `ALTER COLUMN ... SET <option>` on the existing identity,
 * which keeps the sequence where it is. Dropping and re-adding the identity —
 * what used to happen — restarted it (issue #236).
 */
final class IdentityOptions {

    private const KEYWORDS = ['INCREMENT BY', 'MINVALUE', 'MAXVALUE', 'START WITH'];

    /** The clause that puts each option back to its default, for one that is removed. */
    private const RESET = [
        'INCREMENT BY' => 'SET INCREMENT BY 1',
        'MINVALUE'     => 'SET NO MINVALUE',
        'MAXVALUE'     => 'SET NO MAXVALUE',
        'START WITH'   => 'SET START WITH 1',
        'CYCLE'        => 'SET NO CYCLE',
    ];

    /**
     * `INCREMENT BY 10 START WITH 5` → ['INCREMENT BY' => '10', 'START WITH' => '5'];
     * `CYCLE` is present with an empty value.
     *
     * @return array<string, string>
     */
    public static function parse(?string $options): array {
        $parsed = [];
        $options = (string) $options;
        foreach (self::KEYWORDS as $keyword) {
            $pattern = '/\b' . str_replace(' ', '\s+', $keyword) . '\s+(-?\d+)/i';
            if (preg_match($pattern, $options, $m)) {
                $parsed[$keyword] = $m[1];
            }
        }
        if (preg_match('/(?<!NO\s)\bCYCLE\b/i', $options)) {
            $parsed['CYCLE'] = '';
        }
        return $parsed;
    }

    /** Whether the sequence counts upwards — the default, and any positive increment. */
    public static function ascending(?string $options): bool {
        return (int) (self::parse($options)['INCREMENT BY'] ?? 1) > 0;
    }

    /**
     * The `SET ...` clauses taking an identity from `$from` options to `$to`.
     *
     * A removed START WITH is reset to 1, which is its default only for an
     * ascending sequence without a MINVALUE; a descending one's default depends
     * on its type, so it is left as it is.
     *
     * @return string[]
     */
    public static function changes(?string $from, ?string $to): array {
        $old = self::parse($from);
        $new = self::parse($to);
        $clauses = [];

        foreach ($new as $keyword => $value) {
            if (($old[$keyword] ?? null) !== $value) {
                $clauses[] = $keyword === 'CYCLE' ? 'SET CYCLE' : "SET $keyword $value";
            }
        }
        foreach (array_diff_key($old, $new) as $keyword => $_) {
            if ($keyword === 'START WITH' && (!self::ascending($to) || isset($new['MINVALUE']))) {
                continue;
            }
            $clauses[] = self::RESET[$keyword];
        }
        return $clauses;
    }
}
