<?php namespace DBDiff\SQLGen\Dialect;

/**
 * PostgreSQL dialect.
 *
 * Identifier quoting, ANSI ADD/DROP COLUMN syntax, and schema-level
 * DROP INDEX are all inherited from AbstractAnsiDialect.  Only the
 * PDO driver string is specific to this class.
 */
class PostgresDialect extends AbstractAnsiDialect {

    public function getDriver(): string {
        return 'pgsql';
    }

    /**
     * PostgreSQL requires ON "table" for DROP TRIGGER.
     */
    public function dropTrigger(string $trigger, string $table): string {
        return "DROP TRIGGER IF EXISTS " . $this->quote($trigger) . " ON " . $this->quote($table) . ";";
    }
}
