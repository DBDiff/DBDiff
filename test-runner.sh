#!/bin/bash

# Test runner script for different PHP versions
# Usage: ./test-runner.sh [php_version] [mysql_version]
# Example: ./test-runner.sh 8.3 mysql80
#          ./test-runner.sh all all (runs all combinations)
#          ./test-runner.sh (interactive mode)

PHP_VERSIONS=("7.4" "8.3" "8.4")
MYSQL_VERSIONS=("mysql80" "mysql84" "mysql93")

# Function to show usage
show_usage() {
    echo "Usage: $0 [php_version] [mysql_version]"
    echo ""
    echo "Available PHP versions: ${PHP_VERSIONS[@]}"
    echo "Available MySQL versions: ${MYSQL_VERSIONS[@]}"
    echo ""
    echo "Examples:"
    echo "  $0 8.3 mysql80          # Test specific combination"
    echo "  $0 all all              # Test all combinations"
    echo "  $0                      # Interactive mode"
    echo ""
}

# Function to cleanup docker resources
cleanup_docker() {
    echo "=== Cleaning up Docker resources ==="
    echo "Stopping all containers..."
    docker-compose down --remove-orphans 2>/dev/null || true
    
    echo "Removing unused containers..."
    docker container prune -f 2>/dev/null || true
    
    echo "Removing unused images..."
    docker image prune -a -f 2>/dev/null || true
    
    echo "Removing unused volumes..."
    docker volume prune -f 2>/dev/null || true
    
    echo "Removing build cache..."
    docker builder prune -a -f 2>/dev/null || true
    
    echo "Docker cleanup completed."
    echo ""
}

# Function to test a specific PHP/MySQL combination
test_combination() {
    local php_version=$1
    local mysql_version=$2
    local service_name="cli-php${php_version//.}-${mysql_version}"
    
    echo "=== Testing $service_name ==="
    
    # Test PHP version info first
    echo "PHP Version Info:"
    docker-compose run --rm --entrypoint="" $service_name php --version
    
    echo ""
    echo "Composer packages:"
    docker-compose run --rm --entrypoint="" $service_name composer show --installed | head -10
    
    echo ""
    echo "PHPUnit version:"
    docker-compose run --rm --entrypoint="" $service_name ./vendor/bin/phpunit --version
    
    echo ""
    echo "=== Test completed for $service_name ==="
    
    # Cleanup after each test to save disk space
    cleanup_docker
    
    echo "--------------------------------------"
    echo ""
}

# Parse command line arguments
if [ $# -eq 0 ]; then
    # Interactive mode
    echo "=== DBDiff Interactive Test Runner ==="
    echo ""
    show_usage
    
    echo "Select PHP version:"
    select php_choice in "${PHP_VERSIONS[@]}" "all"; do
        if [ -n "$php_choice" ]; then
            break
        fi
    done
    
    echo "Select MySQL version:"
    select mysql_choice in "${MYSQL_VERSIONS[@]}" "all"; do
        if [ -n "$mysql_choice" ]; then
            break
        fi
    done
    
    PHP_ARG=$php_choice
    MYSQL_ARG=$mysql_choice
elif [ $# -eq 2 ]; then
    PHP_ARG=$1
    MYSQL_ARG=$2
else
    show_usage
    exit 1
fi

echo "=== DBDiff Test Runner ==="
echo "PHP version: $PHP_ARG"
echo "MySQL version: $MYSQL_ARG"
echo ""

# Determine which combinations to test
if [ "$PHP_ARG" = "all" ] && [ "$MYSQL_ARG" = "all" ]; then
    # Test all combinations
    for php_version in "${PHP_VERSIONS[@]}"; do
        for mysql_version in "${MYSQL_VERSIONS[@]}"; do
            test_combination $php_version $mysql_version
        done
    done
elif [ "$PHP_ARG" = "all" ]; then
    # Test all PHP versions with specific MySQL
    for php_version in "${PHP_VERSIONS[@]}"; do
        test_combination $php_version $MYSQL_ARG
    done
elif [ "$MYSQL_ARG" = "all" ]; then
    # Test specific PHP with all MySQL versions
    for mysql_version in "${MYSQL_VERSIONS[@]}"; do
        test_combination $PHP_ARG $mysql_version
    done
else
    # Test specific combination
    test_combination $PHP_ARG $MYSQL_ARG
fi

echo "=== All tests completed ==="

# Final cleanup
cleanup_docker
