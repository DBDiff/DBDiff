<?php namespace DBDiff\SQLGen\Dialect;

/**
 * Shared implementation for SQL dialects that follow ANSI/ISO SQL
 * conventions:
 *   - double-quote identifiers
 *   - DROP INDEX without an ALTER TABLE wrapper
 *   - ADD COLUMN / DROP COLUMN keywords
 *   - column changes expressed as DROP + ADD (with a data-loss warning)
 *
 * Concrete subclasses must implement getDriver() and may override
 * changeColumnWarning() to customise the warning comment emitted
 * when a column definition changes.
 */
abstract class AbstractAnsiDialect implements SQLDialectInterface {

    // ── Identifier quoting ───────────────────────────────────────────────────

    public function quote(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    // ── Dialect flags ────────────────────────────────────────────────────────

    public function isMySQLOnly(): bool {
        return false;
    }

    // ── DDL helpers ──────────────────────────────────────────────────────────

    /**
     * Postgres and SQLite both use schema-namespaced indexes,
     * so no ALTER TABLE wrapper is needed.
     */
    public function dropIndex(string $table, string $key): string {
        $k = $this->quote($key);
        return "DROP INDEX $k;";
    }

    public function dropTrigger(string $trigger, string $table): string {
        return "DROP TRIGGER IF EXISTS " . $this->quote($trigger) . ";";
    }

    public function addColumn(string $table, string $colDef): string {
        $t = $this->quote($table);
        return "ALTER TABLE $t ADD COLUMN $colDef;";
    }

    public function dropColumn(string $table, string $col): string {
        $t = $this->quote($table);
        $c = $this->quote($col);
        return "ALTER TABLE $t DROP COLUMN $c;";
    }

    /**
     * Emit a DROP + ADD pair for column definition changes.
     *
     * Neither Postgres nor SQLite supports mutating a column's type
     * in a single statement without risk of data loss, so the safest
     * portable approach is to drop and recreate.  Subclasses may
     * override changeColumnWarning() to customise the comment text.
     */
    public function changeColumn(string $table, string $col, string $newDef): string {
        $t = $this->quote($table);
        $c = $this->quote($col);

        return implode("\n", [
            $this->changeColumnWarning($col),
            "ALTER TABLE $t DROP COLUMN $c;",
            "ALTER TABLE $t ADD COLUMN $newDef;",
        ]);
    }

    /**
     * Returns the warning comment inserted before a column change.
     * Override in subclasses to add engine-specific context.
     *
     * @param string $col Bare (unquoted) column name.
     */
    protected function changeColumnWarning(string $col): string {
        return "-- WARNING: column \"$col\" changed; data may be lost.";
    }
}
