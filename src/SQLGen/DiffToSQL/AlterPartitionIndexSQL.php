<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;

class AlterPartitionIndexSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, ?SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->change($this->obj->targetIndex, $this->obj->sourceIndex);
    }

    public function getDown(): string {
        return $this->change($this->obj->sourceIndex, $this->obj->targetIndex);
    }

    /**
     * @param array{name: string, options: ?string} $from
     * @param array{name: string, options: ?string} $to
     */
    private function change(array $from, array $to): string {
        $out = [];
        if ($from['name'] !== $to['name']) {
            $out[] = 'ALTER INDEX ' . $this->dialect->qualify($from['name']) . ' RENAME TO ' . $this->dialect->quote($to['name']) . ';';
        }
        $index = $this->dialect->qualify($to['name']);
        $fromOptions = self::options($from['options']);
        $toOptions   = self::options($to['options']);
        $reset = array_diff_key($fromOptions, $toOptions);
        if ($reset !== []) {
            $out[] = "ALTER INDEX $index RESET (" . implode(', ', array_keys($reset)) . ');';
        }
        $set = array_diff_assoc($toOptions, $fromOptions);
        if ($set !== []) {
            $out[] = "ALTER INDEX $index SET (" . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($set), $set)) . ');';
        }
        return implode("\n", $out);
    }

    /** @return array<string, string> `fastupdate=false, pages_per_range=32` as name => value. */
    private static function options(?string $options): array {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $options))) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[$name] = $value;
        }
        return $out;
    }
}
