<?php namespace DBDiff\Diff;

class AlterTableProperties {
    public string $table;
    public array $source;
    public array $target;

    public function __construct(string $table, array $source, array $target) {
        $this->table  = $table;
        $this->source = $source;
        $this->target = $target;
    }
}
