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

    public function changeColumn(string $table, string $col, string $newDef): string {
        $t = $this->quote($table);
        $c = $this->quote($col);
        // MySQL CHANGE keeps the same column name; the newDef already contains
        // the backtick-quoted name as the first token.
        return "ALTER TABLE $t CHANGE $c $newDef;";
    }
}
