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


def detect_skip_reason(stmt, before_sql):
    """Detect if a pattern should be excluded from testing."""
    upper = stmt.upper()
    before_upper = before_sql.upper()

    # PG18 named NOT NULL constraints — DBDiff doesn't yet read contype='n'
    if re.search(r'ADD\s+CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+', upper):
        return 'named_notnull_unsupported'
    # PG18 bare ADD NOT NULL (without CONSTRAINT keyword)
    if re.search(r'\bADD\s+NOT\s+NULL\s+\w+', upper):
        return 'named_notnull_unsupported'
    # Before_sql depends on PG18 named NOT NULL constraints
    if re.search(r'ADD\s+CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+', before_upper):
        return 'named_notnull_unsupported'
    if re.search(r'\bADD\s+NOT\s+NULL\s+\w+', before_upper):
        return 'named_notnull_unsupported'

    # System column references
    system_cols = r'(xmin|ctid|cmin|xmax|cmax|tableoid)'
    if re.search(r'ADD\s+COLUMN\s+' + system_cols + r'\b', upper):
        return 'system_column_conflict'
    if re.search(r'ALTER\s+COLUMN\s+' + system_cols + r'\b', upper):
        return 'system_column_alter'

    # ALTER to a non-standard type that won't exist in test databases
    if re.search(r'SET\s+DATA\s+TYPE\s+x\b', upper, re.IGNORECASE):
        return 'nonexistent_type'

    # NOT ENFORCED ENFORCED (toggle constraint enforcement) — not diffable
    if re.search(r'NOT\s+ENFORCED\s+ENFORCED', upper):
        return 'enforcement_toggle'

    # COLLATE in ALTER TYPE — requires specific collation setup
    if re.search(r'SET\s+DATA\s+TYPE\s+\w+\s+COLLATE\b', upper):
        return 'collate_in_type_change'

    # ALTER TYPE with USING expression for incompatible cast —
    # DBDiff can't infer arbitrary conversion expressions.
    # Simple casts like "USING col::type" are fine (PG handles them implicitly),
    # so only skip complex expressions (CASE, function calls, operators, etc.)
    using_match = re.search(r'\bTYPE\s+(\w+).*\bUSING\s+(.+)', upper, re.DOTALL)
    if using_match:
        using_expr = using_match.group(2).strip().rstrip(';').strip()
        # Simple cast: "colname::type" or "(colname)::type"
        if not re.match(r'\(?\w+\)?::\w+$', using_expr):
            return 'using_cast_expression'

    # PRIMARY KEY USING INDEX — PG-specific syntax to promote an
    # existing index to a PK constraint; DBDiff doesn't support this
    if re.search(r'PRIMARY\s+KEY\s+USING\s+INDEX\b', upper):
        return 'primary_key_using_index'

    # Multiple named NOT NULL constraints on the same column in before_sql
    notnull_cols = re.findall(r'ADD\s+CONSTRAINT\s+\w+\s+NOT\s+NULL\s+(\w+)', before_upper)
    if len(notnull_cols) != len(set(c.lower() for c in notnull_cols)):
        return 'duplicate_named_notnull'

    # Dropped-column internal references — only skip if the ALTER itself uses them
    if '........PG.DROPPED' in upper:
        return 'dropped_column_reference'

    # DROP COLUMN non_existing (without IF EXISTS) — tests error handling
    if re.search(r'DROP\s+COLUMN\s+NON_EXISTING\b', upper) and 'IF EXISTS' not in upper:
        return 'intentional_error'

    # DROP COLUMN when a generated column in before_sql depends on it
    # (PG will error without CASCADE)
    drop_col_m = re.search(r'DROP\s+(?:COLUMN\s+)?(\w+)', upper)
    if drop_col_m:
        dcol = drop_col_m.group(1).lower()
        if dcol not in ('column', 'constraint', 'default', 'not', 'identity', 'expression', 'if'):
            gen_dep = re.search(
                r'GENERATED\s+ALWAYS\s+AS\s*\([^)]*\b' + re.escape(dcol) + r'\b',
                before_sql, re.IGNORECASE)
            if gen_dep:
                return 'generated_column_dependency'

    # Intentional wrong-type error tests
    if 'WRONG_DATATYPE' in upper or 'WRONG_DATATYPE' in before_upper:
        return 'intentional_error'

    # Identity column type change to text — intentional PG error
    if re.search(r'ALTER\s+COLUMN\s+\w+\s+TYPE\s+TEXT', upper):
        if 'IDENTITY' in before_upper:
            return 'identity_type_restriction'

    # Identity column already exists — can't add identity again or change type
    if 'IDENTITY' in before_upper:
        if 'ADD GENERATED' in upper:
            return 'identity_already_exists'
        # Can't change type of identity column (PG restriction)
        if re.search(r'ALTER\s+COLUMN\s+\w+\s+TYPE\s', upper):
            return 'identity_type_restriction'

    # ADD GENERATED on a column that already has a default (serial or explicit)
    gen_col_match = re.search(r'ALTER\s+COLUMN\s+(\w+)\s+ADD\s+GENERATED', upper)
    if gen_col_match:
        col = gen_col_match.group(1).lower()
        if re.search(r'\b' + re.escape(col) + r'\b\s+(?:big)?serial\b', before_sql, re.IGNORECASE):
            return 'identity_already_exists'
        if re.search(r'ALTER\s+(?:COLUMN\s+)?' + re.escape(col) + r'\s+SET\s+DEFAULT', before_sql, re.IGNORECASE):
            return 'identity_already_exists'

    # Generated column dependency errors — can't add column referencing generated col
    if 'GENERATED ALWAYS AS' in before_upper:
        if 'ADD COLUMN' in upper and 'GENERATED ALWAYS AS' in upper:
            # Check if the expression references an existing generated column
            gen_cols = re.findall(r'(\w+)\s+\w+\s+GENERATED\s+ALWAYS\s+AS', before_upper)
            expr_match = re.search(r'GENERATED\s+ALWAYS\s+AS\s*\(([^)]+)\)', upper)
            if expr_match and gen_cols:
                expr = expr_match.group(1).upper()
                for gc in gen_cols:
                    if gc in expr:
                        return 'generated_column_dependency'

    # Patterns that try to add columns already in before_sql (duplicate tests)
    col_match = re.search(r'ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)', upper)
    if col_match and 'IF NOT EXISTS' not in upper:
        col_name = col_match.group(1).lower()
        if re.search(r'\b' + re.escape(col_name) + r'\b', before_sql, re.IGNORECASE):
            return 'duplicate_column'

    # Patterns that add a PRIMARY KEY when one already exists in before_sql
    if re.search(r'PRIMARY\s+KEY', upper) and 'DROP' not in upper:
        if re.search(r'PRIMARY\s+KEY', before_upper):
            return 'multiple_primary_keys'

    # DROP NOT NULL on a PK column — Postgres rejects this
    notnull_match = re.search(r'ALTER\s+(?:COLUMN\s+)?(\w+)\s+DROP\s+NOT\s+NULL', upper)
    if notnull_match:
        col = notnull_match.group(1).lower()
        if col != 'column':
            pk_matches = re.findall(r'PRIMARY\s+KEY\s*\(([^)]+)\)', before_upper)
            for pk_cols in pk_matches:
                if re.search(r'\b' + re.escape(col.upper()) + r'\b', pk_cols):
                    return 'intentional_error'
            if re.search(r'\b' + re.escape(col) + r'\b\s+\w+.*\bPRIMARY\s+KEY\b',
                         before_sql, re.IGNORECASE):
                return 'intentional_error'

    # SET NOT NULL on a PK column — valid but redundant (no-op)
    notnull_set = re.search(r'ALTER\s+(?:COLUMN\s+)?(\w+)\s+SET\s+NOT\s+NULL', upper)
    if notnull_set:
        col = notnull_set.group(1).lower()
        if col != 'column':
            pk_matches = re.findall(r'PRIMARY\s+KEY\s*\(([^)]+)\)', before_upper)
            for pk_cols in pk_matches:
                if re.search(r'\b' + re.escape(col.upper()) + r'\b', pk_cols):
                    pass  # Valid no-op, let it be tested

    # Multiple PRIMARY KEYs in before_sql — indicates state poisoning
    pk_count = len(re.findall(r'PRIMARY\s+KEY', before_upper))
    if pk_count > 1:
        return 'state_has_multiple_pks'

    # Before_sql accumulates a DROP CONSTRAINT on a named NOT NULL (PG17 only)
    for drop_m in re.finditer(r'DROP\s+CONSTRAINT\s+"?(\w+)"?', before_upper):
        cname = drop_m.group(1).lower()
        if re.search(r'CONSTRAINT\s+' + re.escape(cname) + r'\s+NOT\s+NULL',
                     before_sql, re.IGNORECASE):
            return 'named_notnull_constraint'

    # Combined context: before_sql + the ALTER itself (for multi-action ALTERs
    # like ADD COLUMN a INT, ALTER a SET NOT NULL)
    combined_upper = before_upper + ' ' + upper

    # Helper: check if a column exists in context (before_sql or the ALTER itself)
    combined = before_sql + '\n' + stmt
    def _col_exists(col_name):
        if not re.search(r'\b' + re.escape(col_name) + r'\b', combined, re.IGNORECASE):
            return False
        # Column was dropped in before_sql
        if re.search(r'DROP\s+(?:COLUMN\s+)?' + re.escape(col_name) + r'\b', before_sql, re.IGNORECASE):
            return False
        return True

    def _col_defined(col_name):
        """Check if column is defined (not just referenced) in before_sql or ALTER."""
        in_create = re.search(r'\b' + re.escape(col_name) + r'\b\s+\w+', before_sql, re.IGNORECASE)
        in_add = re.search(r'ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?' + re.escape(col_name) + r'\b',
                           stmt, re.IGNORECASE)
        return bool(in_create or in_add)

    # Patterns that reference columns not defined anywhere
    key_col = re.search(r'(?:UNIQUE|PRIMARY\s+KEY)\s*\(\s*(\w+)', upper)
    if key_col:
        ref_col = key_col.group(1).lower()
        if ref_col not in ('value',) and not _col_exists(ref_col):
            return 'references_nonexistent_column'

    # CHECK constraint on non-existent column
    check_match = re.search(r'CHECK\s*\(\s*(\w+)', upper)
    if check_match:
        ref_col = check_match.group(1).lower()
        if ref_col not in ('value',) and not _col_exists(ref_col):
            return 'references_nonexistent_column'

    # DROP CONSTRAINT on constraint not in before_sql
    drop_constr = re.search(r'DROP\s+CONSTRAINT\s+(?:IF\s+EXISTS\s+)?"?(\w+)"?', upper)
    if drop_constr:
        constr_name = drop_constr.group(1).lower()
        if constr_name != 'if' and not re.search(r'\b' + re.escape(constr_name) + r'\b', before_sql, re.IGNORECASE):
            return 'references_nonexistent_constraint'
        if re.search(r'CONSTRAINT\s+' + re.escape(constr_name) + r'\s+NOT\s+NULL',
                     before_sql, re.IGNORECASE):
            return 'named_notnull_constraint'

    # ALTER column operations on non-existent or dropped columns
    alter_col = re.search(r'ALTER\s+(?:COLUMN\s+)?(\w+)\s+(?:SET|DROP)\s+(?:NOT\s+NULL|DEFAULT)', upper)
    if alter_col:
        ref_col = alter_col.group(1).lower()
        if ref_col != 'column' and not _col_defined(ref_col):
            return 'references_nonexistent_column'

    # FK REFERENCES on dropped column
    fk_col = re.search(r'(?:FOREIGN\s+KEY|ADD\s+FOREIGN\s+KEY)\s*\(\s*(\w+)', upper)
    if fk_col:
        ref_col = fk_col.group(1).lower()
        if not _col_exists(ref_col):
            return 'references_nonexistent_column'

    # Self-referencing FK on a table without PK/UNIQUE — PG requires
    # the referenced column(s) to have a unique constraint
    ref_m = re.search(r'REFERENCES\s+(\w+)', upper)
    if ref_m:
        ref_table = ref_m.group(1).lower()
        tbl_m = re.search(r'ALTER\s+TABLE\s+(?:ONLY\s+)?(\w+)', upper)
        own_table = tbl_m.group(1).lower() if tbl_m else ''
        if ref_table == own_table:
            if not re.search(r'PRIMARY\s+KEY|UNIQUE', before_upper):
                return 'self_ref_fk_no_pk'

    return None


def detect_dependencies(before_sql, alter_sql, table_state=None, main_table=None):
    """Detect external type/function dependencies and return setup SQL."""
    combined = before_sql + ' ' + alter_sql
    setup = []
    seen = set()

    for name, create_sql in DEPENDENCY_REGISTRY.items():
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

def build_test_cases_sequential(sql_content, source_file):
    """Build test cases with cumulative state tracking."""
    statements = split_statements(sql_content)

    # Per-table state: {name: {'create': str, 'alters': [str], 'skipped_constraints': set}}
    table_state = {}
    cases = []
    seen = set()

    for stmt in statements:
        # Strip leading comments for classification
        clean_stmt = re.sub(r'^(--[^\n]*\n)+', '', stmt).strip()
        if not clean_stmt:
            continue

        # Check for error/fail markers in comments attached to this statement.
        # Include all preceding comment lines (they're part of this statement
        # block) and inline comments on SQL lines.
        has_fail_comment = False
        stmt_lines = stmt.split('\n')
        for line in stmt_lines:
            if re.search(r'--.*(?:\bfail|\berror)', line, re.IGNORECASE):
                has_fail_comment = True
                break

        if is_create_table(clean_stmt):
            table_name = get_create_table_name(clean_stmt)
            if table_name:
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
                idx_name = idx_m.group(1).lower()
                tbl_name = idx_m.group(2).lower()
                if tbl_name in table_state and not has_fail_comment:
                    table_state[tbl_name]['alters'].append(clean_stmt)

        elif is_alter_table(clean_stmt):
            table_name = get_alter_table_name(clean_stmt)
            if not table_name or table_name not in table_state:
                continue

            if not has_fail_comment:
                alter_stmt = clean_stmt
                category = classify_alter(alter_stmt)

                if category and is_safe_alter_sql(alter_stmt):
                    state = table_state[table_name]
                    create_sql = state['create']

                    if is_safe_create_sql(create_sql):
                        before_parts = [create_sql]
                        before_parts.extend(state['alters'])
                        before_sql = '\n'.join(before_parts)

                        skip_reason = detect_skip_reason(alter_stmt, before_sql)
                        min_version = detect_min_pg_version(before_sql, alter_stmt)
                        setup_sql = detect_dependencies(
                            before_sql, alter_stmt, table_state,
                            main_table=table_name)

                        # FK REFERENCES: need referenced table in setup
                        if 'REFERENCES' in alter_stmt.upper():
                            ref_table = _extract_fk_referenced_table(alter_stmt)
                            if ref_table and ref_table not in table_state:
                                skip_reason = skip_reason or 'references_external_table'

                        key = f"{category}:{table_name}:{alter_stmt[:80]}"
                        if key not in seen:
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

            # Accumulate schema-modifying ALTERs for future before_sql.
            # For accumulation, only skip on inline fail comments (on a SQL
            # line, not a preceding comment that describes test intent).
            has_inline_fail = False
            for ln in clean_stmt.split('\n'):
                stripped_ln = ln.strip()
                if stripped_ln and not stripped_ln.startswith('--'):
                    if re.search(r'--.*(?:\bfail|\berror)', ln, re.IGNORECASE):
                        has_inline_fail = True
                        break
            if is_schema_modifying_alter(clean_stmt) and not has_inline_fail:
                # Never accumulate dropped-column references
                if '........pg.dropped' in clean_stmt.lower():
                    pass
                elif is_unsafe_accumulation(clean_stmt, table_state):
                    constr_m = re.search(
                        r'ADD\s+CONSTRAINT\s+"?(\w+)"?', clean_stmt, re.IGNORECASE)
                    if constr_m:
                        table_state[table_name]['skipped_constraints'].add(
                            constr_m.group(1).lower())
                elif _drops_skipped_constraint(clean_stmt, table_state[table_name]):
                    pass
                elif _conflicts_with_state(clean_stmt, table_state[table_name]):
                    pass
                elif _references_dropped_column(clean_stmt, table_state[table_name]):
                    pass
                else:
                    table_state[table_name]['alters'].append(clean_stmt)
                    # Track dropped columns
                    drop_m = re.search(
                        r'DROP\s+(?:COLUMN\s+)?(\w+)', clean_stmt, re.IGNORECASE)
                    if drop_m:
                        col = drop_m.group(1).lower()
                        if col not in ('column', 'constraint', 'default', 'not', 'identity', 'expression'):
                            table_state[table_name]['dropped_columns'].add(col)

    return cases


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

    all_cases = []
    for name, path in source_files.items():
        if not os.path.exists(path):
            print(f"  SKIP: {path} not found", file=sys.stderr)
            continue
        with open(path) as f:
            sql = f.read()
        cases = build_test_cases_sequential(sql, name)
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
