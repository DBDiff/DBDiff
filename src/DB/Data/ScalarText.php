<?php namespace DBDiff\DB\Data;

/**
 * The text of a fetched scalar, for writing it back as an SQL literal.
 *
 * A float cast with (string) is rounded to the `precision` ini setting — 14
 * digits — so a double holding more lost them on the way into the migration.
 * var_export() gives the shortest text that reads back as the same double.
 * NaN and the infinities are spelt the way the servers read them.
 */
final class ScalarText
{
    public static function of(mixed $value): string
    {
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }
            if (is_infinite($value)) {
                return $value > 0 ? 'Infinity' : '-Infinity';
            }
            return var_export($value, true);
        }
        return (string) $value;
    }
}
