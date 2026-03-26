<?php namespace DBDiff\SQLGen\Dialect;


class MySQLDialect implements SQLDialectInterface {

    public function quote(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function getDriver(): string {
        return 'mysql';
    }

    public function isMySQLOnly(): bool {
        return true;
    }

    public function dropIndex(string $table, string $key): string {
        $t = $this->quote($table);
        $k = $this->quote($key);
        return "ALTER TABLE $t DROP INDEX $k;";
    }

    public function dropTrigger(string $trigger, string $table): string {
        return "DROP TRIGGER IF EXISTS " . $this->quote($trigger) . ";";
    }

    /**
     * MySQL uses ADD without the COLUMN keyword for backwards-compat.
     * Both forms are valid MySQL SQL; the shorter form matches pre-existing
     * migration baselines and expected test files.
     */
    public function addColumn(string $table, string $colDef): string {
        $t = $this->quote($table);
        return "ALTER TABLE $t ADD $colDef;";
    }

    public function dropColumn(string $table, string $col): string {
        $t = $this->quote($table);
        $c = $this->quote($col);
        return "ALTER TABLE $t DROP $c;";
    }

    public function changeColumn(string $table, string $col, string $newDef): string {
        $t = $this->quote($table);
        $c = $this->quote($col);
        // MySQL CHANGE keeps the same column name; the newDef already contains
        // the backtick-quoted name as the first token.
        return "ALTER TABLE $t CHANGE $c $newDef;";
    }
}
