#!/bin/bash

# Test runner script for DBDiff comprehensive tests
# Usage: 
#   ./run-tests.sh                    # Run tests normally (MySQL only)
#   ./run-tests.sh --record           # Run in record mode to capture expected outputs
#   ./run-tests.sh --specific <test>  # Run specific test method
#   ./run-tests.sh --postgres         # Run PostgreSQL end-to-end tests
#   ./run-tests.sh --postgres <host>  # Run PostgreSQL tests against a specific host
#   ./run-tests.sh --sqlite           # Run SQLite end-to-end tests

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

# Default values
RECORD_MODE="false"
SPECIFIC_TEST=""
TESTSUITE=""
MYSQL_VERSION=""
POSTGRES_HOST=""
SQLITE_ONLY="false"
DOLT_MODE="false"

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --record)
            RECORD_MODE="true"
            shift
            ;;
        --specific)
            SPECIFIC_TEST="$2"
            shift 2
            ;;
        --mysql)
            MYSQL_VERSION="$2"
            shift 2
            ;;
        --postgres)
            # Optional host argument: --postgres db-postgres16
            # If next arg looks like a hostname (no leading --), use it; otherwise use default.
            if [[ -n "${2:-}" && "${2:-}" != --* ]]; then
                POSTGRES_HOST="$2"
                shift 2
            else
                POSTGRES_HOST="${DB_HOST_POSTGRES:-db-postgres16}"
                shift
            fi
            ;;
        --unit)
            TESTSUITE="Unit"
            shift
            ;;
        --testsuite)
            TESTSUITE="$2"
            shift 2
            ;;
        --sqlite)
            SQLITE_ONLY="true"
            shift
            ;;
        --dolt)
            DOLT_MODE="true"
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--record] [--specific <test_method>] [--mysql <version>]"
            echo "          [--postgres [host]] [--sqlite] [--dolt]"
            echo ""
            echo "Options:"
            echo "  --record                Run in record mode to capture expected outputs"
            echo "  --specific <test>       Run specific test method"
            echo "  --unit                  Run only unit tests (no database required)"
            echo "  --mysql <version>       Test with specific MySQL version (8.0, 8.4, 9.3)"
            echo "  --postgres [host]       Enable PostgreSQL tests (end-to-end + comprehensive)"
            echo "                          Available hosts: db-postgres14, db-postgres15,"
            echo "                          db-postgres16 (default), db-postgres17, db-postgres18"
            echo "  --sqlite                Run SQLite tests (end-to-end + comprehensive)"
            echo "  --dolt                  Run tests against Dolt (MySQL-compatible)"
            echo ""
            echo "Examples:"
            echo "  $0                                       # Run all tests (MySQL)"
            echo "  $0 --unit                               # Run unit tests only (no DB needed)"
            echo "  $0 --record                             # Record expected outputs"
            echo "  $0 --specific testSchemaOnlyDiff        # Run specific test"
            echo "  $0 --mysql 8.4                          # Test with MySQL 8.4"
            echo "  $0 --postgres                           # Run Postgres e2e tests"
            echo "  $0 --postgres localhost                  # Postgres on localhost"
            echo "  $0 --sqlite                             # Run SQLite e2e tests"
            echo "  $0 --dolt                               # Run tests against Dolt"
            exit 1
            ;;
    esac
done

echo "🧪 DBDiff Comprehensive Test Runner"
echo "=================================="

if [ "$RECORD_MODE" = "true" ]; then
    echo "🎬 RECORD MODE: Will capture actual outputs as expected results"
    export DBDIFF_RECORD_MODE=true
else
    echo "✅ TEST MODE: Will compare against expected outputs"
fi

if [ -n "$MYSQL_VERSION" ]; then
    echo "🗄️  MySQL Version: $MYSQL_VERSION"
fi

if [ -n "$POSTGRES_HOST" ]; then
    echo "🐘 PostgreSQL host: $POSTGRES_HOST"
    export DB_HOST_POSTGRES="$POSTGRES_HOST"
    # Run all Postgres tests (end-to-end + comprehensive) via named testsuite.
    TESTSUITE="Postgres"
fi

if [ "$SQLITE_ONLY" = "true" ]; then
    echo "🗂️  Running SQLite tests (end-to-end + comprehensive)"
    TESTSUITE="SQLite"
fi

if [ "$DOLT_MODE" = "true" ]; then
    echo "🔀 Running tests against Dolt (MySQL-compatible)"
    export DBDIFF_ENGINE=dolt
    TESTSUITE="DBDiff"
fi

if [ "$TESTSUITE" = "Unit" ]; then
    echo "🔬 Running unit tests (no database required)"
fi

if [ -n "$SPECIFIC_TEST" ]; then
    echo "🎯 Running specific test: $SPECIFIC_TEST"
    TEST_FILTER="--filter $SPECIFIC_TEST"
elif [ -n "$TESTSUITE" ]; then
    echo "🎯 Running test suite: $TESTSUITE"
    TEST_FILTER="--testsuite $TESTSUITE"
else
    echo "🏃 Running all tests"
    TEST_FILTER=""
fi

echo ""

# Ensure we have the required directories
mkdir -p tests/expected
mkdir -p tests/config

# Run the tests
echo "Starting tests..."
echo ""

# Determine PHPUnit version and appropriate config/flags
PHPUNIT_VERSION=$(php vendor/bin/phpunit --version | head -n 1 | head -n 1 | grep -oE '[0-9]+\.[0-9]+' | head -n 1)
PHPUNIT_MAJOR=$(echo $PHPUNIT_VERSION | cut -d. -f1)

CONFIG_FLAG="-c tests/phpunit.xml"
FLAGS="--colors=always --testdox"

if [ "$PHPUNIT_MAJOR" -lt 10 ]; then
    CONFIG_FLAG="-c tests/phpunit.v9.xml"
else
    FLAGS="$FLAGS --display-deprecations --display-phpunit-deprecations --display-notices --display-warnings"
fi

php vendor/bin/phpunit $CONFIG_FLAG $FLAGS $TEST_FILTER

echo ""
echo "✅ Tests completed!"

if [ "$RECORD_MODE" = "true" ]; then
    echo ""
    echo "📝 Expected output files have been recorded in:"
    echo "   - tests/expected/ (for comprehensive tests)"
    echo "   - tests/end2end/ (for end-to-end tests)"
    echo "💡 You can now run tests normally to validate against these expectations"
fi
