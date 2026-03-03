<?php namespace DBDiff\SQLGen\Dialect;


class PostgresDialect implements SQLDialectInterface {

    public function quote(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function getDriver(): string {
        return 'pgsql';
    }

    public function isMySQLOnly(): bool {
        return false;
    }

    public function dropIndex(string $table, string $key): string {
        // In Postgres, indexes live in the schema namespace; no table qualifier needed.
        $k = $this->quote($key);
        return "DROP INDEX $k;";
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

    public function changeColumn(string $table, string $col, string $newDef): string {
        $t = $this->quote($table);
        $c = $this->quote($col);

        // Postgres does not have a single CHANGE COLUMN like MySQL.
        // We extract the type+constraint portion of $newDef (everything after the
        // leading quoted identifier) and use ALTER COLUMN TYPE.
        // For a rename+type change a DROP/ADD is safest and most explicit.
        $typePart = preg_replace('/^"[^"]+" /', '', $newDef);

        // If only the type part changed we could use ALTER TYPE, but the safest
        // general-purpose approach is DROP then ADD.  Mark data loss explicitly
        // so the DBA can review.
        $lines   = [];
        $lines[] = "-- WARNING: column \"$col\" changed; data may be lost.";
        $lines[] = "ALTER TABLE $t DROP COLUMN $c;";
        $lines[] = "ALTER TABLE $t ADD COLUMN $newDef;";
        return implode("\n", $lines);
    }
}
