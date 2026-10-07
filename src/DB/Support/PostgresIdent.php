<?php namespace DBDiff\DB\Support;

/**
 * A PostgreSQL identifier, quoted.
 */
final class PostgresIdent {

    /**
     * `"name"`, with any quote inside it doubled. Written between plain
     * quotes, a name holding one (`Col "c"`) was a syntax error that failed
     * the whole migration.
     */
    public static function quote(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
