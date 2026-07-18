#!/usr/bin/env bash
# Run the Postgres conformance test suite against DBDiff.
#
# Usage:
#   ./scripts/pg-conformance/run.sh                        # default: localhost:5432
#   ./scripts/pg-conformance/run.sh --host=db-postgres16   # Docker compose host
#   ./scripts/pg-conformance/run.sh --category=add_column  # test one category
#   ./scripts/pg-conformance/run.sh --verbose              # show all results
#   ./scripts/pg-conformance/run.sh --limit=20             # first 20 patterns only

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_DIR"

echo "Step 1: Downloading Postgres regression files from pgrust..."
mkdir -p /tmp/pg-regress

for f in alter_table constraints create_index identity generated_stored domain enum; do
    target="/tmp/pg-regress/${f}.sql"
    if [ -f "$target" ]; then
        echo "  $f.sql (cached)"
        continue
    fi

    if [ "$f" = "alter_table" ]; then
        src="alter_table"
    else
        src="$f"
    fi

    gh api "repos/malisper/pgrust/contents/vendor/postgres-18.3/regress/sql/${src}.sql" \
        --jq '.content' 2>/dev/null | base64 -d > "$target" 2>/dev/null || {
        echo "  WARN: could not fetch $f.sql"
        continue
    }
    echo "  $f.sql ($(wc -l < "$target") lines)"
done

# Symlink for extract script's expected paths
ln -sf /tmp/pg-regress/alter_table.sql /tmp/alter_table.sql
for f in constraints create_index identity generated_stored domain enum; do
    ln -sf "/tmp/pg-regress/${f}.sql" "/tmp/pg_regress_${f}.sql"
done

echo ""
echo "Step 2: Extracting DDL patterns..."
python3 "$SCRIPT_DIR/extract-patterns.py"

echo ""
echo "Step 3: Running conformance tests..."
php "$SCRIPT_DIR/run-conformance.php" "$@"
