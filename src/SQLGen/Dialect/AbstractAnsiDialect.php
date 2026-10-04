<?php namespace DBDiff\SQLGen\Dialect;

use DBDiff\DB\Data\BinaryValue;
use DBDiff\DB\Data\ScalarText;

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

    use QualifiesNames;

    // ── Identifier quoting ───────────────────────────────────────────────────

    public function quote(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    // ── Literals ─────────────────────────────────────────────────────────────

    /**
     * Standard SQL: a quote inside a string is doubled, and a backslash is an
     * ordinary character. Binary data is a hex literal.
     */
    public function literal(mixed $value): string {
        return match (true) {
            $value === null               => 'NULL',
            $value instanceof BinaryValue => "X'" . $value->hex . "'",
            is_bool($value)               => $value ? '1' : '0',
            default                       => "'" . str_replace("'", "''", ScalarText::of($value)) . "'",
        };
    }

    public function insertRow(string $table, array $columns, array $literals, bool $overriding = false): string {
        return "INSERT INTO $table (" . implode(',', $columns) . ") VALUES(" . implode(',', $literals) . ");";
    }

    /** SQLite: the row's rowid picks one of several identical rows. */
    public function deleteOneRow(string $table, array $conditions): string {
        $where = implode(' AND ', $conditions);
        return "DELETE FROM $table WHERE rowid = (SELECT rowid FROM $table WHERE $where LIMIT 1);";
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
        $k = $this->qualify($key);
        return "DROP INDEX $k;";
    }

    public function dropTrigger(string $trigger, string $table): string {
        return "DROP TRIGGER IF EXISTS " . $this->quote($trigger) . ";";
    }

    public function addColumn(string $table, string $colDef): string {
        $t = $this->qualify($table);
        return "ALTER TABLE $t ADD COLUMN $colDef;";
    }

    public function dropColumn(string $table, string $col): string {
        $t = $this->qualify($table);
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
    public function changeColumn(string $table, string $col, string $newDef, string $oldDef = ''): string {
        $t = $this->qualify($table);
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

    public function dropConstraint(string $table, string $name, string $schema): string {
        $t = $this->qualify($table);
        $n = $this->quote($name);
        return "ALTER TABLE $t DROP CONSTRAINT $n;";
    }
}
