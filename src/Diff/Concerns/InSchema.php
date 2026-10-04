<?php namespace DBDiff\Diff\Concerns;

/**
 * The PostgreSQL schema a change is in, set when a diff compares more than
 * `public`. Null otherwise, and for other engines.
 */
trait InSchema {
    public ?string $schema = null;
}
