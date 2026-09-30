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

        if ($oldIsIdentity && $newIsIdentity) {
            return $this->changeIdentityColumn($t, $c, $newDef, $oldDef);
        }

        if ($oldIsGenerated && $newIsGenerated) {
            return $this->changeGeneratedColumn($t, $c, $oldDef, $newDef);
        }

        return $this->changeRegularColumn($t, $c, $newDef, $oldDef, $oldIsIdentity, $oldIsGenerated);
    }

    private function changeIdentityColumn(string $t, string $c, string $newDef, string $oldDef): string {
        $newParts = self::parseColumnDef($newDef);
        preg_match('/GENERATED\s+(.*?)\s+AS\s+IDENTITY/i', $newDef, $m);
        $gen = $m[1] ?? 'BY DEFAULT';
        $stmts = [];
        $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP IDENTITY;";
        if (self::typeChanges($oldDef, $newParts['type'])) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c TYPE {$newParts['type']};";
        }
        $stmts[] = "ALTER TABLE $t ALTER COLUMN $c ADD GENERATED $gen AS IDENTITY;";
        return implode("\n", $stmts);
    }

    private function changeGeneratedColumn(string $t, string $c, string $oldDef, string $newDef): string {
        $oldParts = self::parseColumnDef($oldDef);
        $newParts = self::parseColumnDef($newDef);

        if ($oldParts['type'] !== $newParts['type']) {
            $colDef = preg_replace('/^"[^"]*"\s*/', '', $newDef);
            return "ALTER TABLE $t DROP COLUMN $c;\n"
                 . "ALTER TABLE $t ADD COLUMN $c $colDef;";
        }

        $stmts = [];
        if ($oldParts['not_null'] !== $newParts['not_null']) {
            $stmts[] = $newParts['not_null']
                ? "ALTER TABLE $t ALTER COLUMN $c SET NOT NULL;"
                : "ALTER TABLE $t ALTER COLUMN $c DROP NOT NULL;";
        }
        return implode("\n", $stmts ?: ["ALTER TABLE $t ALTER COLUMN $c DROP NOT NULL;"]);
    }

    private function changeRegularColumn(string $t, string $c, string $newDef, string $oldDef, bool $oldIsIdentity, bool $oldIsGenerated): string {
        $newParts = self::parseColumnDef($newDef);
        // When the previous definition is known, only assert NOT NULL / DEFAULT
        // when they actually change. This avoids emitting a redundant (and, for
        // primary-key or constraint-backed columns, invalid) DROP NOT NULL when
        // the column's nullability is unchanged — e.g. a plain type change on a
        // column whose NOT NULL is carried by a named constraint.
        $oldParts = ($oldDef !== '' && !$oldIsIdentity && !$oldIsGenerated)
            ? self::parseColumnDef($oldDef) : null;
        $stmts = [];

        if ($oldIsIdentity) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP IDENTITY;";
        } elseif ($oldIsGenerated) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c DROP EXPRESSION;";
        }

        // Only when the type actually changes. A column's definition differs
        // for many reasons — a default, nullability — and PostgreSQL refuses
        // `ALTER COLUMN ... TYPE` while a view or policy reads the column even
        // when the type named is the one it already has:
        //
        //     ERROR:  cannot alter type of a column used by a view or rule
        //
        // So a same-type TYPE made a default change on such a column fail, and
        // made AlterTableChangeColumnSQL drop and recreate the views around a
        // statement that changed no type at all.
        if (self::typeChanges($oldDef, $newParts['type'])) {
            $stmts[] = "ALTER TABLE $t ALTER COLUMN $c TYPE {$newParts['type']}"
                . self::usingClause($c, $oldDef, $newParts['type']) . ';';
        }

        if ($oldParts === null || $oldParts['not_null'] !== $newParts['not_null']) {
            $stmts[] = $newParts['not_null']
                ? "ALTER TABLE $t ALTER COLUMN $c SET NOT NULL;"
                : "ALTER TABLE $t ALTER COLUMN $c DROP NOT NULL;";
        }

        if ($oldParts === null || $oldParts['default'] !== $newParts['default']) {
            $stmts[] = $newParts['default'] !== null
                ? "ALTER TABLE $t ALTER COLUMN $c SET DEFAULT {$newParts['default']};"
                : "ALTER TABLE $t ALTER COLUMN $c DROP DEFAULT;";
        }

        return implode("\n", $stmts);
    }

    /**
     * `USING "col"::type` where PostgreSQL will not convert on its own.
     *
     * Without it, `ALTER COLUMN ... TYPE` converts only where an assignment
     * cast exists, and there is none from text to uuid, an integer, boolean,
     * json or a date — so the commonest retype of all, `user_id text` to
     * `uuid`, failed with "cannot be cast automatically".
     *
     * Only from a string type, and never to one. Casting *from* text parses
     * the value and fails loudly on anything it cannot read. An explicit cast
     * *to* `varchar(n)` would instead truncate silently where the assignment
     * cast refuses, and between other types an explicit cast can succeed
     * where the implicit one rightly would not — so those are left alone.
     */
    private static function usingClause(string $quotedColumn, string $oldDef, string $newType): string {
        if ($oldDef === '') {
            return '';
        }
        $isString = fn(string $type) => (bool) preg_match(
            '/^(text|character varying|varchar|character|char|bpchar|citext)\b/i', $type
        );
        $oldType = self::parseColumnDef($oldDef)['type'];
        if (!$isString($oldType) || $isString($newType)) {
            return '';
        }
        // The cast names the type without its collation.
        $castType = trim(preg_replace('/\s+COLLATE\s+.*$/i', '', $newType));
        return " USING $quotedColumn::$castType";
    }

    /**
     * Whether a column definition change includes a change of type.
     *
     * What decides whether the views, policies and triggers reading the column
     * have to be dropped around it (issue #226): only `ALTER COLUMN ... TYPE`
     * is refused with them in place.
     */
    public static function changesColumnType(string $oldDef, string $newDef): bool {
        return self::typeChanges($oldDef, self::parseColumnDef($newDef)['type']);
    }

    /**
     * Whether moving from `$oldDef` to a column of `$newType` changes its type.
     *
     * True when the old definition is unknown, so nothing is lost when there
     * is no previous definition to compare against.
     */
    private static function typeChanges(string $oldDef, string $newType): bool {
        if ($oldDef === '') {
            return true;
        }
        return self::parseColumnDef($oldDef)['type'] !== $newType;
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
