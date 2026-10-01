<?php namespace DBDiff\DB\Support;

/**
 * A PostgreSQL column definition, as PostgresSchemaHelper::columnDefinition()
 * renders it, taken apart:
 *
 *     "name" <type> [COLLATE "c"] [NOT NULL] [COMPRESSION m]
 *            [GENERATED {ALWAYS|BY DEFAULT} AS IDENTITY [(options)]
 *             | GENERATED ALWAYS AS (expr) STORED
 *             | DEFAULT expr]
 *
 * One parser for every place that needs a part of a definition — the
 * dialect deciding which ALTERs a change takes, the planning of generated
 * columns, the schema diff. Each had its own regular expressions, and they
 * disagreed: a compression clause read as part of the type, so a type change
 * on a compressed column emitted `TYPE varchar(20) COMPRESSION lz4`, which is
 * not SQL.
 *
 * Read with awareness of quotes and parentheses, so a default or generation
 * expression containing a keyword — `DEFAULT 'NOT NULL'` — is not mistaken
 * for one. `NOT NULL` is accepted after the default or the generation clause
 * as well as before it, which is how older renderings and hand-written
 * definitions put it.
 */
final class PostgresColumnDefinition {

    private const KEYWORDS = ['COLLATE', 'NOT', 'NULL', 'COMPRESSION', 'DEFAULT', 'GENERATED', 'STORAGE'];

    /** The serial spellings, and the integer type each stands for. */
    private const SERIALS = ['smallserial' => 'smallint', 'serial' => 'integer', 'bigserial' => 'bigint'];

    public string $name = '';
    /** The type as written, without collation: `numeric(10,2)`, `character varying(20)[]`. */
    public string $type = '';
    public ?string $collation = null;
    public bool $notNull = false;
    /** `lz4` or `pglz`; null for the server default. */
    public ?string $compression = null;
    public ?string $default = null;
    /** `ALWAYS` or `BY DEFAULT` for an identity column. */
    public ?string $identity = null;
    /** The identity's sequence options, without parentheses: `INCREMENT BY 10 START WITH 5`. */
    public ?string $identityOptions = null;
    /** The expression of a stored generated column, without its outer parentheses. */
    public ?string $generated = null;
    /**
     * A serial column — an integer column defaulting to its own sequence,
     * which PostgresSchemaHelper renders as `serial`. `type` holds the
     * integer type, and `notNull` is set, as serial implies.
     */
    public bool $serial = false;

    public static function parse(string $definition): self {
        $column = new self();
        $rest = trim($definition);

        if (preg_match('/^("(?:[^"]|"")*")\s*(.*)$/s', $rest, $m)) {
            $column->name = str_replace('""', '"', substr($m[1], 1, -1));
            $rest = $m[2];
        }

        $tokens = self::tokens($rest);
        $i = 0;
        $typeParts = [];
        while ($i < count($tokens) && !self::isKeyword($tokens[$i]['text'])) {
            $typeParts[] = $tokens[$i]['text'];
            $i++;
        }
        $column->type = implode(' ', $typeParts);
        $integer = self::SERIALS[strtolower($column->type)] ?? null;
        if ($integer !== null) {
            $column->serial  = true;
            $column->type    = $integer;
            $column->notNull = true;
        }

        while ($i < count($tokens)) {
            $i = $column->readClause($tokens, $i, $rest);
        }
        return $column;
    }

    public function isGenerated(): bool {
        return $this->generated !== null;
    }

    public function isIdentity(): bool {
        return $this->identity !== null;
    }

    /**
     * Whether going from `$fromDef` to `$toDef` needs the column dropped and
     * re-added rather than altered: the destination is a stored generated
     * column, and the origin is not one, or has another expression or type.
     * PostgreSQL can neither give an existing column an expression nor retype
     * a generated one in place on every supported version (issue #233).
     */
    public static function needsRegenerating(string $fromDef, string $toDef): bool {
        $to = self::parse($toDef);
        if (!$to->isGenerated()) {
            return false;
        }
        $from = self::parse($fromDef);
        return !$from->isGenerated()
            || $from->generated !== $to->generated
            || $from->typeWithCollation() !== $to->typeWithCollation();
    }

    /**
     * Whether one of the two definitions is serial and the other is not —
     * a change that needs the column's sequence by name.
     */
    public static function switchesSerial(string $a, string $b): bool {
        return self::parse($a)->serial !== self::parse($b)->serial;
    }

    /** The type with its collation — what `ALTER COLUMN ... TYPE` takes. */
    public function typeWithCollation(): string {
        return $this->type . ($this->collation !== null ? ' COLLATE "' . $this->collation . '"' : '');
    }

    /** One clause starting at token `$i`; returns the index after it. */
    private function readClause(array $tokens, int $i, string $source): int {
        $word = strtoupper($tokens[$i]['text']);
        $next = strtoupper($tokens[$i + 1]['text'] ?? '');

        if ($word === 'NOT' && $next === 'NULL') {
            $this->notNull = true;
            return $i + 2;
        }
        if ($word === 'COLLATE') {
            $this->collation = trim($tokens[$i + 1]['text'] ?? '', '"');
            return $i + 2;
        }
        if ($word === 'COMPRESSION') {
            $this->compression = strtolower($tokens[$i + 1]['text'] ?? '');
            return $i + 2;
        }
        if ($word === 'STORAGE') {
            return $i + 2;
        }
        if ($word === 'DEFAULT') {
            return $this->readUntilTrailingNotNull($tokens, $i + 1, $source, 'default');
        }
        if ($word === 'GENERATED') {
            return $this->readGenerated($tokens, $i + 1, $source);
        }
        return $i + 1;
    }

    /** `ALWAYS AS IDENTITY [(..)]`, `BY DEFAULT AS IDENTITY [(..)]` or `ALWAYS AS (expr) STORED`. */
    private function readGenerated(array $tokens, int $i, string $source): int {
        $kind = strtoupper($tokens[$i]['text'] ?? '');
        if ($kind === 'BY') {
            $kind = 'BY DEFAULT';
            $i++;
        }
        $i++;                                           // past ALWAYS / DEFAULT
        if (strtoupper($tokens[$i]['text'] ?? '') === 'AS') {
            $i++;
        }
        if (strtoupper($tokens[$i]['text'] ?? '') === 'IDENTITY') {
            $this->identity = $kind;
            $i++;
            if (isset($tokens[$i]) && str_starts_with($tokens[$i]['text'], '(')) {
                $this->identityOptions = trim(substr($tokens[$i]['text'], 1, -1));
                $i++;
            }
            return $i;
        }
        if (isset($tokens[$i]) && str_starts_with($tokens[$i]['text'], '(')) {
            $this->generated = trim(substr($tokens[$i]['text'], 1, -1));
            $i++;
            if (strtoupper($tokens[$i]['text'] ?? '') === 'STORED') {
                $i++;
            }
        }
        return $i;
    }

    /** The rest of the definition, less a trailing `NOT NULL`, as one expression. */
    private function readUntilTrailingNotNull(array $tokens, int $i, string $source, string $into): int {
        $end = count($tokens);
        if ($end - $i >= 2
            && strtoupper($tokens[$end - 2]['text']) === 'NOT'
            && strtoupper($tokens[$end - 1]['text']) === 'NULL') {
            $this->notNull = true;
            $end -= 2;
        }
        if ($end > $i) {
            $this->$into = trim(substr($source, $tokens[$i]['start'], $tokens[$end - 1]['end'] - $tokens[$i]['start']));
        }
        return count($tokens);
    }

    private static function isKeyword(string $token): bool {
        return in_array(strtoupper($token), self::KEYWORDS, true);
    }

    /**
     * Whitespace-separated tokens at the top level: a quoted string, a quoted
     * identifier or a parenthesised group is part of the token it is in.
     *
     * @return list<array{text: string, start: int, end: int}>
     */
    private static function tokens(string $s): array {
        $tokens = [];
        $len = strlen($s);
        $start = null;
        $depth = 0;
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    // A doubled quote is an escaped one.
                    if (($s[$i + 1] ?? '') === $quote) {
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $start ??= $i;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            if ($depth === 0 && ctype_space($ch)) {
                if ($start !== null) {
                    $tokens[] = ['text' => substr($s, $start, $i - $start), 'start' => $start, 'end' => $i];
                    $start = null;
                }
                continue;
            }
            $start ??= $i;
        }
        if ($start !== null) {
            $tokens[] = ['text' => substr($s, $start), 'start' => $start, 'end' => $len];
        }
        return $tokens;
    }
}
