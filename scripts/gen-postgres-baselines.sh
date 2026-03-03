#!/bin/bash
# Generate PostgreSQL baselines for versions 14, 15, 16, 17, 18
# Run from the DBDiff project root on the host (requires podman):
#   cd /path/to/DBDiff && bash scripts/gen-postgres-baselines.sh

set -e
DBDIFF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$DBDIFF_DIR"

NETWORK="dbdiff-pg-baseline-net"
PG_USER="dbdiff"
PG_PASS="rootpass"
PG_DB="diff1"

# Create network (ignore if exists)
podman network create "$NETWORK" 2>/dev/null || true

for PG_VERSION in 14 15 16 17 18; do
    CONTAINER_NAME="pg${PG_VERSION}-baseline"
    echo ""
    echo "════════════════════════════════════════════════"
    echo "  PostgreSQL ${PG_VERSION}"
    echo "════════════════════════════════════════════════"

    # Remove any existing container with this name
    podman rm -f "$CONTAINER_NAME" 2>/dev/null || true

    # Start PG container
    echo "Starting postgres:${PG_VERSION}..."
    podman run -d \
        --name "$CONTAINER_NAME" \
        --network "$NETWORK" \
        -e POSTGRES_PASSWORD="$PG_PASS" \
        -e POSTGRES_USER="$PG_USER" \
        -e POSTGRES_DB="$PG_DB" \
        "docker.io/library/postgres:${PG_VERSION}"

    # Wait for PostgreSQL to be ready (up to 60 seconds)
    echo "Waiting for PostgreSQL ${PG_VERSION} to be ready..."
    for i in $(seq 1 60); do
        if podman exec "$CONTAINER_NAME" pg_isready -U "$PG_USER" -q 2>/dev/null; then
            echo "PostgreSQL ${PG_VERSION} ready after ${i}s"
            break
        fi
        sleep 1
        if [ "$i" -eq 60 ]; then
            echo "ERROR: PostgreSQL ${PG_VERSION} did not become ready in 60 seconds"
            podman stop "$CONTAINER_NAME" || true
            podman rm "$CONTAINER_NAME" || true
            continue 2
        fi
    done

    # Run tests in record mode from the same network
    echo "Running baselines for PostgreSQL ${PG_VERSION}..."
    podman run --rm \
        --userns=keep-id \
        --network "$NETWORK" \
        -v "${DBDIFF_DIR}:/usr/src/dbdiff:z" \
        -e DBDIFF_RECORD_MODE=true \
        -e DB_HOST_POSTGRES="$CONTAINER_NAME" \
        localhost/dbdiff-php83 \
        bash -c "cd /usr/src/dbdiff && ./scripts/run-tests.sh --postgres \"$CONTAINER_NAME\" --record 2>&1"

    # Stop and clean up PG container
    echo "Cleaning up PostgreSQL ${PG_VERSION} container..."
    podman stop "$CONTAINER_NAME" || true
    podman rm "$CONTAINER_NAME" || true
    echo "  Done."
done

# Remove network
podman network rm "$NETWORK" 2>/dev/null || true

echo ""
echo "══════════════════════════════════════════════════"
echo "  All PostgreSQL baselines generated successfully!"
echo "══════════════════════════════════════════════════"
echo ""
echo "Generated baseline files:"
ls -la "${DBDIFF_DIR}/tests/expected/" | grep pgsql
