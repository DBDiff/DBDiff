<?php namespace DBDiff\SQLGen\Dialect;


class SQLiteDialect implements SQLDialectInterface {

    public function quote(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function getDriver(): string {
        return 'sqlite';
    }

    public function isMySQLOnly(): bool {
        return false;
    }

    public function dropIndex(string $table, string $key): string {
        // SQLite: DROP INDEX "name" (no ALTER TABLE qualifier)
        $k = $this->quote($key);
        return "DROP INDEX $k;";
    }

    public function changeColumn(string $table, string $col, string $newDef): string {
        // SQLite supports ALTER TABLE ... RENAME COLUMN (v3.25+) and
        // ALTER TABLE ... DROP COLUMN (v3.35+), but not type changes in a
        // single statement.  The safest universal approach is recreate the
        // column with a DROP + ADD pair and warn about potential data loss.
        $t = $this->quote($table);
        $c = $this->quote($col);

        $lines   = [];
        $lines[] = "-- WARNING: column \"$col\" changed. SQLite requires DROP + ADD (data may be lost).";
        $lines[] = "ALTER TABLE $t DROP COLUMN $c;";
        $lines[] = "ALTER TABLE $t ADD COLUMN $newDef;";
        return implode("\n", $lines);
    }
}
