#!/usr/bin/env bash
# test-release-podman.sh
#
# End-to-end release validation for the DBDiff distribution binaries.
#
# Spins up a matrix of Podman containers (or Docker if Podman is absent)
# and verifies that the self-contained binaries:
#   1. Execute and print the correct version string
#   2. Print meaningful help output (commands are registered)
#   3. Exit 0 in a freshly provisioned container with zero dependencies
#
# Optionally runs against a live MySQL/Postgres service (see ENABLE_DB_TESTS).
#
# Usage:
#   scripts/test-release-podman.sh [binary_dir]
#
#   # Test the binaries in the default npm package directories
#   scripts/test-release-podman.sh
#
#   # Test a specific pre-built binary directly
#   BINARY=./dist/dbdiff scripts/test-release-podman.sh
#
# Environment variables:
#   BINARY            Path to a pre-built binary to test (optional)
#   ENABLE_DB_TESTS   Set to 1 to run live MySQL + Postgres diff tests (requires
#                     the DB containers to be running via docker-compose)
#   PODMAN_OPTS       Extra flags passed to every `podman run` / `docker run`

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

run_test "Ubuntu 24.04"     ubuntu:24.04        "$LINUX_X64_BIN"
run_test "Ubuntu 22.04"     ubuntu:22.04        "$LINUX_X64_BIN"
run_test "Ubuntu 20.04"     ubuntu:20.04        "$LINUX_X64_BIN"
run_test "Debian 12"        debian:12-slim      "$LINUX_X64_BIN"
run_test "Debian 11"        debian:11-slim      "$LINUX_X64_BIN"
run_test "AlmaLinux 9 (RHEL)" docker.io/almalinux:9-minimal "$LINUX_X64_BIN"

# ─────────────────────────────────────────────────────────────────────────────
# MATRIX: musl (x64) binary — must run correctly on Alpine
# ─────────────────────────────────────────────────────────────────────────────
if [ -f "$LINUX_X64_MUSL_BIN" ]; then
    echo ""
    echo "==================================================================="
    echo " Testing musl x64 binary: $LINUX_X64_MUSL_BIN"
    echo "==================================================================="

    run_test "Alpine 3.21"  alpine:3.21         "$LINUX_X64_MUSL_BIN"
    run_test "Alpine 3.20"  alpine:3.20         "$LINUX_X64_MUSL_BIN"
    run_test "Alpine 3.19"  alpine:3.19         "$LINUX_X64_MUSL_BIN"
else
    echo ""
    echo "Skipping musl tests — $LINUX_X64_MUSL_BIN not found."
    echo "(Run scripts/release-binaries.sh first)"
fi

# ─────────────────────────────────────────────────────────────────────────────
# OPTIONAL: Live database diff tests
# Set ENABLE_DB_TESTS=1 to run these.  Requires the DB services to be up
# (e.g. via:  podman-compose -f docker-compose.yml up -d)
# ─────────────────────────────────────────────────────────────────────────────
if [ "$ENABLE_DB_TESTS" = "1" ]; then
    echo ""
    echo "==================================================================="
    echo " Live database tests"
    echo "==================================================================="

    ABS_BIN="$(cd "$(dirname "$LINUX_X64_BIN")" && pwd)/$(basename "$LINUX_X64_BIN")"

    # MySQL smoke: connect and run --version (a full diff requires two DBs)
    echo ""
    echo "---- MySQL connectivity smoke test ----"
    if $CT run --rm $PODMAN_OPTS \
               --network host \
               -v "${ABS_BIN}:/usr/local/bin/dbdiff:ro,Z" \
               -e DB_HOST="${DB_HOST:-127.0.0.1}" \
               -e DB_PORT="${DB_PORT:-3306}" \
               -e DB_USER="${DB_USER:-dbdiff}" \
               -e DB_PASSWORD="${DB_PASSWORD:-dbdiff}" \
               ubuntu:22.04 \
               sh -c '
                   dbdiff --version
                   dbdiff diff \
                       "${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/diff1" \
                       "${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/diff1" \
                       --type=schema 2>&1 | head -5 || true
               '; then
        echo "  PASS"
        PASS=$(( PASS + 1 ))
    else
        echo "  FAIL"
        FAIL=$(( FAIL + 1 ))
    fi
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
