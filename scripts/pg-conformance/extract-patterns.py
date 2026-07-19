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

        # Statement boundary: line ends with ; and we're not in dollar quote
        if not in_dollar and stripped.endswith(';'):
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
        'ADD CHECK', 'ADD FOREIGN KEY', 'DROP IDENTITY', 'ADD GENERATED',
        'DROP EXPRESSION',
    ]
    return any(m in upper for m in modifiers)


def classify_alter(stmt):
    """Classify an ALTER TABLE statement into a testable category."""
    upper = stmt.upper()

    # Skip non-schema-diffable operations
    skip_patterns = [
        'OWNER TO', 'SET TABLESPACE', 'CLUSTER ON', 'SET WITHOUT CLUSTER',
        'ENABLE TRIGGER', 'DISABLE TRIGGER', 'ENABLE RULE', 'DISABLE RULE',
        'SET SCHEMA', 'RENAME TO', 'RENAME COLUMN', 'RENAME CONSTRAINT',
        'SET (', 'RESET (', 'ENABLE ROW LEVEL', 'DISABLE ROW LEVEL',
        'FORCE ROW LEVEL', 'NO FORCE ROW', 'INHERIT', 'NO INHERIT',
        'OF ', 'NOT OF', 'REPLICA IDENTITY', 'SET LOGGED', 'SET UNLOGGED',
        'ATTACH PARTITION', 'DETACH PARTITION', 'SET ACCESS METHOD',
        'SET STATISTICS', 'SET STORAGE', 'SET COMPRESSION',
        'ALTER COLUMN', 'VALIDATE CONSTRAINT',
    ]
    for p in skip_patterns:
        if p in upper:
            if 'ALTER COLUMN' in upper:
                diffable = ['SET DEFAULT', 'DROP DEFAULT', 'SET NOT NULL',
                           'DROP NOT NULL', 'SET DATA TYPE', ' TYPE ']
                if any(d in upper for d in diffable):
                    break
            else:
                return None

    if 'ADD COLUMN' in upper:
        return 'add_column'
    elif 'DROP COLUMN' in upper:
        return 'drop_column'
    elif 'ADD CONSTRAINT' in upper:
        return 'add_constraint'
    elif 'DROP CONSTRAINT' in upper:
        return 'drop_constraint'
    elif 'SET DATA TYPE' in upper or re.search(r'ALTER\s+COLUMN\s+\w+\s+TYPE\s', upper):
        return 'change_type'
    elif 'SET NOT NULL' in upper:
        return 'set_not_null'
    elif 'DROP NOT NULL' in upper:
        return 'drop_not_null'
    elif 'SET DEFAULT' in upper:
        return 'set_default'
    elif 'DROP DEFAULT' in upper:
        return 'drop_default'

    return None


# ── Safety and version checks ────────────────────────────────────────────

def is_safe_sql(stmt):
    """Check if a SQL statement is safe to run in isolation."""
    upper = stmt.upper().strip()
    unsafe = ['USING', 'REFERENCES', 'LIKE ', 'INHERITS', 'PARTITION',
              'regress_', 'attmp_log', 'pg_temp',
              'AT_TAB2', 'ATTMP2']  # Tables used via INHERITS/FK in other patterns
    return not any(u in upper for u in unsafe)


def detect_min_pg_version(before_sql, alter_sql):
    """Detect minimum PG version needed for this pattern."""
    combined = (before_sql + ' ' + alter_sql).upper()

    if 'NOT ENFORCED' in combined:
        return 17
    # PG 17 constraint-style NOT NULL: ADD CONSTRAINT <name> NOT NULL <col>
    if re.search(r'ADD\s+CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+', combined):
        return 17
    # Constraint definition in CREATE TABLE: CONSTRAINT <name> NOT NULL <col>
    if re.search(r'CONSTRAINT\s+\w+\s+NOT\s+NULL\s+\w+', combined):
        return 17

    return None


def detect_skip_reason(stmt, before_sql):
    """Detect if a pattern should be excluded from testing."""
    upper = stmt.upper()
    before_upper = before_sql.upper()

    # System column name conflicts
    if re.search(r'ADD\s+COLUMN\s+XMIN\b', upper):
        return 'system_column_conflict'

    # Dropped-column internal references
    if '........PG.DROPPED' in upper or '........PG.DROPPED' in before_upper:
        return 'dropped_column_reference'

    # DROP COLUMN non_existing (without IF EXISTS) — tests error handling
    if re.search(r'DROP\s+COLUMN\s+NON_EXISTING\b', upper) and 'IF EXISTS' not in upper:
        return 'intentional_error'

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
    if col_match:
        col_name = col_match.group(1).lower()
        # Check if column already exists in before_sql
        if re.search(r'\b' + re.escape(col_name) + r'\b', before_sql, re.IGNORECASE):
            # ADD COLUMN IF NOT EXISTS is fine (no-op)
            if 'IF NOT EXISTS' in upper:
                return 'no_op_if_not_exists'
            else:
                return 'duplicate_column'

    # Patterns that add a PRIMARY KEY when one already exists in before_sql
    if re.search(r'PRIMARY\s+KEY', upper) and 'DROP' not in upper:
        if re.search(r'PRIMARY\s+KEY', before_upper):
            return 'multiple_primary_keys'

    # SET/DROP NOT NULL on a column that's part of a PRIMARY KEY in before_sql
    notnull_match = re.search(r'ALTER\s+(?:COLUMN\s+)?(\w+)\s+(?:SET|DROP)\s+NOT\s+NULL', upper)
    if notnull_match:
        col = notnull_match.group(1).lower()
        if col != 'column':
            # Check if this column is in a PRIMARY KEY in before_sql
            pk_match = re.search(r'PRIMARY\s+KEY\s*\(([^)]+)\)', before_upper)
            if pk_match and re.search(r'\b' + re.escape(col.upper()) + r'\b', pk_match.group(1)):
                return 'column_in_primary_key'

    # DROP CONSTRAINT on a PK when other accumulated ALTERs depend on it
    if re.search(r'DROP\s+CONSTRAINT', upper):
        # If before has a PK and the alter drops it, check for dependencies
        if re.search(r'PRIMARY\s+KEY', before_upper) and 'PKEY' in upper:
            pk_col_match = re.search(r'PRIMARY\s+KEY\s*\(([^)]+)\)', before_upper)
            if pk_col_match:
                return 'drop_pk_with_dependencies'

    # Patterns that reference columns not present in before_sql
    # UNIQUE/PRIMARY KEY on non-existent columns
    key_col = re.search(r'(?:UNIQUE|PRIMARY\s+KEY)\s*\(\s*(\w+)', upper)
    if key_col:
        ref_col = key_col.group(1).lower()
        if not re.search(r'\b' + re.escape(ref_col) + r'\b', before_sql, re.IGNORECASE):
            return 'references_nonexistent_column'

    # CHECK constraint on non-existent column
    check_match = re.search(r'CHECK\s*\(\s*(\w+)', upper)
    if check_match:
        ref_col = check_match.group(1).lower()
        if not re.search(r'\b' + re.escape(ref_col) + r'\b', before_sql, re.IGNORECASE):
            return 'references_nonexistent_column'

    # DROP CONSTRAINT on constraint not in before_sql
    drop_constr = re.search(r'DROP\s+CONSTRAINT\s+(?:IF\s+EXISTS\s+)?"?(\w+)"?', upper)
    if drop_constr:
        constr_name = drop_constr.group(1).lower()
        if constr_name != 'if' and not re.search(r'\b' + re.escape(constr_name) + r'\b', before_sql, re.IGNORECASE):
            return 'references_nonexistent_constraint'

    # ALTER/DROP NOT NULL on column not in before_sql
    notnull_col = re.search(r'ALTER\s+(?:COLUMN\s+)?(\w+)\s+(?:SET|DROP)\s+NOT\s+NULL', upper)
    if notnull_col:
        ref_col = notnull_col.group(1).lower()
        if ref_col != 'column' and not re.search(r'\b' + re.escape(ref_col) + r'\b', before_sql, re.IGNORECASE):
            return 'references_nonexistent_column'

    return None


def detect_dependencies(before_sql, alter_sql):
    """Detect external type/function dependencies and return setup SQL."""
    combined = before_sql + ' ' + alter_sql
    setup = []
    seen = set()

    for name, create_sql in DEPENDENCY_REGISTRY.items():
        # Check if this dependency name appears as a type or function reference
        if re.search(r'\b' + re.escape(name) + r'\b', combined, re.IGNORECASE):
            if name not in seen:
                setup.append(create_sql)
                seen.add(name)

    return setup if setup else None


# ── Sequential extraction with cumulative state ──────────────────────────

def build_test_cases_sequential(sql_content, source_file):
    """Build test cases with cumulative state tracking."""
    statements = split_statements(sql_content)

    # Per-table state: {name: {'create': str, 'alters': [str]}}
    table_state = {}
    cases = []
    seen = set()

    for stmt in statements:
        # Strip leading comments for classification
        clean_stmt = re.sub(r'^(--[^\n]*\n)+', '', stmt).strip()
        if not clean_stmt:
            continue

        # Check for "-- fails" / "-- fail" in preceding comments
        has_fail_comment = bool(re.search(r'--\s*fail', stmt, re.IGNORECASE))

        if is_create_table(clean_stmt):
            table_name = get_create_table_name(clean_stmt)
            if table_name:
                table_state[table_name] = {
                    'create': get_create_table_body(stmt),
                    'alters': [],
                }

        elif is_drop_table(clean_stmt):
            table_name = get_drop_table_name(clean_stmt)
            if table_name and table_name in table_state:
                del table_state[table_name]

        elif is_alter_table(clean_stmt):
            table_name = get_alter_table_name(clean_stmt)
            if not table_name or table_name not in table_state:
                continue

            # Skip statements marked as intentional failures
            if has_fail_comment:
                # Still accumulate if it's schema-modifying (some "fail" comments
                # are for the test output but the statement actually succeeds)
                pass
            else:
                alter_stmt = clean_stmt
                category = classify_alter(alter_stmt)

                if category and is_safe_sql(alter_stmt):
                    state = table_state[table_name]
                    create_sql = state['create']

                    # Safety check: skip if create_sql itself is unsafe
                    if is_safe_sql(create_sql):
                        # Build before_sql: CREATE + all preceding ALTERs
                        before_parts = [create_sql]
                        before_parts.extend(state['alters'])
                        before_sql = '\n'.join(before_parts)

                        # Check for skip reasons
                        skip_reason = detect_skip_reason(alter_stmt, before_sql)

                        # Check PG version requirements
                        min_version = detect_min_pg_version(before_sql, alter_stmt)

                        # Check external dependencies
                        setup_sql = detect_dependencies(before_sql, alter_stmt)

                        # Deduplicate
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

            # Accumulate schema-modifying ALTERs for future patterns
            if is_schema_modifying_alter(clean_stmt) and not has_fail_comment:
                table_state[table_name]['alters'].append(clean_stmt)

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
