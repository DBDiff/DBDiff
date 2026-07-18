#!/usr/bin/env python3
"""
Extract testable DDL pattern pairs from Postgres regression SQL files.

Scans regression .sql files for CREATE TABLE + ALTER TABLE sequences and
produces isolated (before, after) schema pairs that DBDiff can diff.

Each pair is a self-contained test: create db1 from "before", create db2
from "before + alteration", run dbdiff, apply UP to db1, assert match.

Output: JSON array of test cases to tests/pg-conformance/patterns.json
"""

import re
import json
import sys
import os

PATTERNS_DIR = os.path.join(os.path.dirname(__file__), '..', '..', 'tests', 'pg-conformance')

def extract_create_tables(sql):
    """Extract all CREATE TABLE statements with their table names."""
    tables = {}
    pattern = re.compile(
        r'CREATE\s+(?:UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'
        r'(\w+)\s*\((.*?)\)\s*;',
        re.DOTALL | re.IGNORECASE
    )
    for m in pattern.finditer(sql):
        name = m.group(1).lower()
        full = m.group(0)
        tables[name] = full
    return tables


def extract_alter_blocks(sql):
    """Extract ALTER TABLE statements paired with their target table."""
    alters = []
    pattern = re.compile(
        r'(ALTER\s+TABLE\s+(?:ONLY\s+)?(\w+)\s+.*?;)',
        re.DOTALL | re.IGNORECASE
    )
    for m in pattern.finditer(sql):
        stmt = m.group(1).strip()
        table = m.group(2).lower()

        # Skip statements we know will fail (comments say so)
        line_start = sql.rfind('\n', 0, m.start())
        preceding = sql[max(0, line_start):m.start()]
        if '-- fails' in preceding or '-- fail' in preceding:
            continue

        # Classify the ALTER
        category = classify_alter(stmt)
        if category:
            alters.append({
                'table': table,
                'statement': stmt,
                'category': category,
            })
    return alters


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
            # But allow specific ALTER COLUMN sub-patterns we CAN diff
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
    elif 'ADD COLUMN' in upper:
        return 'add_column'

    return None


def is_safe_sql(stmt):
    """Check if a SQL statement is safe to run in isolation."""
    upper = stmt.upper().strip()
    # Skip statements referencing functions, types, or other objects
    # that won't exist in our isolated test
    unsafe = ['USING', 'REFERENCES', 'LIKE ', 'INHERITS', 'PARTITION',
              'regress_', 'attmp_log', 'pg_temp']
    return not any(u in upper for u in unsafe)


def build_test_cases(sql_content, source_file):
    """Build isolated test cases from a regression SQL file."""
    tables = extract_create_tables(sql_content)
    alters = extract_alter_blocks(sql_content)

    cases = []
    seen = set()

    for alter in alters:
        tname = alter['table']
        if tname not in tables:
            continue

        create_stmt = tables[tname]
        alter_stmt = alter['statement']
        category = alter['category']

        # Skip complex statements that reference other objects
        if not is_safe_sql(create_stmt) or not is_safe_sql(alter_stmt):
            continue

        # Deduplicate by category + table
        key = f"{category}:{tname}:{alter_stmt[:80]}"
        if key in seen:
            continue
        seen.add(key)

        cases.append({
            'id': f"{source_file}_{category}_{len(cases)+1}",
            'source_file': source_file,
            'category': category,
            'table': tname,
            'before_sql': create_stmt,
            'alter_sql': alter_stmt,
            'description': f"{category}: {alter_stmt[:100]}",
        })

    return cases


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
        cases = build_test_cases(sql, name)
        print(f"  {name}: {len(cases)} test cases extracted", file=sys.stderr)
        all_cases.extend(cases)

    # Summary by category
    cats = {}
    for c in all_cases:
        cats[c['category']] = cats.get(c['category'], 0) + 1
    print(f"\n  Total: {len(all_cases)} test cases", file=sys.stderr)
    for cat, count in sorted(cats.items(), key=lambda x: -x[1]):
        print(f"    {cat}: {count}", file=sys.stderr)

    os.makedirs(PATTERNS_DIR, exist_ok=True)
    out_path = os.path.join(PATTERNS_DIR, 'patterns.json')
    with open(out_path, 'w') as f:
        json.dump(all_cases, f, indent=2)
    print(f"\n  Written to: {out_path}", file=sys.stderr)


if __name__ == '__main__':
    main()
