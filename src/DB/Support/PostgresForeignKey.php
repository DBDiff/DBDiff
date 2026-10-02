<?php namespace DBDiff\DB\Support;

/**
 * What a foreign key references, rendered from a constraint row as the bulk
 * schema fetch reads it (PostgresAdapter::getBulkTableSchema).
 */
final class PostgresForeignKey {

    /** `"schema"."table" ("a", "b")` — the part after REFERENCES. */
    public static function references(array $c): string {
        return self::table($c) . ' (' . self::columns($c) . ')';
    }

    /**
     * The table a foreign key references, qualified when it is outside
     * public: a key onto `auth.users` rendered as `REFERENCES "users"`, which
     * does not exist anywhere the migration runs.
     */
    private static function table(array $c): string {
        $table  = '"' . str_replace('"', '""', $c['foreign_table']) . '"';
        $schema = $c['foreign_schema'] ?? 'public';
        return $schema === 'public' ? $table : '"' . str_replace('"', '""', $schema) . '".' . $table;
    }

    /** The referenced columns, all of them, in key order. */
    private static function columns(array $c): string {
        $columns = isset($c['foreign_columns']) ? json_decode((string) $c['foreign_columns'], true) : null;
        if (!is_array($columns) || $columns === []) {
            $columns = isset($c['foreign_column']) ? [$c['foreign_column']] : [];
        }
        return implode(', ', array_map(fn($col) => '"' . str_replace('"', '""', $col) . '"', $columns));
    }
}
