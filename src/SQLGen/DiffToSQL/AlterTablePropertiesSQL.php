<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\Diff\AlterTableProperties;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\SQLGenInterface;

class AlterTablePropertiesSQL implements SQLGenInterface {
    private string $table;
    private array $source;
    private array $target;

    public function __construct(AlterTableProperties $diff) {
        $this->table  = $diff->table;
        $this->source = $diff->source;
        $this->target = $diff->target;
    }

    public function getUp(): string {
        return $this->statements($this->target, $this->source);
    }

    public function getDown(): string {
        return $this->statements($this->source, $this->target);
    }

    private function statements(array $from, array $to): string {
        $dialect = DialectRegistry::get();
        if ($dialect->getDriver() !== 'pgsql') {
            return '';
        }

        $table = $dialect->quote($this->table);
        $statements = [];

        if (($from['unlogged'] ?? false) !== ($to['unlogged'] ?? false)) {
            $mode = ($to['unlogged'] ?? false) ? 'UNLOGGED' : 'LOGGED';
            $statements[] = "ALTER TABLE $table SET $mode";
        }

        $fromOptions = $from['reloptions'] ?? [];
        $toOptions = $to['reloptions'] ?? [];
        $set = [];
        foreach ($toOptions as $name => $value) {
            if (!array_key_exists($name, $fromOptions) || $fromOptions[$name] !== $value) {
                $set[] = "$name = $value";
            }
        }
        $reset = array_diff_key($fromOptions, $toOptions);

        if ($set !== []) {
            $statements[] = "ALTER TABLE $table SET (" . implode(', ', $set) . ')';
        }
        if ($reset !== []) {
            $statements[] = "ALTER TABLE $table RESET (" . implode(', ', array_keys($reset)) . ')';
        }

        return $statements === [] ? '' : implode(";\n", $statements) . ';';
    }
}
