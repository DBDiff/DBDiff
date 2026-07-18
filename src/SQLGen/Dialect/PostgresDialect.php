<?php namespace DBDiff\SQLGen\Dialect;

/**
 * PostgreSQL dialect.
 *
 * Overrides column-change logic to use ALTER COLUMN (TYPE, SET/DROP
 * NOT NULL, SET/DROP DEFAULT) instead of the ANSI DROP+ADD approach.
 */
class PostgresDialect extends AbstractAnsiDialect {

    public function getDriver(): string {
        return 'pgsql';
    }

    /**
     * PostgreSQL requires ON "table" for DROP TRIGGER.
     */
    public function dropTrigger(string $trigger, string $table): string {
        return "DROP TRIGGER IF EXISTS " . $this->quote($trigger) . " ON " . $this->quote($table) . ";";
    }

    /**
     * Detect sequence-backed defaults (SERIAL columns) and create the
     * sequence before adding the column.
     */
    public function addColumn(string $table, string $colDef): string {
        if (preg_match("/DEFAULT\s+nextval\('([^']+)'::regclass\)/i", $colDef, $m)) {
            $seqName = $m[1];
            $t = $this->quote($table);
            $create = "CREATE SEQUENCE IF NOT EXISTS \"$seqName\";\n";
            return $create . "ALTER TABLE $t ADD COLUMN $colDef;";
        }
        return parent::addColumn($table, $colDef);
    }

    /**
     * Drop sequence when dropping a SERIAL column.
     */
    public function dropColumn(string $table, string $col): string {
        $t = $this->quote($table);
        $c = $this->quote($col);
        return "ALTER TABLE $t DROP COLUMN $c CASCADE;";
    }

    /**
     * Generate ALTER COLUMN statements for Postgres instead of DROP+ADD.
     *
     * Parses the old and new column definition strings (from fetchColumns)
     * and emits the minimal set of ALTER COLUMN sub-statements.
     */
    public function changeColumn(string $table, string $col, string $newDef, string $oldDef = ''): string {
        $t = $this->quote($table);
        $c = $this->quote($col);

        $oldIsIdentity  = $oldDef !== '' && preg_match('/GENERATED\s+.*AS\s+IDENTITY/i', $oldDef);
        $newIsIdentity  = (bool) preg_match('/GENERATED\s+.*AS\s+IDENTITY/i', $newDef);
        $oldIsGenerated = $oldDef !== '' && preg_match('/GENERATED\s+ALWAYS\s+AS\s+\(.+\)\s+STORED/i', $oldDef);
        $newIsGenerated = (bool) preg_match('/GENERATED\s+ALWAYS\s+AS\s+\(.+\)\s+STORED/i', $newDef);

        // Both old and new are identity — drop identity, change type, re-add identity
        if ($oldIsIdentity && $newIsIdentity) {
            $newParts = self::parseColumnDef($newDef);
            preg_match('/GENERATED\s+(.*?)\s+AS\s+IDENTITY/i', $newDef, $m);
            $gen = $m[1] ?? 'BY DEFAULT';
            $stmts = [];
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP IDENTITY;";
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c TYPE {$newParts['type']};";
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c ADD GENERATED $gen AS IDENTITY;";
            return implode("\n", $stmts);
        }

        // Both old and new are generated stored
        if ($oldIsGenerated && $newIsGenerated) {
            $oldParts = self::parseColumnDef($oldDef);
            $newParts = self::parseColumnDef($newDef);

            // Type change on a generated column: DROP + re-ADD to release dependencies
            if ($oldParts['type'] !== $newParts['type']) {
                // Strip column name from newDef for ADD COLUMN
                $colDef = preg_replace('/^"[^"]*"\s*/', '', $newDef);
                return "ALTER TABLE $t DROP COLUMN $c;\n"
                     . "ALTER TABLE $t ADD COLUMN $c $colDef;";
            }
            // Nullability-only change
            $stmts = [];
            if ($oldParts['not_null'] !== $newParts['not_null']) {
                $stmts[] = $newParts['not_null']
                    ? "ALTER TABLE $t ALTER COLUMN $c SET NOT NULL;"
                    : "ALTER TABLE $t ALTER COLUMN $c DROP NOT NULL;";
            }
            return implode("\n", $stmts ?: ["ALTER TABLE $t ALTER COLUMN $c DROP NOT NULL;"]);
        }

        $newParts = self::parseColumnDef($newDef);
        $stmts = [];

        if ($oldIsIdentity) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP IDENTITY;";
        } elseif ($oldIsGenerated) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP EXPRESSION;";
        }

        $stmts[] = "ALTER TABLE $t ALTER COLUMN $c TYPE {$newParts['type']};";

        if ($newParts['not_null']) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c SET NOT NULL;";
        } else {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP NOT NULL;";
        }

        if ($newParts['default'] !== null) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c SET DEFAULT {$newParts['default']};";
        } else {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP DEFAULT;";
        }

        return implode("\n", $stmts);
    }

    /**
     * Parse a column definition string like:
     *   "col_name" integer NOT NULL DEFAULT 42
     * into type, nullability, and default components.
     */
    private static function parseColumnDef(string $def): array {
        // Strip leading quoted column name: "col_name" <rest>
        $rest = preg_replace('/^"[^"]*"\s*/', '', $def);

        $notNull = false;
        $default = null;

        // Strip GENERATED ... AS IDENTITY or GENERATED ALWAYS AS (...) STORED clauses
        $rest = preg_replace('/\s+GENERATED\s+.*AS\s+IDENTITY.*/i', '', $rest);
        $rest = preg_replace('/\s+GENERATED\s+ALWAYS\s+AS\s+\(.+\)\s+STORED/i', '', $rest);

        // Extract DEFAULT clause (may contain complex expressions)
        if (preg_match('/\bDEFAULT\s+(.+)$/i', $rest, $m)) {
            $defaultExpr = $m[1];
            // Remove trailing NOT NULL from default expression if present
            $defaultExpr = preg_replace('/\s+NOT\s+NULL\s*$/i', '', $defaultExpr);
            $default = trim($defaultExpr);
            $rest = substr($rest, 0, $m[0] ? strpos($rest, $m[0]) : strlen($rest));
        }

        if (preg_match('/\bNOT\s+NULL\b/i', $rest) || preg_match('/\bNOT\s+NULL\b/i', $def)) {
            $notNull = true;
        }

        // Remove NOT NULL and DEFAULT ... from the rest to get the type
        $type = preg_replace('/\s+NOT\s+NULL\b/i', '', $rest);
        $type = preg_replace('/\s+DEFAULT\s+.*/i', '', $type);
        $type = trim($type);

        return [
            'type'     => $type,
            'not_null' => $notNull,
            'default'  => $default,
        ];
    }
}
