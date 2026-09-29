<?php namespace DBDiff\SQLGen\DiffToSQL;

use DBDiff\SQLGen\SQLGenInterface;
use DBDiff\SQLGen\Dialect\DialectRegistry;
use DBDiff\SQLGen\Dialect\SQLDialectInterface;


/**
 * Storage parameters, set and reset one at a time.
 *
 * `SET (...)` only touches the options named in it, and `RESET (...)` returns
 * one to its default — so moving from one set of options to another means
 * setting what differs and resetting what is no longer there. Replacing the
 * whole list is not something PostgreSQL offers.
 */
class AlterTableOptionsSQL implements SQLGenInterface {

    protected $obj;
    protected SQLDialectInterface $dialect;

    public function __construct($obj, SQLDialectInterface $dialect = null) {
        $this->obj     = $obj;
        $this->dialect = $dialect ?? DialectRegistry::get();
    }

    public function getUp(): string {
        return $this->transition($this->obj->prevOptions, $this->obj->options);
    }

    public function getDown(): string {
        return $this->transition($this->obj->options, $this->obj->prevOptions);
    }

    /** SQL taking the table from the `$from` options to the `$to` options. */
    private function transition(?string $from, ?string $to): string {
        $fromOptions = self::parse($from);
        $toOptions   = self::parse($to);

        $t     = $this->dialect->quote($this->obj->table);
        $lines = [];

        $set = [];
        foreach ($toOptions as $name => $value) {
            if (($fromOptions[$name] ?? null) !== $value) {
                $set[] = "$name = $value";
            }
        }
        if ($set !== []) {
            $lines[] = "ALTER TABLE $t SET (" . implode(', ', $set) . ');';
        }

        $reset = [];
        foreach ($fromOptions as $name => $_) {
            if (!array_key_exists($name, $toOptions)) {
                $reset[] = $name;
            }
        }
        if ($reset !== []) {
            $lines[] = "ALTER TABLE $t RESET (" . implode(', ', $reset) . ');';
        }

        return implode("\n", $lines);
    }

    /**
     * `fillfactor = 70, autovacuum_enabled = false` → [name => value].
     *
     * @return array<string, string>
     */
    public static function parse(?string $options): array {
        if ($options === null || trim($options) === '') {
            return [];
        }

        $parsed = [];
        foreach (explode(',', $options) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $parsed[trim($parts[0])] = trim($parts[1]);
        }

        return $parsed;
    }
}
