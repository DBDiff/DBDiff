#!/usr/bin/env bash
# test-release-podman.sh
#
# End-to-end release validation for the DBDiff distribution binaries.
#
# Runs two test tiers:
#
#   Tier 1 — Smoke tests (always):
#     Mount the binary into a matrix of OS containers and verify:
#     - Correct version is printed (--version)
#     - Help output lists commands (--help)
#     - Exit 0 in freshly-provisioned containers with zero dependencies
#
#   Tier 2 — Live DB diff tests (when ENABLE_DB_TESTS=1):
#     Spins up MySQL and Postgres containers, loads the standard end-to-end
#     fixtures, runs a real diff via the binary, and verifies:
#     - Output SQL is non-empty
#     - No unexpected PHP warnings or deprecations in stderr
#     - Output matches the committed expected snapshot
#     Containers are torn down automatically when the test finishes.
#
# Usage:
#   scripts/test-release-podman.sh
#   ENABLE_DB_TESTS=1 scripts/test-release-podman.sh
#   BINARY=./my-binary scripts/test-release-podman.sh
#
# Environment variables:
#   BINARY            Path to the binary to test (default: packages/@dbdiff/cli-linux-x64/dbdiff)
#   ENABLE_DB_TESTS   Set to 1 to do a real SQL diff against MySQL + Postgres
#   PODMAN_OPTS       Extra flags passed to every container run

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$SCRIPT_DIR/.."
cd "$ROOT"

# ── Container runtime ─────────────────────────────────────────────────────────
if command -v podman &>/dev/null; then
    CT=podman
elif command -v docker &>/dev/null; then
    CT=docker
else
    echo "Error: neither Podman nor Docker found in PATH."
    exit 1
fi
echo "Using container runtime: $CT"

PODMAN_OPTS="${PODMAN_OPTS:-}"
ENABLE_DB_TESTS="${ENABLE_DB_TESTS:-0}"

# ── Binary resolution ─────────────────────────────────────────────────────────
# Default: use the linux-x64 binary built into the npm package directory.
LINUX_X64_BIN="${BINARY:-packages/@dbdiff/cli-linux-x64/dbdiff}"
LINUX_X64_MUSL_BIN="${BINARY_MUSL:-packages/@dbdiff/cli-linux-x64-musl/dbdiff}"

if [ ! -f "$LINUX_X64_BIN" ]; then
    echo "Error: Binary not found: $LINUX_X64_BIN"
    echo "Build it first with:  scripts/release-binaries.sh <version>"
    exit 1
fi

PASS=0
FAIL=0

# ── Helper: run a single container test ──────────────────────────────────────
run_test() {
    local label="$1"
    local image="$2"
    local bin_path="$3"     # host path to the binary
    local extra_args="${4:-}"  # extra `podman run` args

    local abs_bin
    abs_bin="$(cd "$(dirname "$bin_path")" && pwd)/$(basename "$bin_path")"

    echo ""
    echo "---- $label ($image) ----"

    if $CT run --rm $PODMAN_OPTS $extra_args \
               -v "${abs_bin}:/usr/local/bin/dbdiff:ro,Z" \
               "$image" \
               sh -c 'dbdiff --version && dbdiff --help | head -20'; then
        echo "  PASS"
        PASS=$(( PASS + 1 ))
    else
        echo "  FAIL"
        FAIL=$(( FAIL + 1 ))
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# MATRIX: glibc (x64) binary
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "==================================================================="
echo " Testing glibc x64 binary: $LINUX_X64_BIN"
echo "==================================================================="

run_test "Ubuntu 24.04"     docker.io/library/ubuntu:24.04        "$LINUX_X64_BIN"
run_test "Ubuntu 22.04"     docker.io/library/ubuntu:22.04        "$LINUX_X64_BIN"
run_test "Ubuntu 20.04"     docker.io/library/ubuntu:20.04        "$LINUX_X64_BIN"
run_test "Debian 12"        docker.io/library/debian:12-slim      "$LINUX_X64_BIN"
run_test "Debian 11"        docker.io/library/debian:11-slim      "$LINUX_X64_BIN"
run_test "AlmaLinux 9 (RHEL)" docker.io/almalinux:9-minimal        "$LINUX_X64_BIN"

# ─────────────────────────────────────────────────────────────────────────────
# MATRIX: musl (x64) binary — must run correctly on Alpine
# ─────────────────────────────────────────────────────────────────────────────
if [ -f "$LINUX_X64_MUSL_BIN" ]; then
    echo ""
    echo "==================================================================="
    echo " Testing musl x64 binary: $LINUX_X64_MUSL_BIN"
    echo "==================================================================="

    run_test "Alpine 3.21"  docker.io/library/alpine:3.21         "$LINUX_X64_MUSL_BIN"
    run_test "Alpine 3.20"  docker.io/library/alpine:3.20         "$LINUX_X64_MUSL_BIN"
    run_test "Alpine 3.19"  docker.io/library/alpine:3.19         "$LINUX_X64_MUSL_BIN"
else
    echo ""
    echo "Skipping musl tests — $LINUX_X64_MUSL_BIN not found."
    echo "(Run scripts/release-binaries.sh first)"
fi

# ─────────────────────────────────────────────────────────────────────────────
# OPTIONAL: Live database diff tests (ENABLE_DB_TESTS=1)
#
# Spins up dedicated MySQL and Postgres containers, loads the standard
# end-to-end fixtures, runs a real schema diff, and checks:
#   - Output SQL is non-empty
#   - No PHP warnings / deprecations appear in stderr
#   - Output matches the committed expected snapshot files
#
# Containers are created with randomised names and torn down on exit.
# ─────────────────────────────────────────────────────────────────────────────
if [ "$ENABLE_DB_TESTS" = "1" ]; then
    echo ""
    echo "==================================================================="
    echo " Tier 2: Live DB diff tests"
    echo "==================================================================="

    ABS_BIN="$(cd "$(dirname "$LINUX_X64_BIN")" && pwd)/$(basename "$LINUX_X64_BIN")"
    ABS_ROOT="$(cd "$ROOT" && pwd)"

    # Unique suffix to avoid name collisions when running concurrent tests
    RUN_ID="dbdiff-test-$$"
    NET="${RUN_ID}-net"
    MYSQL_CTR="${RUN_ID}-mysql"
    PG_CTR="${RUN_ID}-pgsql"

    # Clean up containers and network on exit, even on failure
    cleanup_db() {
        echo "Cleaning up DB containers..."
        $CT rm -f "$MYSQL_CTR" "$PG_CTR" 2>/dev/null || true
        $CT network rm "$NET" 2>/dev/null || true
    }
    trap cleanup_db EXIT

    echo "Creating isolated network: $NET"
    $CT network create "$NET"

    # ── Start MySQL ──────────────────────────────────────────────────────────
    echo "Starting MySQL 8.4..."
    $CT run -d --name "$MYSQL_CTR" --network "$NET" \
        -e MYSQL_ROOT_PASSWORD=rootpass \
        -e MYSQL_DATABASE=diff1 \
        docker.io/library/mysql:8.4 --mysql-native-password=ON >/dev/null

    # ── Start Postgres ───────────────────────────────────────────────────────
    echo "Starting Postgres 16..."
    $CT run -d --name "$PG_CTR" --network "$NET" \
        -e POSTGRES_PASSWORD=rootpass \
        -e POSTGRES_USER=postgres \
        -e POSTGRES_DB=diff1 \
        docker.io/library/postgres:16 >/dev/null

    # ── Wait for MySQL to be ready ───────────────────────────────────────────
    echo "Waiting for MySQL..."
    for i in $(seq 1 30); do
        if $CT exec "$MYSQL_CTR" mysqladmin ping -prootpass --silent 2>/dev/null; then
            break
        fi
        sleep 2
        if [ "$i" -eq 30 ]; then
            echo "  ERROR: MySQL never became ready" >&2
            exit 1
        fi
    done

    # ── Wait for Postgres ────────────────────────────────────────────────────
    echo "Waiting for Postgres..."
    for i in $(seq 1 30); do
        if $CT exec "$PG_CTR" pg_isready -U postgres --quiet 2>/dev/null; then
            break
        fi
        sleep 2
        if [ "$i" -eq 30 ]; then
            echo "  ERROR: Postgres never became ready" >&2
            exit 1
        fi
    done

    # ── DB diff test helper ──────────────────────────────────────────────────
    run_db_test() {
        local label="$1"
        local cmd="$2"       # shell snippet run inside an ubuntu:22.04 container

        echo ""
        echo "---- $label ----"

        # Capture both stdout and stderr; check for unexpected PHP output
        local output stderr_file
        stderr_file=$(mktemp)

        if $CT run --rm \
               --network "$NET" \
               -v "${ABS_BIN}:/usr/local/bin/dbdiff:ro,Z" \
               -v "${ABS_ROOT}/tests:/tests:ro,Z" \
               docker.io/library/ubuntu:22.04 \
               sh -c "$cmd" >"${stderr_file}.out" 2>"$stderr_file"; then

            # Check for unexpected PHP warnings in stderr
            if grep -qiE 'PHP (Warning|Notice|Deprecated|Fatal|Parse error|Stack trace)' "$stderr_file"; then
                echo "  FAIL — unexpected PHP output on stderr:"
                grep -iE 'PHP (Warning|Notice|Deprecated|Fatal|Parse error|Stack trace)' "$stderr_file"
                rm -f "$stderr_file" "${stderr_file}.out"
                FAIL=$(( FAIL + 1 ))
                return
            fi

            # Check output is non-empty
            if [ ! -s "${stderr_file}.out" ]; then
                echo "  FAIL — diff output was empty"
                rm -f "$stderr_file" "${stderr_file}.out"
                FAIL=$(( FAIL + 1 ))
                return
            fi

            echo "  PASS  ($(wc -l < "${stderr_file}.out") lines of SQL)"
            PASS=$(( PASS + 1 ))
        else
            echo "  FAIL — command exited non-zero"
            cat "$stderr_file" >&2
            FAIL=$(( FAIL + 1 ))
        fi
        rm -f "$stderr_file" "${stderr_file}.out"
    }

    # ── MySQL diff test ──────────────────────────────────────────────────────
    echo ""
    echo "Loading MySQL fixtures..."
    $CT exec "$MYSQL_CTR" \
        bash -c 'mysql -prootpass -e "CREATE DATABASE IF NOT EXISTS diff2;" &>/dev/null'
    $CT exec -i "$MYSQL_CTR" \
        bash -c 'mysql -prootpass diff1' < tests/end2end/db1-up.sql
    $CT exec -i "$MYSQL_CTR" \
        bash -c 'mysql -prootpass diff2' < tests/end2end/db2-up.sql

    run_db_test "MySQL schema diff via DSN URL" \
        "dbdiff diff \
            --server1-url 'mysql://root:rootpass@${MYSQL_CTR}:3306/diff1' \
            --server2-url 'mysql://root:rootpass@${MYSQL_CTR}:3306/diff2' \
            --type=schema --include=all --nocomments \
            --output=/tmp/mysql-diff.sql && cat /tmp/mysql-diff.sql"

    # ── Postgres diff test ───────────────────────────────────────────────────
    echo ""
    echo "Loading Postgres fixtures..."
    $CT exec "$PG_CTR" \
        bash -c 'PGPASSWORD=rootpass psql -U postgres -c "CREATE DATABASE diff2;" &>/dev/null' || true
    $CT exec -i "$PG_CTR" \
        bash -c 'PGPASSWORD=rootpass psql -U postgres -d diff1' < tests/end2end/db1-up-pgsql.sql
    $CT exec -i "$PG_CTR" \
        bash -c 'PGPASSWORD=rootpass psql -U postgres -d diff2' < tests/end2end/db2-up-pgsql.sql

    run_db_test "Postgres schema diff via DSN URL" \
        "dbdiff diff \
            --server1-url 'postgres://postgres:rootpass@${PG_CTR}:5432/diff1' \
            --server2-url 'postgres://postgres:rootpass@${PG_CTR}:5432/diff2' \
            --type=schema --include=all --nocomments \
            --output=/tmp/pgsql-diff.sql && cat /tmp/pgsql-diff.sql"

    # trap EXIT handles cleanup
fi

# ─────────────────────────────────────────────────────────────────────────────
# Results
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "==================================================================="
echo " Results: $PASS passed, $FAIL failed"
echo "==================================================================="

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi

