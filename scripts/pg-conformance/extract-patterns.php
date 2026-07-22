<?php
/**
 * Extract testable DDL pattern pairs from Postgres regression SQL files.
 *
 * Scans regression .sql files sequentially, tracking cumulative table state so
 * that each extracted pattern is self-contained (its before_sql includes all
 * prerequisite columns/constraints from earlier ALTERs).
 *
 * Each pair is a self-contained test: create db1 from "before", create db2
 * from "before + alteration", run dbdiff, apply UP to db1, assert match.
 *
 * When PGCONF_DSN is set, every statement is validated against a real Postgres
 * (via PDO, the same extension the runner uses) so before_sql is always valid
 * and error tests are classified by actual PG behaviour — no silent skips and
 * no extra language/runtime dependency.
 *
 * Output: JSON array of test cases to tests/pg-conformance/patterns.json
 */

const PATTERNS_DIR = __DIR__ . '/../../tests/pg-conformance';

// Statements never applied to the scratch DB: DML (the harness never loads
// data) plus session/role/config management. Skipping role changes keeps the
// whole replay running as the harness's own superuser, so a statement's
// success here matches what the harness will see.
const SKIP_RE =
    '#^\s*(INSERT|UPDATE|DELETE|SELECT|COPY|VALUES|WITH|TABLE|EXPLAIN|ANALYZE'
    . '|VACUUM|CHECKPOINT|CLUSTER|LOCK|LISTEN|NOTIFY|UNLISTEN|MOVE|FETCH'
    . '|PREPARE|EXECUTE|DEALLOCATE|SHOW|BEGIN|START|COMMIT|END|ROLLBACK'
    . '|SAVEPOINT|RELEASE|DISCARD|SET|RESET|GRANT|REVOKE|SECURITY\s+LABEL'
    . '|COMMENT|DO|CALL)\b'
    . '|^\s*(CREATE|ALTER|DROP)\s+(ROLE|USER|GROUP|SCHEMA|DATABASE|TABLESPACE)\b#i';

// Setup SQL for types, domains, and functions referenced by test patterns.
const DEPENDENCY_REGISTRY = [
    'ddef1'       => "CREATE DOMAIN ddef1 AS int4 DEFAULT 3;",
    'ddef2'       => "CREATE DOMAIN ddef2 AS oid DEFAULT '12';",
    'ddef3'       => "CREATE DOMAIN ddef3 AS text DEFAULT 5;",
    'str_domain'  => "CREATE DOMAIN str_domain AS text NOT NULL;",
    'str_domain2' => "CREATE DOMAIN str_domain2 AS text CHECK (VALUE <> 'foo') DEFAULT 'foo';",
    'boo'         => "CREATE FUNCTION boo(int) RETURNS int IMMUTABLE STRICT LANGUAGE plpgsql AS \$\$ BEGIN RETURN \$1; END; \$\$;",
    'int42'       => "CREATE DOMAIN int42 AS integer;",
    'city_budget' => "CREATE DOMAIN city_budget AS numeric;",
    'ddef4'       => "CREATE SEQUENCE ddef4_seq; CREATE DOMAIN ddef4 AS int4 DEFAULT nextval('ddef4_seq');",
    'ddef5'       => "CREATE DOMAIN ddef5 AS numeric(8,2) NOT NULL DEFAULT '12.12';",
    'gtest31_1'   => "CREATE TABLE gtest31_1 (a int, b text GENERATED ALWAYS AS ('hello') STORED, c text);",
];


/**
 * Replays schema statements against a throwaway, schema-only Postgres so that
 * accumulation and error-test detection reflect what actually happens on an
 * empty table (exactly the harness's condition), instead of guessing from
 * "-- fail" comments. A statement is accumulated into before_sql iff it
 * executes successfully here; a candidate ALTER that raises is an
 * intentional-error test.
 */
class PgValidator
{
    public bool $enabled = false;
    private ?PDO $conn = null;   // ordered replay (accumulation), schema "public"
    private ?PDO $probe = null;  // isolated per-pattern checks, schema "probe"

    public function __construct(?string $dsn)
    {
        if (!$dsn) {
            return;
        }
        $kv = [];
        foreach (preg_split('/\s+/', trim($dsn)) as $part) {
            if (strpos($part, '=') !== false) {
                [$k, $v] = explode('=', $part, 2);
                $kv[$k] = $v;
            }
        }
        $host = $kv['host'] ?? 'localhost';
        $port = $kv['port'] ?? '5432';
        $db   = $kv['dbname'] ?? 'postgres';
        $pdoDsn = "pgsql:host=$host;port=$port;dbname=$db";
        try {
            $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
            $this->conn  = new PDO($pdoDsn, $kv['user'] ?? '', $kv['password'] ?? '', $opts);
            $this->probe = new PDO($pdoDsn, $kv['user'] ?? '', $kv['password'] ?? '', $opts);
            $this->enabled = true;
        } catch (\Throwable $e) {
            fwrite(STDERR, "  NOTE: live validation disabled ({$e->getMessage()})\n");
        }
    }

    /** Drop all objects and re-seed dependency types for a fresh file. */
    public function reset(): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->exec($this->conn, "DROP SCHEMA public CASCADE; CREATE SCHEMA public;");
        foreach (DEPENDENCY_REGISTRY as $createSql) {
            $this->exec($this->conn, $createSql);
        }
    }

    /**
     * Replay a schema statement onto the ordered accumulation DB; return true
     * on success. DML is a no-op (the harness never loads data).
     */
    public function apply(string $stmt): bool
    {
        if (!$this->enabled) {
            return true;
        }
        if (preg_match(SKIP_RE, $stmt)) {
            return true;
        }
        return $this->exec($this->conn, $stmt);
    }

    /**
     * Validate a candidate pattern exactly as the harness would, in an isolated
     * schema. Returns 'ok', 'before_fail', or 'alter_fail'. 'before_fail' means
     * before_sql + setup is not self-contained (a real gap, never a silent
     * skip); 'alter_fail' means the ALTER is an intentional PG error test.
     */
    public function check(?array $setup, string $before, string $alter): string
    {
        if (!$this->enabled) {
            return 'ok';
        }
        // Isolate the probe from the ordered-replay objects in "public" so it
        // faithfully mirrors the harness's fresh, self-contained database: only
        // setup_sql + before_sql may provide dependencies.
        $this->exec($this->probe, "DROP SCHEMA IF EXISTS probe CASCADE; CREATE SCHEMA probe;");
        $this->exec($this->probe, "SET search_path = probe;");
        try {
            foreach (($setup ?? []) as $s) {
                $this->exec($this->probe, $s);
            }
            if (!$this->exec($this->probe, $before)) {
                return 'before_fail';
            }
            return $this->exec($this->probe, $alter) ? 'ok' : 'alter_fail';
        } finally {
            $this->exec($this->probe, "RESET search_path;");
        }
    }

    private function exec(PDO $conn, string $sql): bool
    {
        try {
            $conn->exec($sql);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}


// ── Statement splitting ──────────────────────────────────────────────────

/** Split SQL into individual statements, handling dollar-quoting. */
function split_statements(string $sql): array
{
    $stmts    = [];
    $current  = [];
    $inDollar = false;
    $dollarTag = '';

    foreach (explode("\n", $sql) as $line) {
        $stripped = trim($line);
        // Skip psql meta-commands and empty lines
        if ($stripped === '' || $stripped[0] === '\\') {
            continue;
        }
        if (strncmp($stripped, '--', 2) === 0) {
            // Keep comment for context
            $current[] = $line;
            continue;
        }

        $current[] = $line;

        // Track dollar-quoting
        if (!$inDollar) {
            if (preg_match_all('#\$(\w*)\$#', $line, $m)) {
                foreach ($m[0] as $dt) {
                    if (substr_count($line, $dt) % 2 === 1) {
                        $inDollar  = true;
                        $dollarTag = $dt;
                        break;
                    }
                }
            }
        } elseif (strpos($line, $dollarTag) !== false) {
            $inDollar  = false;
            $dollarTag = '';
        }

        // Statement boundary: line ends with ; (strip trailing inline comment)
        $codePart = preg_replace('#\s*--.*$#', '', $stripped);
        if (!$inDollar && substr($codePart, -1) === ';') {
            $full = trim(implode("\n", $current));
            if ($full !== '') {
                $stmts[] = $full;
            }
            $current = [];
        }
    }

    if ($current) {
        $full = trim(implode("\n", $current));
        if ($full !== '' && strncmp($full, '--', 2) !== 0) {
            $stmts[] = $full;
        }
    }

    return $stmts;
}


// ── Statement classification ─────────────────────────────────────────────

function extract_table_name(string $stmt, string $pattern): ?string
{
    return preg_match($pattern, $stmt, $m) ? strtolower($m[1]) : null;
}

function is_create_table(string $stmt): bool
{
    $upper = ltrim(strtoupper($stmt), "- \n");
    return (bool) preg_match('#^CREATE\s+(?:UNLOGGED\s+)?TABLE\s#', $upper);
}

function is_drop_table(string $stmt): bool
{
    $upper = ltrim(strtoupper($stmt), "- \n");
    return (bool) preg_match('#^DROP\s+TABLE\s#', $upper);
}

function is_alter_table(string $stmt): bool
{
    $upper = trim(strtoupper(preg_replace('#^--[^\n]*\n#', '', $stmt)));
    return (bool) preg_match('#^ALTER\s+TABLE\s#', $upper);
}

function get_create_table_name(string $stmt): ?string
{
    return extract_table_name($stmt, '#CREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)#i');
}

function get_drop_table_name(string $stmt): ?string
{
    return extract_table_name($stmt, '#DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(\w+)#i');
}

function get_alter_table_name(string $stmt): ?string
{
    $clean = trim(preg_replace('#^--[^\n]*\n#', '', $stmt));
    return extract_table_name($clean, '#ALTER\s+TABLE\s+(?:ONLY\s+)?(\w+)#i');
}

/** Extract just the CREATE TABLE statement (strip comments). */
function get_create_table_body(string $stmt): string
{
    $lines = [];
    foreach (explode("\n", $stmt) as $l) {
        if (strncmp(ltrim($l), '--', 2) !== 0) {
            $lines[] = $l;
        }
    }
    return trim(implode("\n", $lines));
}

function is_schema_modifying_alter(string $stmt): bool
{
    $upper = strtoupper($stmt);
    $modifiers = [
        'ADD COLUMN', 'DROP COLUMN', 'ADD CONSTRAINT', 'DROP CONSTRAINT',
        'SET NOT NULL', 'DROP NOT NULL', 'SET DEFAULT', 'DROP DEFAULT',
        'SET DATA TYPE', ' TYPE ', 'ADD PRIMARY KEY', 'ADD UNIQUE',
        'ADD CHECK', 'ADD FOREIGN KEY', 'ADD EXCLUDE', 'ADD NOT NULL',
        'DROP IDENTITY', 'ADD GENERATED', 'DROP EXPRESSION',
    ];
    foreach ($modifiers as $m) {
        if (strpos($upper, $m) !== false) {
            return true;
        }
    }
    $body = trim(preg_replace('#^ALTER\s+TABLE\s+(?:ONLY\s+)?\w+\s+#', '', $upper));
    if (preg_match('#^DROP\s+[A-Z_]\w*\s*[;,]#', $body)) {
        return true;
    }
    if (preg_match('#^ADD\s+[A-Z_]\w+\s+\w+#', $body)
        && !preg_match('#^ADD\s+(COLUMN|CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN|EXCLUDE|NOT|GENERATED|IF)\b#', $body)) {
        return true;
    }
    return false;
}

/** Classify an ALTER TABLE statement into a testable category, or null. */
function classify_alter(string $stmt): ?string
{
    $upper = strtoupper($stmt);

    $skipPatterns = [
        'OWNER TO', 'SET TABLESPACE', 'CLUSTER ON', 'SET WITHOUT CLUSTER',
        'ENABLE TRIGGER', 'DISABLE TRIGGER', 'ENABLE RULE', 'DISABLE RULE',
        'SET SCHEMA', 'RENAME TO', 'RENAME COLUMN', 'RENAME CONSTRAINT',
        'SET (', 'RESET (', 'ENABLE ROW LEVEL', 'DISABLE ROW LEVEL',
        'FORCE ROW LEVEL', 'NO FORCE ROW',
        'OF ', 'NOT OF', 'REPLICA IDENTITY', 'SET LOGGED', 'SET UNLOGGED',
        'ATTACH PARTITION', 'DETACH PARTITION', 'SET ACCESS METHOD',
        'SET STATISTICS', 'SET STORAGE', 'SET COMPRESSION',
        'ALTER COLUMN', 'VALIDATE CONSTRAINT',
        'SET WITHOUT OIDS', 'SET WITH OIDS',
    ];
    foreach ($skipPatterns as $p) {
        if (strpos($upper, $p) !== false) {
            if (strpos($upper, 'ALTER COLUMN') !== false) {
                $diffable = ['SET DEFAULT', 'DROP DEFAULT', 'SET NOT NULL',
                    'DROP NOT NULL', 'SET DATA TYPE', ' TYPE ',
                    'ADD GENERATED', 'DROP IDENTITY'];
                $hasDiffable = false;
                foreach ($diffable as $d) {
                    if (strpos($upper, $d) !== false) {
                        $hasDiffable = true;
                        break;
                    }
                }
                if ($hasDiffable) {
                    break;
                }
            } else {
                return null;
            }
        }
    }

    if (strpos($upper, 'ADD COLUMN') !== false) {
        return 'add_column';
    }
    if (strpos($upper, 'DROP COLUMN') !== false) {
        return 'drop_column';
    }
    if (strpos($upper, 'ADD CONSTRAINT') !== false) {
        return 'add_constraint';
    }
    if (strpos($upper, 'DROP CONSTRAINT') !== false) {
        return 'drop_constraint';
    }
    if (preg_match('#ADD\s+PRIMARY\s+KEY#', $upper)) {
        return 'add_constraint';
    }
    if (preg_match('#ADD\s+UNIQUE#', $upper)) {
        return 'add_constraint';
    }
    if (preg_match('#ADD\s+CHECK\b#', $upper)) {
        return 'add_constraint';
    }
    if (preg_match('#ADD\s+FOREIGN\s+KEY#', $upper)) {
        return 'add_constraint';
    }
    if (preg_match('#ADD\s+EXCLUDE\b#', $upper)) {
        return 'add_constraint';
    }
    if (preg_match('#ADD\s+NOT\s+NULL\b#', $upper)) {
        return 'add_constraint';
    }
    if (strpos($upper, 'SET DATA TYPE') !== false || preg_match('#ALTER\s+(?:COLUMN\s+)?\w+\s+TYPE\s#', $upper)) {
        return 'change_type';
    }
    if (strpos($upper, 'SET NOT NULL') !== false) {
        return 'set_not_null';
    }
    if (strpos($upper, 'DROP NOT NULL') !== false) {
        return 'drop_not_null';
    }
    if (strpos($upper, 'SET DEFAULT') !== false) {
        return 'set_default';
    }
    if (strpos($upper, 'DROP DEFAULT') !== false) {
        return 'drop_default';
    }
    if (preg_match('#ADD\s+GENERATED\b#', $upper)) {
        return 'add_identity';
    }
    if (strpos($upper, 'DROP IDENTITY') !== false) {
        return 'drop_identity';
    }

    // Shorthand forms without explicit keywords
    $body = trim(preg_replace('#^ALTER\s+TABLE\s+(?:ONLY\s+)?\w+\s+#', '', $upper));
    if (preg_match('#^DROP\s+[A-Z_]\w*\s*[;,]#', $body)) {
        return 'drop_column';
    }
    if (preg_match('#^ADD\s+[A-Z_]\w+\s+\w+#', $body)
        && !preg_match('#^ADD\s+(COLUMN|CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN|EXCLUDE|NOT|GENERATED|IF)\b#', $body)) {
        return 'add_column';
    }
    if (preg_match('#^ALTER\s+[A-Z_]\w+\s+TYPE\s#', $body)) {
        return 'change_type';
    }

    return null;
}


// ── Safety and version checks ────────────────────────────────────────────

function is_safe_create_sql(string $stmt): bool
{
    $upper = trim(strtoupper($stmt));
    foreach (['REFERENCES', 'LIKE ', 'INHERITS', 'PARTITION', 'REGRESS_', 'ATTMP_LOG', 'PG_TEMP'] as $u) {
        if (strpos($upper, $u) !== false) {
            return false;
        }
    }
    return true;
}

function is_safe_alter_sql(string $stmt): bool
{
    $upper = trim(strtoupper($stmt));
    return !(strpos($upper, 'EXECUTE ') !== false || strpos($upper, 'PREPARE ') !== false);
}

function is_unsafe_accumulation(string $stmt, ?array $tableState = null): bool
{
    $upper = strtoupper($stmt);
    if (strpos($upper, 'FOREIGN KEY') !== false || strpos($upper, 'REFERENCES') !== false) {
        if (preg_match('#REFERENCES\s+(\w+)#', $upper, $m) && $tableState !== null) {
            if (!isset($tableState[strtolower($m[1])])) {
                return true;
            }
        } elseif ($tableState === null) {
            return true;
        }
    }
    if (strpos($upper, 'SET EXPRESSION') !== false || strpos($upper, 'DROP EXPRESSION') !== false) {
        return true;
    }
    return false;
}

function references_dropped_column(string $stmt, array $state): bool
{
    foreach (array_keys($state['dropped_columns'] ?? []) as $col) {
        if (preg_match('#\b' . preg_quote($col, '#') . '\b#i', $stmt)) {
            return true;
        }
    }
    return false;
}

function extract_fk_referenced_table(string $stmt): ?string
{
    return preg_match('#REFERENCES\s+(\w+)#i', $stmt, $m) ? strtolower($m[1]) : null;
}

function drops_skipped_constraint(string $stmt, array $state): bool
{
    if (preg_match('#DROP\s+CONSTRAINT\s+"?(\w+)"?#i', $stmt, $m)) {
        return isset($state['skipped_constraints'][strtolower($m[1])]);
    }
    return false;
}

function conflicts_with_state(string $stmt, array $state): bool
{
    $upper     = strtoupper($stmt);
    $fullState = $state['create'] . "\n" . implode("\n", $state['alters']);
    $fullUpper = strtoupper($fullState);

    if (preg_match('#ALTER\s+(?:COLUMN\s+)?(\w+)\s+(?:SET|DROP)\s+NOT\s+NULL#', $upper, $m)) {
        $col = strtolower($m[1]);
        if ($col !== 'column') {
            $colExists = preg_match('#\b' . preg_quote($col, '#') . '\b\s+\w+#i', $state['create'])
                || preg_match('#ADD\s+COLUMN\s+' . preg_quote($col, '#') . '\b#i', $fullState);
            if (!$colExists) {
                return true;
            }
            if (strpos($upper, 'DROP') !== false && strpos($upper, 'NOT NULL') !== false) {
                if (preg_match_all('#PRIMARY\s+KEY\s*\(([^)]+)\)#i', $fullState, $pk)) {
                    foreach ($pk[1] as $cols) {
                        if (preg_match('#\b' . preg_quote($col, '#') . '\b#i', $cols)) {
                            return true;
                        }
                    }
                }
                if (preg_match('#\b' . preg_quote($col, '#') . '\b\s+\w+.*\bPRIMARY\s+KEY\b#is', $fullState)) {
                    return true;
                }
            }
        }
    }

    if (preg_match('#ADD\s+(?:CONSTRAINT\s+\w+\s+)?PRIMARY\s+KEY#', $upper)
        && preg_match('#PRIMARY\s+KEY#', $fullUpper)) {
        return true;
    }

    if (strpos($upper, 'IF EXISTS') === false
        && preg_match('#DROP\s+(?:COLUMN\s+)?(\w+)#', $upper, $m)) {
        $dcol = strtolower($m[1]);
        $reserved = ['column', 'constraint', 'default', 'not', 'identity', 'expression', 'if'];
        if (!in_array($dcol, $reserved, true)) {
            $inCreate = preg_match('#\b' . preg_quote($dcol, '#') . '\b\s+\w+#i', $state['create']);
            $added    = preg_match('#ADD\s+(?:COLUMN\s+)?' . preg_quote($dcol, '#') . '\b#i', $fullState);
            if (!$inCreate && !$added) {
                return true;
            }
        }
    }

    if (strpos($upper, 'IF NOT EXISTS') !== false && strpos($upper, 'ADD') !== false) {
        if (preg_match('#ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+(\w+)#', $upper, $m)) {
            if (preg_match('#\b' . preg_quote(strtolower($m[1]), '#') . '\b#i', $fullState)) {
                return true;
            }
        }
    }

    if (strpos($upper, 'IF NOT EXISTS') === false && preg_match('#ADD\s+COLUMN\s+(\w+)#', $upper, $m)) {
        $col = strtolower($m[1]);
        if ($col !== 'if' && preg_match('#\b' . preg_quote($col, '#') . '\b\s+\w+#i', $state['create'])) {
            return true;
        }
    }

    if (preg_match('#FOREIGN\s+KEY\s*\(\s*(\w+)#', $upper, $m)) {
        $fkCol    = strtolower($m[1]);
        $inCreate = preg_match('#\b' . preg_quote($fkCol, '#') . '\b\s+\w+#i', $state['create']);
        $added    = preg_match('#ADD\s+(?:COLUMN\s+)?' . preg_quote($fkCol, '#') . '\b#i', $fullState);
        if (!$inCreate && !$added) {
            return true;
        }
    }

    return false;
}

function detect_min_pg_version(string $beforeSql, string $alterSql): ?int
{
    $combined = strtoupper($beforeSql . ' ' . $alterSql);
    if (strpos($combined, 'NOT ENFORCED') !== false) {
        return 18;
    }
    if (preg_match('#ADD\s+CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+#', $combined)) {
        return 18;
    }
    if (preg_match('#CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+#', $combined)) {
        return 18;
    }
    if (preg_match('#\bADD\s+NOT\s+NULL\s+\w+#', $combined)) {
        return 18;
    }
    if (strpos($combined, 'SET EXPRESSION') !== false || strpos($combined, 'DROP EXPRESSION') !== false) {
        return 17;
    }
    return null;
}

/**
 * Return the ALTER with every USING clause removed, matching what DBDiff emits
 * for a type change (a plain TYPE change). Handles multi-action ALTERs by
 * stopping each USING at the next top-level action.
 */
function strip_using(string $stmt): string
{
    $stripped = preg_replace(
        '#\bUSING\b.*?(?=,\s*(?:ALTER|DROP|ADD|RENAME|VALIDATE|SET|ENABLE|DISABLE)\b|$)#is',
        '', $stmt);
    return rtrim(rtrim($stripped), ';');
}

/**
 * Reasons a PG-valid pattern still can't be tested by DBDiff today. PG
 * rejections are NOT handled here — live validation classifies those as
 * intentional_error. Only genuine DBDiff feature gaps remain.
 */
function detect_skip_reason(string $stmt, string $beforeSql, ?PgValidator $validator = null, ?array $setupSql = null): ?string
{
    $upper = strtoupper($stmt);

    // Internal dropped-column placeholder columns cannot be expressed in DDL.
    if (strpos($upper, '........PG.DROPPED') !== false) {
        return 'dropped_column_reference';
    }

    // NOT NULL ... NO INHERIT — non-inherited NOT NULL (contype='n') that DBDiff
    // models as a plain column attribute. (CHECK ... NO INHERIT round-trips.)
    if (preg_match('#NOT\s+NULL\s+\w+\s+NO\s+INHERIT#', $upper)) {
        return 'notnull_no_inherit';
    }

    // PRIMARY KEY USING INDEX promotes+renames an index; DBDiff emits a
    // default-named PK + index, so the names won't match.
    if (preg_match('#PRIMARY\s+KEY\s+USING\s+INDEX\b#', $upper)) {
        return 'primary_key_using_index';
    }

    // ALTER COLUMN TYPE ... USING <expr>: DBDiff emits a plain TYPE change. If
    // PG can't cast without USING, DBDiff's output fails to apply. Probe the
    // no-USING form to keep only the casts DBDiff genuinely can't reproduce.
    if (preg_match('#\bTYPE\b.*\bUSING\b#is', $upper)) {
        if ($validator !== null && $validator->enabled) {
            $plain = strip_using($stmt);
            if ($validator->check($setupSql, $beforeSql, $plain) !== 'ok') {
                return 'using_cast_expression';
            }
        } else {
            $expr = preg_match('#\bTYPE\s+\w+.*\bUSING\s+(.+)#is', $upper, $m)
                ? rtrim(trim($m[1]), ';') : '';
            if (!preg_match('#^\(?\w+\)?::\w+$#', trim($expr))) {
                return 'using_cast_expression';
            }
        }
    }

    return null;
}

/** Detect external type/function dependencies and return setup SQL, or null. */
function detect_dependencies(string $beforeSql, string $alterSql, ?array $tableState = null, ?string $mainTable = null): ?array
{
    $combined = $beforeSql . ' ' . $alterSql;
    $setup = [];
    $seen  = [];

    foreach (DEPENDENCY_REGISTRY as $name => $createSql) {
        if ($name === $mainTable) {
            continue;
        }
        if (preg_match('#\b' . preg_quote($name, '#') . '\b#i', $combined) && !isset($seen[$name])) {
            $setup[] = $createSql;
            $seen[$name] = true;
        }
    }

    if ($tableState) {
        if (preg_match_all('#REFERENCES\s+(\w+)#i', $combined, $m)) {
            foreach ($m[1] as $ref) {
                $refTable = strtolower($ref);
                if ($refTable === $mainTable || !isset($tableState[$refTable]) || isset($seen[$refTable])) {
                    continue;
                }
                $seen[$refTable] = true;
                $refState = $tableState[$refTable];
                $setup[]  = $refState['create'];
                foreach ($refState['alters'] as $a) {
                    if (strpos(strtoupper($a), 'REFERENCES') === false) {
                        $setup[] = $a;
                    }
                }
            }
        }
    }

    return $setup ?: null;
}


// ── Sequential extraction with cumulative state ──────────────────────────

function build_test_cases_sequential(string $sqlContent, string $sourceFile, PgValidator $validator): array
{
    $statements = split_statements($sqlContent);
    $tableState = [];
    $cases      = [];
    $seen       = [];

    $live = $validator->enabled;
    if ($live) {
        $validator->reset();
    }

    foreach ($statements as $stmt) {
        $cleanStmt = trim(preg_replace('#^(--[^\n]*\n)+#', '', $stmt));
        if ($cleanStmt === '') {
            continue;
        }

        $hasFailComment = false;
        foreach (explode("\n", $stmt) as $line) {
            if (preg_match('#--.*(?:\bfail|\berror)#i', $line)) {
                $hasFailComment = true;
                break;
            }
        }

        $appliedOk = $live ? $validator->apply($cleanStmt) : !$hasFailComment;

        if (is_create_table($cleanStmt)) {
            $tableName = get_create_table_name($cleanStmt);
            if ($tableName && ($appliedOk || !$live)) {
                $tableState[$tableName] = [
                    'create'               => get_create_table_body($stmt),
                    'alters'               => [],
                    'skipped_constraints'  => [],
                    'dropped_columns'      => [],
                ];
            }
        } elseif (is_drop_table($cleanStmt)) {
            $tableName = get_drop_table_name($cleanStmt);
            if ($tableName && isset($tableState[$tableName])) {
                unset($tableState[$tableName]);
            }
        } elseif (strncmp(strtoupper($cleanStmt), 'CREATE', 6) === 0 && strpos(strtoupper($cleanStmt), 'INDEX') !== false) {
            if (preg_match('#CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?(\w+)\s+ON\s+(\w+)#i', $cleanStmt, $m)) {
                $tbl = strtolower($m[2]);
                $accumulate = $live ? $appliedOk : !$hasFailComment;
                if (isset($tableState[$tbl]) && $accumulate) {
                    $tableState[$tbl]['alters'][] = $cleanStmt;
                }
            }
        } elseif (is_alter_table($cleanStmt)) {
            $tableName = get_alter_table_name($cleanStmt);
            if (!$tableName || !isset($tableState[$tableName])) {
                continue;
            }
            emit_alter_pattern($cleanStmt, $tableName, $tableState, $sourceFile, $cases, $seen, $validator, $live, $hasFailComment);
            accumulate_alter($cleanStmt, $tableName, $tableState, $live, $appliedOk);
        }
    }

    return $cases;
}

function emit_alter_pattern(string $alterStmt, string $tableName, array &$tableState, string $sourceFile, array &$cases, array &$seen, PgValidator $validator, bool $live, bool $hasFailComment): void
{
    if (!$live && $hasFailComment) {
        return;
    }
    $category = classify_alter($alterStmt);
    if (!$category || !is_safe_alter_sql($alterStmt)) {
        return;
    }
    $state     = $tableState[$tableName];
    $createSql = $state['create'];
    if (!is_safe_create_sql($createSql)) {
        return;
    }

    $beforeSql = implode("\n", array_merge([$createSql], $state['alters']));
    $setupSql  = detect_dependencies($beforeSql, $alterStmt, $tableState, $tableName);
    $minVersion = detect_min_pg_version($beforeSql, $alterStmt);

    $skipReason = detect_skip_reason($alterStmt, $beforeSql, $live ? $validator : null, $setupSql);
    if (!$skipReason) {
        if ($live) {
            $status = $validator->check($setupSql, $beforeSql, $alterStmt);
            if ($status === 'alter_fail') {
                $skipReason = 'intentional_error';
            } elseif ($status === 'before_fail') {
                $skipReason = 'before_not_self_contained';
            }
        } elseif (strpos(strtoupper($alterStmt), 'REFERENCES') !== false) {
            $ref = extract_fk_referenced_table($alterStmt);
            if ($ref && !isset($tableState[$ref])) {
                $skipReason = 'references_external_table';
            }
        }
    }

    $key = "$category:$tableName:" . substr($alterStmt, 0, 80);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;

    $pattern = [
        'id'          => $sourceFile . '_' . $category . '_' . (count($cases) + 1),
        'source_file' => $sourceFile,
        'category'    => $category,
        'table'       => $tableName,
        'before_sql'  => $beforeSql,
        'alter_sql'   => $alterStmt,
        'description' => $category . ': ' . substr($alterStmt, 0, 100),
    ];
    if ($setupSql) {
        $pattern['setup_sql'] = $setupSql;
    }
    if ($minVersion) {
        $pattern['min_pg_version'] = $minVersion;
    }
    if ($skipReason) {
        $pattern['skip_reason'] = $skipReason;
    }
    $cases[] = $pattern;
}

function accumulate_alter(string $cleanStmt, string $tableName, array &$tableState, bool $live, bool $appliedOk): void
{
    if (!is_schema_modifying_alter($cleanStmt)) {
        return;
    }
    if (strpos(strtolower($cleanStmt), '........pg.dropped') !== false) {
        return;
    }

    if ($live) {
        if (!$appliedOk) {
            return;
        }
    } else {
        $hasInlineFail = false;
        foreach (explode("\n", $cleanStmt) as $ln) {
            $t = trim($ln);
            if ($t !== '' && strncmp($t, '--', 2) !== 0 && preg_match('#--.*(?:\bfail|\berror)#i', $ln)) {
                $hasInlineFail = true;
                break;
            }
        }
        if ($hasInlineFail) {
            return;
        }
        if (is_unsafe_accumulation($cleanStmt, $tableState)) {
            if (preg_match('#ADD\s+CONSTRAINT\s+"?(\w+)"?#i', $cleanStmt, $m)) {
                $tableState[$tableName]['skipped_constraints'][strtolower($m[1])] = true;
            }
            return;
        }
        if (drops_skipped_constraint($cleanStmt, $tableState[$tableName])
            || conflicts_with_state($cleanStmt, $tableState[$tableName])
            || references_dropped_column($cleanStmt, $tableState[$tableName])) {
            return;
        }
    }

    $tableState[$tableName]['alters'][] = $cleanStmt;
    if (preg_match('#DROP\s+(?:COLUMN\s+)?(\w+)#i', $cleanStmt, $m)) {
        $col = strtolower($m[1]);
        $reserved = ['column', 'constraint', 'default', 'not', 'identity', 'expression'];
        if (!in_array($col, $reserved, true)) {
            $tableState[$tableName]['dropped_columns'][$col] = true;
        }
    }
}


// ── Main ─────────────────────────────────────────────────────────────────

function main(): void
{
    $regressionDir = '/tmp';
    $sourceFiles = [
        'alter_table'      => "$regressionDir/alter_table.sql",
        'constraints'      => "$regressionDir/pg_regress_constraints.sql",
        'create_index'     => "$regressionDir/pg_regress_create_index.sql",
        'identity'         => "$regressionDir/pg_regress_identity.sql",
        'generated_stored' => "$regressionDir/pg_regress_generated_stored.sql",
        'domain'           => "$regressionDir/pg_regress_domain.sql",
        'enum'             => "$regressionDir/pg_regress_enum.sql",
    ];

    $dsn = getenv('PGCONF_DSN') ?: null;
    $validator = new PgValidator($dsn);
    if ($validator->enabled) {
        fwrite(STDERR, "  Live validation: ON\n");
    } elseif ($dsn) {
        fwrite(STDERR, "  ERROR: PGCONF_DSN is set but live validation could not be enabled (pdo_pgsql missing or connection failed).\n");
        exit(1);
    } else {
        fwrite(STDERR, "  Live validation: OFF (set PGCONF_DSN to enable)\n");
    }

    $allCases = [];
    foreach ($sourceFiles as $name => $path) {
        if (!file_exists($path)) {
            fwrite(STDERR, "  SKIP: $path not found\n");
            continue;
        }
        $cases = build_test_cases_sequential(file_get_contents($path), $name, $validator);
        fwrite(STDERR, "  $name: " . count($cases) . " test cases extracted\n");
        $allCases = array_merge($allCases, $cases);
    }

    // Summary
    $cats = [];
    $skipReasons = [];
    $versionGated = 0;
    $withSetup = 0;
    foreach ($allCases as $c) {
        $cats[$c['category']] = ($cats[$c['category']] ?? 0) + 1;
        if (isset($c['skip_reason'])) {
            $skipReasons[$c['skip_reason']] = ($skipReasons[$c['skip_reason']] ?? 0) + 1;
        }
        if (isset($c['min_pg_version'])) {
            $versionGated++;
        }
        if (isset($c['setup_sql'])) {
            $withSetup++;
        }
    }

    fwrite(STDERR, "\n  Total: " . count($allCases) . " test cases\n");
    arsort($cats);
    foreach ($cats as $cat => $count) {
        fwrite(STDERR, "    $cat: $count\n");
    }
    if ($skipReasons) {
        fwrite(STDERR, "\n  Pre-excluded: " . array_sum($skipReasons) . "\n");
        ksort($skipReasons);
        foreach ($skipReasons as $reason => $count) {
            fwrite(STDERR, "    $reason: $count\n");
        }
    }
    if ($versionGated) {
        fwrite(STDERR, "  Version-gated (PG 17+): $versionGated\n");
    }
    if ($withSetup) {
        fwrite(STDERR, "  With setup_sql: $withSetup\n");
    }

    if (!is_dir(PATTERNS_DIR)) {
        mkdir(PATTERNS_DIR, 0777, true);
    }
    $outPath = PATTERNS_DIR . '/patterns.json';
    file_put_contents($outPath, json_encode($allCases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDERR, "\n  Written to: $outPath\n");
}

main();
