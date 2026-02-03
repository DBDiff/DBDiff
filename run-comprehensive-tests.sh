#!/bin/bash

# Test runner script for DBDiff comprehensive tests
# Usage: 
#   ./run-tests.sh                    # Run tests normally
#   ./run-tests.sh --record           # Run in record mode to capture expected outputs
#   ./run-tests.sh --specific <test>  # Run specific test method

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Default values
RECORD_MODE="false"
SPECIFIC_TEST=""
MYSQL_VERSION=""

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
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--record] [--specific <test_method>] [--mysql <version>]"
            echo ""
            echo "Options:"
            echo "  --record                Run in record mode to capture expected outputs"
            echo "  --specific <test>       Run specific test method"
            echo "  --mysql <version>       Test with specific MySQL version (8.0, 8.4, 9.3)"
            echo ""
            echo "Examples:"
            echo "  $0                                    # Run all tests"
            echo "  $0 --record                          # Record expected outputs"
            echo "  $0 --specific testSchemaOnlyDiff     # Run specific test"
            echo "  $0 --mysql 8.4                       # Test with MySQL 8.4"
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
    # Here you could add logic to switch to specific MySQL version
    # For now, we'll use whatever version is running
fi

if [ -n "$SPECIFIC_TEST" ]; then
    echo "🎯 Running specific test: $SPECIFIC_TEST"
    TEST_FILTER="--filter $SPECIFIC_TEST"
else
    echo "🏃 Running all comprehensive tests"
    TEST_FILTER=""
fi

echo ""

# Ensure we have the required directories
mkdir -p tests/expected
mkdir -p tests/config

# Run the tests
echo "Starting tests..."
echo ""

if [ -n "$SPECIFIC_TEST" ]; then
    php vendor/bin/phpunit tests/DBDiffComprehensiveTest.php $TEST_FILTER --verbose
else
    php vendor/bin/phpunit tests/DBDiffComprehensiveTest.php --verbose
fi

echo ""
echo "✅ Tests completed!"

if [ "$RECORD_MODE" = "true" ]; then
    echo ""
    echo "📝 Expected output files have been recorded in tests/expected/"
    echo "💡 You can now run tests normally to validate against these expectations"
fi
