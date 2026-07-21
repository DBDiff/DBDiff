#!/usr/bin/env python3
"""
Extract testable DDL pattern pairs from Postgres regression SQL files.

Scans regression .sql files sequentially, tracking cumulative table state
so that each extracted pattern is self-contained (its before_sql includes
all prerequisite columns/constraints from earlier ALTERs).

Each pair is a self-contained test: create db1 from "before", create db2
from "before + alteration", run dbdiff, apply UP to db1, assert match.

Output: JSON array of test cases to tests/pg-conformance/patterns.json
"""

import re
import json
import sys
import os

PATTERNS_DIR = os.path.join(os.path.dirname(__file__), '..', '..', 'tests', 'pg-conformance')

# Statements never applied to the scratch DB: DML (the harness never loads
# data) plus session/role/config management. Skipping role changes keeps the
# whole replay running as the harness's own superuser, so a statement's
# success here matches what the harness will see.
_SKIP_RE = re.compile(
    r'^\s*(INSERT|UPDATE|DELETE|SELECT|COPY|VALUES|WITH|TABLE|EXPLAIN|ANALYZE'
    r'|VACUUM|CHECKPOINT|CLUSTER|LOCK|LISTEN|NOTIFY|UNLISTEN|MOVE|FETCH'
    r'|PREPARE|EXECUTE|DEALLOCATE|SHOW|BEGIN|START|COMMIT|END|ROLLBACK'
    r'|SAVEPOINT|RELEASE|DISCARD|SET|RESET|GRANT|REVOKE|SECURITY\s+LABEL'
    r'|COMMENT|DO|CALL)\b'
    r'|^\s*(CREATE|ALTER|DROP)\s+(ROLE|USER|GROUP|SCHEMA|DATABASE|TABLESPACE)\b',
    re.IGNORECASE)


class PgValidator:
    """Replays schema statements against a throwaway, schema-only Postgres so
    that accumulation and error-test detection reflect what actually happens
    on an empty table (exactly the harness's condition), instead of guessing
    from ``-- fail`` comments.

    A statement is accumulated into before_sql iff it executes successfully
    here; a candidate ALTER that raises is an intentional-error test.
    """

    def __init__(self, dsn):
        self.enabled = False
        self.conn = None    # ordered replay (accumulation), schema "public"
        self.probe = None   # isolated per-pattern checks, schema "probe"
        if not dsn:
            return
        try:
            import psycopg2  # noqa: F401
        except ImportError:
            print("  NOTE: psycopg2 not available; extraction runs without "
                  "live validation", file=sys.stderr)
            return
        try:
            import psycopg2
            self.conn = psycopg2.connect(dsn)
            self.conn.autocommit = True
            self.probe = psycopg2.connect(dsn)
            self.probe.autocommit = True
            self.enabled = True
        except Exception as e:  # pragma: no cover - connection issues
            print(f"  NOTE: live validation disabled ({e})", file=sys.stderr)

    def reset(self):
        """Drop all objects and re-seed dependency types for a fresh file."""
        if not self.enabled:
            return
        self._exec(self.conn, "DROP SCHEMA public CASCADE; CREATE SCHEMA public;")
        for create_sql in DEPENDENCY_REGISTRY.values():
            self._exec(self.conn, create_sql)

    def apply(self, stmt):
        """Replay a schema statement onto the ordered accumulation DB; return
        True on success. DML is a no-op (the harness never loads data)."""
        if not self.enabled:
            return True
        if _SKIP_RE.match(stmt):
            return True
        return self._exec(self.conn, stmt)

    def check(self, setup, before, alter):
        """Validate a candidate pattern exactly as the harness would, in an
        isolated schema. Returns 'ok', 'before_fail', or 'alter_fail'.

        'before_fail' means before_sql + setup is not self-contained (a real
        gap to fix, never a silent skip); 'alter_fail' means the ALTER is an
        intentional PG error test."""
        if not self.enabled:
            return 'ok'
        # Isolate the probe from the ordered-replay objects in "public" so it
        # faithfully mirrors the harness's fresh, self-contained database:
        # only setup_sql + before_sql may provide dependencies.
        self._exec(self.probe, "DROP SCHEMA IF EXISTS probe CASCADE; CREATE SCHEMA probe;")
        self._exec(self.probe, "SET search_path = probe;")
        try:
            for s in (setup or []):
                self._exec(self.probe, s)
            if not self._exec(self.probe, before):
                return 'before_fail'
            return 'ok' if self._exec(self.probe, alter) else 'alter_fail'
        finally:
            self._exec(self.probe, "RESET search_path;")

    @staticmethod
    def _exec(conn, sql):
        cur = conn.cursor()
        try:
            cur.execute(sql)
            return True
        except Exception:
            return False
        finally:
            cur.close()

# ── Dependency registry ──────────────────────────────────────────────────
# Setup SQL for types, domains, and functions referenced by test patterns
DEPENDENCY_REGISTRY = {
    'ddef1': "CREATE DOMAIN ddef1 AS int4 DEFAULT 3;",
    'ddef2': "CREATE DOMAIN ddef2 AS oid DEFAULT '12';",
    'ddef3': "CREATE DOMAIN ddef3 AS text DEFAULT 5;",
    'str_domain': "CREATE DOMAIN str_domain AS text NOT NULL;",
    'str_domain2': "CREATE DOMAIN str_domain2 AS text CHECK (VALUE <> 'foo') DEFAULT 'foo';",
    'boo': "CREATE FUNCTION boo(int) RETURNS int IMMUTABLE STRICT LANGUAGE plpgsql AS $$ BEGIN RETURN $1; END; $$;",
    'int42': "CREATE DOMAIN int42 AS integer;",
    'city_budget': "CREATE DOMAIN city_budget AS numeric;",
    'ddef4': "CREATE SEQUENCE ddef4_seq; CREATE DOMAIN ddef4 AS int4 DEFAULT nextval('ddef4_seq');",
    'ddef5': "CREATE DOMAIN ddef5 AS numeric(8,2) NOT NULL DEFAULT '12.12';",
    'gtest31_1': "CREATE TABLE gtest31_1 (a int, b text GENERATED ALWAYS AS ('hello') STORED, c text);",
}


# ── Statement splitting ──────────────────────────────────────────────────

def split_statements(sql):
    """Split SQL into individual statements, handling dollar-quoting."""
    stmts = []
    current = []
    in_dollar = False
    dollar_tag = ''
    i = 0
    lines = sql.split('\n')

    for line in lines:
        stripped = line.strip()
        # Skip psql meta-commands and empty/comment-only lines
        if stripped.startswith('\\') or stripped == '':
            continue
        if stripped.startswith('--'):
            # Keep comment for context but don't include in statements
            current.append(line)
            continue

        current.append(line)

        # Track dollar-quoting
        if not in_dollar:
            dollar_matches = re.findall(r'\$(\w*)\$', line)
            if dollar_matches:
                for tag in dollar_matches:
                    dt = f'${tag}$'
                    # Check if it opens and closes on same line
                    count = line.count(dt)
                    if count % 2 == 1:
                        in_dollar = True
                        dollar_tag = dt
                        break
        else:
            if dollar_tag in line:
                in_dollar = False
                dollar_tag = ''

        # Statement boundary: line ends with ; (strip trailing inline comments)
        code_part = re.sub(r'\s*--.*$', '', stripped)
        if not in_dollar and code_part.endswith(';'):
            full = '\n'.join(current).strip()
            if full:
                stmts.append(full)
            current = []

    # Remaining (shouldn't happen in well-formed SQL)
    if current:
        full = '\n'.join(current).strip()
        if full and not full.startswith('--'):
            stmts.append(full)

    return stmts


# ── Statement classification ─────────────────────────────────────────────

def extract_table_name(stmt, keyword_pattern):
    """Extract table name from a DDL statement."""
    m = re.search(keyword_pattern, stmt, re.IGNORECASE)
    return m.group(1).lower() if m else None


def is_create_table(stmt):
    upper = stmt.upper().lstrip('- \n')
    return bool(re.match(r'CREATE\s+(?:UNLOGGED\s+)?TABLE\s', upper))


def is_drop_table(stmt):
    upper = stmt.upper().lstrip('- \n')
    return bool(re.match(r'DROP\s+TABLE\s', upper))


def is_alter_table(stmt):
    upper = re.sub(r'^--[^\n]*\n', '', stmt).upper().strip()
    return bool(re.match(r'ALTER\s+TABLE\s', upper))


def get_create_table_name(stmt):
    return extract_table_name(
        stmt,
        r'CREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)'
    )


def get_drop_table_name(stmt):
    return extract_table_name(stmt, r'DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(\w+)')


def get_alter_table_name(stmt):
    clean = re.sub(r'^--[^\n]*\n', '', stmt).strip()
    return extract_table_name(clean, r'ALTER\s+TABLE\s+(?:ONLY\s+)?(\w+)')


def get_create_table_body(stmt):
    """Extract just the CREATE TABLE statement (strip comments)."""
    lines = stmt.split('\n')
    sql_lines = [l for l in lines if not l.strip().startswith('--')]
    return '\n'.join(sql_lines).strip()


def is_schema_modifying_alter(stmt):
    """Check if an ALTER modifies the schema (columns, constraints, types)."""
    upper = stmt.upper()
    modifiers = [
        'ADD COLUMN', 'DROP COLUMN', 'ADD CONSTRAINT', 'DROP CONSTRAINT',
        'SET NOT NULL', 'DROP NOT NULL', 'SET DEFAULT', 'DROP DEFAULT',
        'SET DATA TYPE', ' TYPE ', 'ADD PRIMARY KEY', 'ADD UNIQUE',
        'ADD CHECK', 'ADD FOREIGN KEY', 'ADD EXCLUDE', 'ADD NOT NULL',
        'DROP IDENTITY', 'ADD GENERATED', 'DROP EXPRESSION',
    ]
    # Also match shorthand DROP col (no COLUMN keyword) and ADD col type
    if any(m in upper for m in modifiers):
        return True
    body = re.sub(r'^ALTER\s+TABLE\s+(?:ONLY\s+)?\w+\s+', '', upper).strip()
    if re.match(r'DROP\s+[A-Z_]\w*\s*[;,]', body):
        return True
    if re.match(r'ADD\s+[A-Z_]\w+\s+\w+', body):
        if not re.match(r'ADD\s+(COLUMN|CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN|EXCLUDE|NOT|GENERATED|IF)\b', body):
            return True
    return False


def classify_alter(stmt):
    """Classify an ALTER TABLE statement into a testable category."""
    upper = stmt.upper()

    # Skip non-schema-diffable operations
    skip_patterns = [
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
    ]
    for p in skip_patterns:
        if p in upper:
            if 'ALTER COLUMN' in upper:
                diffable = ['SET DEFAULT', 'DROP DEFAULT', 'SET NOT NULL',
                           'DROP NOT NULL', 'SET DATA TYPE', ' TYPE ',
                           'ADD GENERATED', 'DROP IDENTITY']
                if any(d in upper for d in diffable):
                    break
            else:
                return None

    # Explicit keyword forms (check most specific first)
    if 'ADD COLUMN' in upper:
        return 'add_column'
    elif 'DROP COLUMN' in upper:
        return 'drop_column'
    elif 'ADD CONSTRAINT' in upper:
        return 'add_constraint'
    elif 'DROP CONSTRAINT' in upper:
        return 'drop_constraint'
    elif re.search(r'ADD\s+PRIMARY\s+KEY', upper):
        return 'add_constraint'
    elif re.search(r'ADD\s+UNIQUE', upper):
        return 'add_constraint'
    elif re.search(r'ADD\s+CHECK\b', upper):
        return 'add_constraint'
    elif re.search(r'ADD\s+FOREIGN\s+KEY', upper):
        return 'add_constraint'
    elif re.search(r'ADD\s+EXCLUDE\b', upper):
        return 'add_constraint'
    elif re.search(r'ADD\s+NOT\s+NULL\b', upper):
        return 'add_constraint'
    elif 'SET DATA TYPE' in upper or re.search(r'ALTER\s+(?:COLUMN\s+)?\w+\s+TYPE\s', upper):
        return 'change_type'
    elif 'SET NOT NULL' in upper:
        return 'set_not_null'
    elif 'DROP NOT NULL' in upper:
        return 'drop_not_null'
    elif 'SET DEFAULT' in upper:
        return 'set_default'
    elif 'DROP DEFAULT' in upper:
        return 'drop_default'
    elif re.search(r'ADD\s+GENERATED\b', upper):
        return 'add_identity'
    elif 'DROP IDENTITY' in upper:
        return 'drop_identity'

    # Shorthand forms without explicit keywords:
    # "ALTER TABLE t DROP colname;" (no COLUMN keyword)
    body = re.sub(r'^ALTER\s+TABLE\s+(?:ONLY\s+)?\w+\s+', '', upper).strip()
    if re.match(r'DROP\s+[A-Z_]\w*\s*[;,]', body):
        return 'drop_column'
    # "ALTER TABLE t ADD colname type ..." (no COLUMN keyword)
    if re.match(r'ADD\s+[A-Z_]\w+\s+\w+', body):
        if not re.match(r'ADD\s+(COLUMN|CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN|EXCLUDE|NOT|GENERATED|IF)\b', body):
            return 'add_column'
    # "ALTER TABLE t ALTER colname TYPE ..." (no COLUMN keyword)
    if re.match(r'ALTER\s+[A-Z_]\w+\s+TYPE\s', body):
        return 'change_type'

    # INHERIT / NO INHERIT — not schema-diffable but classifiable
    if re.match(r'(?:NO\s+)?INHERIT\b', body):
        return None

    return None


# ── Safety and version checks ────────────────────────────────────────────

def is_safe_create_sql(stmt):
    """Check if a CREATE TABLE statement can run in isolation."""
    upper = stmt.upper().strip()
    unsafe = ['REFERENCES', 'LIKE ', 'INHERITS', 'PARTITION',
              'regress_', 'attmp_log', 'pg_temp']
    return not any(u in upper for u in unsafe)


def is_safe_alter_sql(stmt):
    """Check if an ALTER TABLE statement can run in isolation."""
    upper = stmt.upper().strip()
    # EXECUTE/PREPARE are PL/pgSQL, not DDL
    if 'EXECUTE ' in upper or 'PREPARE ' in upper:
        return False
    return True


def is_unsafe_accumulation(stmt, table_state=None):
    """Check if a statement would poison cumulative state if accumulated."""
    upper = stmt.upper()
    # FK REFERENCES to other tables — only skip if referenced table is not in our state
    if 'FOREIGN KEY' in upper or 'REFERENCES' in upper:
        ref_match = re.search(r'REFERENCES\s+(\w+)', upper)
        if ref_match and table_state:
            ref_table = ref_match.group(1).lower()
            if ref_table not in table_state:
                return True
        elif not table_state:
            return True
    # PG17+ syntax that fails on PG16
    if 'SET EXPRESSION' in upper or 'DROP EXPRESSION' in upper:
        return True
    return False


def _references_dropped_column(stmt, state):
    """Check if a statement references a column that was already dropped."""
    dropped = state.get('dropped_columns', set())
    if not dropped:
        return False
    upper = stmt.upper()
    for col in dropped:
        if re.search(r'\b' + re.escape(col) + r'\b', upper, re.IGNORECASE):
            return True
    return False


def _extract_fk_referenced_table(stmt):
    """Extract the referenced table name from a REFERENCES clause."""
    m = re.search(r'REFERENCES\s+(\w+)', stmt, re.IGNORECASE)
    return m.group(1).lower() if m else None


def _drops_skipped_constraint(stmt, state):
    """Check if a DROP CONSTRAINT references a constraint we filtered out."""
    m = re.search(r'DROP\s+CONSTRAINT\s+"?(\w+)"?', stmt, re.IGNORECASE)
    if m:
        name = m.group(1).lower()
        return name in state.get('skipped_constraints', set())
    return False


def _conflicts_with_state(stmt, state):
    """Check if a statement would error given the current cumulative state."""
    upper = stmt.upper()
    full_state = state['create'] + '\n' + '\n'.join(state['alters'])
    full_upper = full_state.upper()

    # SET/DROP NOT NULL on a column — check it exists and isn't in a PK (for DROP)
    m = re.search(r'ALTER\s+(?:COLUMN\s+)?(\w+)\s+(?:SET|DROP)\s+NOT\s+NULL', upper)
    if m:
        col = m.group(1).lower()
        if col == 'column':
            return False
        col_exists = (
            re.search(r'\b' + re.escape(col) + r'\b\s+\w+', state['create'], re.IGNORECASE)
            or re.search(r'ADD\s+COLUMN\s+' + re.escape(col) + r'\b', full_state, re.IGNORECASE)
        )
        if not col_exists:
            return True

        if 'DROP' in upper and 'NOT NULL' in upper:
            for pk_m in re.finditer(r'PRIMARY\s+KEY\s*\(([^)]+)\)', full_state, re.IGNORECASE):
                if re.search(r'\b' + re.escape(col) + r'\b', pk_m.group(1), re.IGNORECASE):
                    return True
            if re.search(r'\b' + re.escape(col) + r'\b\s+\w+.*\bPRIMARY\s+KEY\b',
                         full_state, re.IGNORECASE):
                return True

    # ADD PRIMARY KEY when one already exists in state
    if re.search(r'ADD\s+(?:CONSTRAINT\s+\w+\s+)?PRIMARY\s+KEY', upper):
        if re.search(r'PRIMARY\s+KEY', full_upper):
            return True

    # DROP COLUMN on a column that doesn't exist in the table state
    drop_col_m = re.search(r'DROP\s+(?:COLUMN\s+)?(?:IF\s+EXISTS\s+)?(\w+)', upper)
    if drop_col_m and 'IF EXISTS' not in upper:
        dcol = drop_col_m.group(1).lower()
        if dcol not in ('column', 'constraint', 'default', 'not', 'identity',
                         'expression', 'if'):
            col_in_create = re.search(
                r'\b' + re.escape(dcol) + r'\b\s+\w+', state['create'], re.IGNORECASE)
            col_added = re.search(
                r'ADD\s+(?:COLUMN\s+)?' + re.escape(dcol) + r'\b', full_state, re.IGNORECASE)
            if not col_in_create and not col_added:
                return True

    # ADD COLUMN IF NOT EXISTS — column already exists, skip accumulating
    if 'IF NOT EXISTS' in upper and 'ADD' in upper:
        col_m = re.search(r'ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+(\w+)', upper)
        if col_m:
            col = col_m.group(1).lower()
            if re.search(r'\b' + re.escape(col) + r'\b', full_state, re.IGNORECASE):
                return True

    # ADD COLUMN for column that already exists (no IF NOT EXISTS)
    col_add = re.search(r'ADD\s+COLUMN\s+(\w+)', upper)
    if col_add and 'IF NOT EXISTS' not in upper:
        col = col_add.group(1).lower()
        if col not in ('if',):
            if re.search(r'\b' + re.escape(col) + r'\b\s+\w+', state['create'], re.IGNORECASE):
                return True

    # FK referencing a local column that doesn't exist
    fk_col_m = re.search(r'FOREIGN\s+KEY\s*\(\s*(\w+)', upper)
    if fk_col_m:
        fk_col = fk_col_m.group(1).lower()
        col_in_create = re.search(
            r'\b' + re.escape(fk_col) + r'\b\s+\w+', state['create'], re.IGNORECASE)
        col_added = re.search(
            r'ADD\s+(?:COLUMN\s+)?' + re.escape(fk_col) + r'\b', full_state, re.IGNORECASE)
        if not col_in_create and not col_added:
            return True

    return False


def detect_min_pg_version(before_sql, alter_sql):
    """Detect minimum PG version needed for this pattern."""
    combined = (before_sql + ' ' + alter_sql).upper()

    # PG18 features
    if 'NOT ENFORCED' in combined:
        return 18
    # PG18: ADD CONSTRAINT <name> NOT NULL <col>
    if re.search(r'ADD\s+CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+', combined):
        return 18
    # PG18: CONSTRAINT <name> NOT NULL <col> in CREATE TABLE
    if re.search(r'CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+', combined):
        return 18
    # PG18: ADD NOT NULL <col> (multi-action syntax)
    if re.search(r'\bADD\s+NOT\s+NULL\s+\w+', combined):
        return 18

    # PG17 features
    if 'SET EXPRESSION' in combined or 'DROP EXPRESSION' in combined:
        return 17

    return None


def _strip_using(stmt):
    """Return the ALTER with every USING clause removed, matching what DBDiff
    emits for a type change (a plain TYPE change, no USING). Handles
    multi-action ALTERs by stopping each USING at the next top-level action."""
    stripped = re.sub(
        r'\bUSING\b.*?(?=,\s*(?:ALTER|DROP|ADD|RENAME|VALIDATE|SET|ENABLE|DISABLE)\b|$)',
        '', stmt, flags=re.IGNORECASE | re.DOTALL)
    return stripped.rstrip().rstrip(';')


def detect_skip_reason(stmt, before_sql, validator=None, setup_sql=None):
    """Reasons a *PG-valid* pattern still can't be tested by DBDiff today.

    Statements that PostgreSQL itself rejects are NOT handled here: live
    validation classifies those as ``intentional_error``. This function keeps
    only genuine DBDiff feature gaps — cases where the ALTER succeeds in PG but
    DBDiff cannot yet reproduce the resulting schema (so the state fingerprint
    would differ). Everything else falls through to live validation.
    """
    upper = stmt.upper()
    before_upper = before_sql.upper()

    # Internal dropped-column placeholder columns ("........pg.dropped.N......")
    # cannot be expressed in normal DDL, so DBDiff can't reproduce them.
    if '........PG.DROPPED' in upper:
        return 'dropped_column_reference'

    # Unvalidated NOT NULL constraints (PG18 "ADD [CONSTRAINT] NOT NULL col
    # NOT VALID") — DBDiff models NOT NULL as a validated column attribute, so
    # it can't reproduce the unvalidated state. (CHECK ... NOT VALID is fine.)
    if re.search(r'NOT\s+NULL\s+\w+\s+NOT\s+VALID', upper) \
       or re.search(r'NOT\s+NULL\s+\w+\s+NOT\s+VALID', before_upper):
        return 'notnull_not_valid'

    # NO INHERIT constraints — DBDiff doesn't emit the NO INHERIT clause, so
    # pg_get_constraintdef differs.
    if re.search(r'\bNO\s+INHERIT\b', upper):
        return 'constraint_no_inherit'

    # PRIMARY KEY USING INDEX promotes an existing index to a PK and renames the
    # index to the constraint name; DBDiff emits a default-named PK + index, so
    # the constraint/index names won't match.
    if re.search(r'PRIMARY\s+KEY\s+USING\s+INDEX\b', upper):
        return 'primary_key_using_index'

    # ALTER COLUMN TYPE ... USING <expr>: DBDiff emits a plain TYPE change with
    # no USING clause. If PG cannot perform that cast without USING, DBDiff's
    # output fails to apply. Probe the no-USING form against live PG to decide;
    # keep only the casts DBDiff genuinely cannot reproduce.
    if re.search(r'\bTYPE\b.*\bUSING\b', upper, re.DOTALL):
        if validator is not None and getattr(validator, 'enabled', False):
            plain = _strip_using(stmt)
            if validator.check(setup_sql, before_sql, plain) != 'ok':
                return 'using_cast_expression'
        else:
            # Static fallback: only simple "col::type" casts are safe.
            m = re.search(r'\bTYPE\s+\w+.*\bUSING\s+(.+)', upper, re.DOTALL)
            expr = m.group(1).strip().rstrip(';').strip() if m else ''
            if not re.match(r'\(?\w+\)?::\w+$', expr):
                return 'using_cast_expression'

    return None


def detect_dependencies(before_sql, alter_sql, table_state=None, main_table=None):
    """Detect external type/function dependencies and return setup SQL."""
    combined = before_sql + ' ' + alter_sql
    setup = []
    seen = set()

    for name, create_sql in DEPENDENCY_REGISTRY.items():
        if name == main_table:
            continue  # the table under test provides its own CREATE
        if re.search(r'\b' + re.escape(name) + r'\b', combined, re.IGNORECASE):
            if name not in seen:
                setup.append(create_sql)
                seen.add(name)

    # FK REFERENCES: include referenced table's CREATE + ALTERs in setup
    if table_state:
        for ref_m in re.finditer(r'REFERENCES\s+(\w+)', combined, re.IGNORECASE):
            ref_table = ref_m.group(1).lower()
            if ref_table == main_table:
                continue
            if ref_table in table_state and ref_table not in seen:
                seen.add(ref_table)
                ref_state = table_state[ref_table]
                setup.append(ref_state['create'])
                for a in ref_state['alters']:
                    if 'REFERENCES' not in a.upper():
                        setup.append(a)

    return setup if setup else None


# ── Sequential extraction with cumulative state ──────────────────────────

def build_test_cases_sequential(sql_content, source_file, validator=None):
    """Build test cases with cumulative state tracking."""
    statements = split_statements(sql_content)

    # Per-table state: {name: {'create': str, 'alters': [str], 'skipped_constraints': set}}
    table_state = {}
    cases = []
    seen = set()

    live = validator is not None and validator.enabled
    if live:
        validator.reset()

    for stmt in statements:
        # Strip leading comments for classification
        clean_stmt = re.sub(r'^(--[^\n]*\n)+', '', stmt).strip()
        if not clean_stmt:
            continue

        # Check for error/fail markers in comments attached to this statement.
        has_fail_comment = False
        for line in stmt.split('\n'):
            if re.search(r'--.*(?:\bfail|\berror)', line, re.IGNORECASE):
                has_fail_comment = True
                break

        # Replay onto the ordered accumulation DB (source of truth for whether
        # a statement actually applies on an empty schema).
        applied_ok = validator.apply(clean_stmt) if live else (not has_fail_comment)

        if is_create_table(clean_stmt):
            table_name = get_create_table_name(clean_stmt)
            if table_name and (applied_ok or not live):
                table_state[table_name] = {
                    'create': get_create_table_body(stmt),
                    'alters': [],
                    'skipped_constraints': set(),
                    'dropped_columns': set(),
                }

        elif is_drop_table(clean_stmt):
            table_name = get_drop_table_name(clean_stmt)
            if table_name and table_name in table_state:
                del table_state[table_name]

        elif clean_stmt.upper().startswith('CREATE') and 'INDEX' in clean_stmt.upper():
            idx_m = re.search(r'CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?(\w+)\s+ON\s+(\w+)',
                              clean_stmt, re.IGNORECASE)
            if idx_m:
                tbl_name = idx_m.group(2).lower()
                accumulate = applied_ok if live else not has_fail_comment
                if tbl_name in table_state and accumulate:
                    table_state[tbl_name]['alters'].append(clean_stmt)

        elif is_alter_table(clean_stmt):
            table_name = get_alter_table_name(clean_stmt)
            if not table_name or table_name not in table_state:
                continue

            _emit_alter_pattern(
                clean_stmt, table_name, table_state, source_file, cases, seen,
                validator, live, applied_ok, has_fail_comment)

            _accumulate_alter(
                clean_stmt, table_name, table_state, live, applied_ok,
                has_fail_comment)

    return cases


def _emit_alter_pattern(alter_stmt, table_name, table_state, source_file,
                        cases, seen, validator, live, applied_ok, has_fail_comment):
    """Build and record a test pattern for a single ALTER, if classifiable."""
    if not live and has_fail_comment:
        return

    category = classify_alter(alter_stmt)
    if not category or not is_safe_alter_sql(alter_stmt):
        return

    state = table_state[table_name]
    create_sql = state['create']
    if not is_safe_create_sql(create_sql):
        return

    before_sql = '\n'.join([create_sql] + state['alters'])
    setup_sql = detect_dependencies(
        before_sql, alter_stmt, table_state, main_table=table_name)
    min_version = detect_min_pg_version(before_sql, alter_stmt)

    # skip_reason precedence: DBDiff feature limitations first (a live-valid
    # ALTER we can't yet reproduce), then live PG outcome.
    skip_reason = detect_skip_reason(
        alter_stmt, before_sql,
        validator=validator if live else None, setup_sql=setup_sql)
    if not skip_reason:
        if live:
            status = validator.check(setup_sql, before_sql, alter_stmt)
            if status == 'alter_fail':
                skip_reason = 'intentional_error'
            elif status == 'before_fail':
                skip_reason = 'before_not_self_contained'
        else:
            if 'REFERENCES' in alter_stmt.upper():
                ref_table = _extract_fk_referenced_table(alter_stmt)
                if ref_table and ref_table not in table_state:
                    skip_reason = 'references_external_table'

    key = f"{category}:{table_name}:{alter_stmt[:80]}"
    if key in seen:
        return
    seen.add(key)

    pattern = {
        'id': f"{source_file}_{category}_{len(cases)+1}",
        'source_file': source_file,
        'category': category,
        'table': table_name,
        'before_sql': before_sql,
        'alter_sql': alter_stmt,
        'description': f"{category}: {alter_stmt[:100]}",
    }
    if setup_sql:
        pattern['setup_sql'] = setup_sql
    if min_version:
        pattern['min_pg_version'] = min_version
    if skip_reason:
        pattern['skip_reason'] = skip_reason
    cases.append(pattern)


def _accumulate_alter(clean_stmt, table_name, table_state, live, applied_ok,
                      has_fail_comment):
    """Accumulate a schema-modifying ALTER into before_sql for later patterns."""
    if not is_schema_modifying_alter(clean_stmt):
        return
    if '........pg.dropped' in clean_stmt.lower():
        return

    if live:
        # Authoritative: accumulate iff it actually applied on the empty schema.
        if not applied_ok:
            return
    else:
        has_inline_fail = any(
            (not ln.strip().startswith('--')) and ln.strip()
            and re.search(r'--.*(?:\bfail|\berror)', ln, re.IGNORECASE)
            for ln in clean_stmt.split('\n'))
        if has_inline_fail:
            return
        if is_unsafe_accumulation(clean_stmt, table_state):
            constr_m = re.search(r'ADD\s+CONSTRAINT\s+"?(\w+)"?', clean_stmt, re.IGNORECASE)
            if constr_m:
                table_state[table_name]['skipped_constraints'].add(constr_m.group(1).lower())
            return
        if _drops_skipped_constraint(clean_stmt, table_state[table_name]):
            return
        if _conflicts_with_state(clean_stmt, table_state[table_name]):
            return
        if _references_dropped_column(clean_stmt, table_state[table_name]):
            return

    table_state[table_name]['alters'].append(clean_stmt)
    drop_m = re.search(r'DROP\s+(?:COLUMN\s+)?(\w+)', clean_stmt, re.IGNORECASE)
    if drop_m:
        col = drop_m.group(1).lower()
        if col not in ('column', 'constraint', 'default', 'not', 'identity', 'expression'):
            table_state[table_name]['dropped_columns'].add(col)


# ── Main ─────────────────────────────────────────────────────────────────

def main():
    regression_dir = '/tmp'
    source_files = {
        'alter_table': os.path.join(regression_dir, 'alter_table.sql'),
        'constraints': os.path.join(regression_dir, 'pg_regress_constraints.sql'),
        'create_index': os.path.join(regression_dir, 'pg_regress_create_index.sql'),
        'identity': os.path.join(regression_dir, 'pg_regress_identity.sql'),
        'generated_stored': os.path.join(regression_dir, 'pg_regress_generated_stored.sql'),
        'domain': os.path.join(regression_dir, 'pg_regress_domain.sql'),
        'enum': os.path.join(regression_dir, 'pg_regress_enum.sql'),
    }

    # Live validation against a real Postgres, when a DSN is provided. This
    # makes accumulation and error-test detection reflect actual PG behaviour
    # on empty tables, so before_sql is always valid and there are no silent
    # runtime skips. Falls back to static heuristics when no DSN is available.
    dsn = os.environ.get('PGCONF_DSN')
    validator = PgValidator(dsn)
    if validator.enabled:
        print("  Live validation: ON", file=sys.stderr)
    elif dsn:
        # A DSN was supplied but validation could not initialise — fail loudly
        # rather than emit a degraded pattern set that would cause silent skips.
        print("  ERROR: PGCONF_DSN is set but live validation could not be "
              "enabled (psycopg2 missing or connection failed).", file=sys.stderr)
        sys.exit(1)
    else:
        print("  Live validation: OFF (set PGCONF_DSN to enable)", file=sys.stderr)

    all_cases = []
    for name, path in source_files.items():
        if not os.path.exists(path):
            print(f"  SKIP: {path} not found", file=sys.stderr)
            continue
        with open(path) as f:
            sql = f.read()
        cases = build_test_cases_sequential(sql, name, validator)
        print(f"  {name}: {len(cases)} test cases extracted", file=sys.stderr)
        all_cases.extend(cases)

    # Summary by category
    cats = {}
    skip_reasons = {}
    version_gated = 0
    with_setup = 0
    for c in all_cases:
        cats[c['category']] = cats.get(c['category'], 0) + 1
        if 'skip_reason' in c:
            r = c['skip_reason']
            skip_reasons[r] = skip_reasons.get(r, 0) + 1
        if 'min_pg_version' in c:
            version_gated += 1
        if 'setup_sql' in c:
            with_setup += 1

    print(f"\n  Total: {len(all_cases)} test cases", file=sys.stderr)
    for cat, count in sorted(cats.items(), key=lambda x: -x[1]):
        print(f"    {cat}: {count}", file=sys.stderr)
    if skip_reasons:
        print(f"\n  Pre-excluded: {sum(skip_reasons.values())}", file=sys.stderr)
        for reason, count in sorted(skip_reasons.items()):
            print(f"    {reason}: {count}", file=sys.stderr)
    if version_gated:
        print(f"  Version-gated (PG 17+): {version_gated}", file=sys.stderr)
    if with_setup:
        print(f"  With setup_sql: {with_setup}", file=sys.stderr)

    os.makedirs(PATTERNS_DIR, exist_ok=True)
    out_path = os.path.join(PATTERNS_DIR, 'patterns.json')
    with open(out_path, 'w') as f:
        json.dump(all_cases, f, indent=2)
    print(f"\n  Written to: {out_path}", file=sys.stderr)


if __name__ == '__main__':
    main()
