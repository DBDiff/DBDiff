<?php namespace DBDiff\SQLGen\Dialect;

/**
 * SQLite dialect.
 *
 * Identifier quoting, ANSI ADD/DROP COLUMN syntax, and schema-level
 * DROP INDEX are all inherited from AbstractAnsiDialect.
 *
 * SQLite supports ALTER TABLE … DROP COLUMN only from v3.35+ and does
 * not support type changes at all, so the base-class DROP + ADD
 * approach is correct.  The warning comment is overridden here to
 * surface that SQLite-specific constraint to the DBA.
 */
class SQLiteDialect extends AbstractAnsiDialect {

    public function getDriver(): string {
        return 'sqlite';
    }

    /**
     * A value as SQLite reads it back with the same storage class.
     *
     * Quoted, every value was text: an integer in a column with no declared
     * type came back as the text '12', which no longer equals 12, and a STRICT
     * table's REAL column refused 'Infinity'. A blob, or text holding a NUL
     * byte, was written as raw bytes inside a string literal, which is not
     * text the migration file can hold. Integers and reals are written bare,
     * an infinity as a real past the largest double, NaN — which SQLite stores
     * as NULL — as NULL, bytes that are not UTF-8 text as X'…', and text
     * holding a NUL as its bytes cast to text. (A blob whose bytes happen to be
     * UTF-8 text cannot be told from text as fetched, and is written as text.)
     */
    public function literal(mixed $value): string {
        return match (true) {
            is_int($value)   => (string) $value,
            is_float($value) => match (true) {
                is_nan($value)      => 'NULL',
                is_infinite($value) => $value > 0 ? '9e999' : '-9e999',
                default             => var_export($value, true),
            },
            is_string($value) && !preg_match('//u', $value) => "X'" . bin2hex($value) . "'",
            is_string($value) && str_contains($value, "\0") => "CAST(X'" . bin2hex($value) . "' AS TEXT)",
            default => parent::literal($value),
        };
    }

    protected function changeColumnWarning(string $col): string {
        return "-- WARNING: column \"$col\" changed. SQLite requires DROP + ADD (data may be lost).";
    }
}
