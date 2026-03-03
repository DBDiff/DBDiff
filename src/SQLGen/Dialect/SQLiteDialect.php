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

    protected function changeColumnWarning(string $col): string {
        return "-- WARNING: column \"$col\" changed. SQLite requires DROP + ADD (data may be lost).";
    }
}
