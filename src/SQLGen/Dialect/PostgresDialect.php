<?php namespace DBDiff\SQLGen\Dialect;

use DBDiff\DB\Support\PostgresColumnDefinition as Column;
use DBDiff\DB\Support\IdentityOptions;

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

    /** See withSerialSequence(). */
    private ?string $serialSequence = null;

    /**
     * This dialect, naming the sequence of a column becoming or ceasing to be
     * serial for changeColumn() — read from the database by the schema diff
     * (AlterTableChangeColumn::$serialSequence), since the definitions do
     * not carry it.
     */
    public function withSerialSequence(?string $sequence): static {
        $copy = clone $this;
        $copy->serialSequence = $sequence;
        return $copy;
    }

    /**
     * The ALTER COLUMN statements that take a column from `$oldDef` to `$newDef`.
     *
     * Only what differs is emitted, each part read by PostgresColumnDefinition:
     * type, nullability, default, compression, and an identity's kind and
     * options. A stored generated column whose expression changes never comes
     * here — it is dropped and re-added (GeneratedColumnPlan).
     */
    public function changeColumn(string $table, string $col, string $newDef, string $oldDef = ''): string {
        $t   = $this->quote($table);
        $c   = $this->quote($col);
        $new = Column::parse($newDef);
        $old = $oldDef !== '' ? Column::parse($oldDef) : null;
        $alter = fn(string $clause) => "ALTER TABLE $t ALTER COLUMN $c $clause;";

        $stmts = [];
        if ($old?->isIdentity() && !$new->isIdentity()) {
            $stmts[] = $alter('DROP IDENTITY');
        } elseif ($old?->isGenerated() && !$new->isGenerated()) {
            $stmts[] = $alter('DROP EXPRESSION');
        }

        // Only when the type actually changes. PostgreSQL refuses
        // `ALTER COLUMN ... TYPE` while a view or policy reads the column even
        // when the type named is the one it already has, so restating it made
        // a default change on such a column fail (issue #226).
        $retyped = self::typeChanges($old, $new);
        if ($retyped) {
            $stmts[] = $alter('TYPE ' . $new->typeWithCollation() . self::usingClause($c, $old, $new));
        }
        if ($old === null || $old->notNull !== $new->notNull) {
            $stmts[] = $alter($new->notNull ? 'SET NOT NULL' : 'DROP NOT NULL');
        }
        $serialSwitch = $old !== null && $old->serial !== $new->serial;
        if (($old === null || $old->default !== $new->default || ($serialSwitch && $old->serial))
            && !$new->isGenerated()) {
            $stmts[] = $alter($new->default !== null ? "SET DEFAULT {$new->default}" : 'DROP DEFAULT');
        }
        if ($serialSwitch) {
            array_push($stmts, ...$this->serialChange($t, $c, $new, $this->serialSequence ?? self::serialSequenceName($table, $col)));
        }
        // A type change resets compression to the server default, so a
        // compressed column is set again after one, not only when the method
        // itself differs (issue #225).
        if ($old !== null && ($old->compression !== $new->compression || ($retyped && $new->compression !== null))) {
            $stmts[] = $alter('SET COMPRESSION ' . ($new->compression ?? 'default'));
        }
        if ($new->isIdentity()) {
            array_push($stmts, ...$this->identityChange($t, $c, $old, $new, $alter));
        }

        return implode("\n", $stmts);
    }

    /**
     * A column becoming serial gets a sequence of its own, owned by it and
     * started past the values already there; one ceasing to be serial loses
     * its sequence once the default no longer reads it. Before an identity is
     * added, so that `pg_get_serial_sequence` then finds only the identity's.
     *
     * @return string[]
     */
    private function serialChange(string $t, string $c, Column $new, string $sequence): array {
        if (!$new->serial) {
            return ["DROP SEQUENCE IF EXISTS $sequence;"];
        }
        $literal = "'" . str_replace("'", "''", $sequence) . "'";
        return [
            "CREATE SEQUENCE IF NOT EXISTS $sequence AS {$new->type};",
            "ALTER SEQUENCE $sequence OWNED BY $t.$c;",
            "ALTER TABLE $t ALTER COLUMN $c SET DEFAULT nextval($literal::regclass);",
            "SELECT setval($literal, COALESCE(MAX($c), 0) + 1, false) FROM $t;",
        ];
    }

    /** PostgreSQL's own name for a serial column's sequence, where none was read. */
    private static function serialSequenceName(string $table, string $col): string {
        return '"' . str_replace('"', '""', "{$table}_{$col}_seq") . '"';
    }

    /**
     * An identity kept, changed or added — never dropped and re-added, which
     * restarted its sequence: the next insert collided with existing rows
     * (issue #236).
     *
     * @return string[]
     */
    private function identityChange(string $t, string $c, ?Column $old, Column $new, callable $alter): array {
        if (!$old?->isIdentity()) {
            // A plain column becoming one: its new sequence starts past the
            // values already there, or the next insert would reuse one.
            $options = $new->identityOptions !== null ? " ({$new->identityOptions})" : '';
            $stmts = [$alter("ADD GENERATED {$new->identity} AS IDENTITY$options")];
            if (IdentityOptions::ascending($new->identityOptions)) {
                $stmts[] = "SELECT setval(pg_get_serial_sequence('" . str_replace("'", "''", $t) . "', '"
                    . str_replace("'", "''", trim($c, '"')) . "'), COALESCE(MAX($c), 0) + 1, false) FROM $t;";
            }
            return $stmts;
        }

        $stmts = [];
        if ($old->identity !== $new->identity) {
            $stmts[] = $alter("SET GENERATED {$new->identity}");
        }
        foreach (IdentityOptions::changes($old->identityOptions, $new->identityOptions) as $clause) {
            $stmts[] = $alter($clause);
        }
        return $stmts;
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
    private static function usingClause(string $quotedColumn, ?Column $old, Column $new): string {
        if ($old === null || !self::isStringType($old->type) || self::isStringType($new->type)) {
            return '';
        }
        return " USING $quotedColumn::{$new->type}";
    }

    private static function isStringType(string $type): bool {
        return (bool) preg_match('/^(text|character varying|varchar|character|char|bpchar|citext)\b/i', $type);
    }

    /**
     * Whether a column definition change includes a change of type.
     *
     * What decides whether the views, policies and triggers reading the column
     * have to be dropped around it (issue #226): only `ALTER COLUMN ... TYPE`
     * is refused with them in place.
     */
    public static function changesColumnType(string $oldDef, string $newDef): bool {
        return self::typeChanges($oldDef !== '' ? Column::parse($oldDef) : null, Column::parse($newDef));
    }

    /** True when the old definition is unknown, so nothing is lost without one. */
    private static function typeChanges(?Column $old, Column $new): bool {
        return $old === null || $old->typeWithCollation() !== $new->typeWithCollation();
    }
}
