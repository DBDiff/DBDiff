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

# Parse connection args (also forwarded to the PHP runner) so extraction can
# validate every statement against the same live Postgres.
PG_HOST=localhost; PG_PORT=5432; PG_USER=dbdiff; PG_PASS=rootpass
PG_DB="${DB_NAME:-diff1}"
for arg in "$@"; do
    case "$arg" in
        --host=*) PG_HOST="${arg#*=}" ;;
        --port=*) PG_PORT="${arg#*=}" ;;
        --user=*) PG_USER="${arg#*=}" ;;
        --pass=*) PG_PASS="${arg#*=}" ;;
    esac
done
export PGCONF_DSN="host=$PG_HOST port=$PG_PORT user=$PG_USER password=$PG_PASS dbname=$PG_DB"

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
echo "Step 2: Extracting DDL patterns (live-validated against $PG_HOST:$PG_PORT)..."
# The extractor validates each statement on the live server via PDO (pdo_pgsql),
# so before_sql is always self-contained and there are no silent runtime skips.
php "$SCRIPT_DIR/extract-patterns.php"

echo ""
echo "Step 3: Running conformance tests..."
php "$SCRIPT_DIR/run-conformance.php" "$@"
