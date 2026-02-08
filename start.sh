#!/bin/bash

# Start script for running DBDiff tests with different PHP/MySQL version combinations
# Usage: ./start.sh [php_version] [mysql_version]
# Example: ./start.sh 8.3 8.0
#          ./start.sh all all (runs all combinations)
#          ./start.sh (interactive mode)

# Load environment configuration
if [ -f .env ]; then
    # Parse .env file and export variables
    while IFS='=' read -r key value; do
        # Skip comments and empty lines
        [[ $key =~ ^[[:space:]]*# ]] && continue
        [[ -z $key ]] && continue
        
        # Remove quotes and export
        value=$(echo "$value" | sed 's/^["'\'']//' | sed 's/["'\'']$//')
        export "$key"="$value"
    done < .env
fi

# Set defaults if not loaded from .env
export COMPOSE_BAKE=${COMPOSE_BAKE:-true}
export COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-dbdiff}

# Set up signal handling for cleanup
trap cleanup_on_exit EXIT
trap cleanup_on_interrupt INT TERM

cleanup_on_exit() {
    local exit_code=$?
    stop_watchdog
    
    if [ "$NO_TEARDOWN" = "true" ]; then
        echo ""
        echo "🔄 No teardown mode - containers and resources left running"
        echo "💡 Use 'docker-compose down' or run stop.sh to cleanup manually"
        return 0
    fi
    
    if [ $exit_code -ne 0 ]; then
        echo ""
        echo "🧹 Script exited with error code $exit_code. Cleaning up..."
    else
        echo ""
        echo "🧹 Script completed. Cleaning up..."
    fi
    cleanup_docker
}

cleanup_on_interrupt() {
    echo ""
    echo "🛑 Interrupted!"
    
    stop_watchdog
    
    if [ "$NO_TEARDOWN" = "true" ]; then
        echo "🔄 No teardown mode - keeping containers and resources running"
        echo "💡 Use 'docker-compose down' or run stop.sh to cleanup manually"
    else
        echo "🧹 Force cleaning up..."
        cleanup_docker
        echo "Cleanup completed."
    fi
    echo "Exiting..."
    exit 130
}

# Configuration variables - derive arrays and mappings from individual variables
# Build PHP versions array from individual variables
PHP_VERSIONS=()
for var in PHP_VERSION_74 PHP_VERSION_83 PHP_VERSION_84; do
    if [ -n "${!var}" ]; then
        PHP_VERSIONS+=("${!var}")
    fi
done

# Build MySQL version mapping from individual variables
MYSQL_VERSION_MAPPING=()
if [ -n "$MYSQL_VERSION_80" ]; then
    MYSQL_VERSION_MAPPING+=("$MYSQL_VERSION_80:mysql80")
fi
if [ -n "$MYSQL_VERSION_84" ]; then
    MYSQL_VERSION_MAPPING+=("$MYSQL_VERSION_84:mysql84")
fi
if [ -n "$MYSQL_VERSION_93" ]; then
    MYSQL_VERSION_MAPPING+=("$MYSQL_VERSION_93:mysql93")
fi

# Static MySQL service names
MYSQL_VERSIONS=("mysql80" "mysql84" "mysql93")

# Fallback to defaults if individual variables not found
if [ ${#PHP_VERSIONS[@]} -eq 0 ]; then
    PHP_VERSIONS=("7.4" "8.3" "8.4")
fi
if [ ${#MYSQL_VERSION_MAPPING[@]} -eq 0 ]; then
    MYSQL_VERSION_MAPPING=("8.0:mysql80" "8.4:mysql84" "9.3:mysql93")
fi

# Port configuration - derive arrays from individual variables
DB_PORTS=(${DB_PORT_MYSQL80:-3306} ${DB_PORT_MYSQL84:-3307} ${DB_PORT_MYSQL93:-3308})
PHPMYADMIN_PORTS=(${PHPMYADMIN_PORT_MYSQL80:-18080} ${PHPMYADMIN_PORT_MYSQL84:-18081} ${PHPMYADMIN_PORT_MYSQL93:-18082})

# Database connection details
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD:-"rootpass"}
DB_NAME=${DB_NAME:-"diff1"}
DB_USER=${DB_USER:-"dbdiff"}
DB_PASSWORD=${DB_PASSWORD:-"dbdiff"}

# Timeout configuration (in seconds)
DATABASE_STARTUP_TIMEOUT=${DATABASE_STARTUP_TIMEOUT:-180}
DATABASE_HEALTH_TIMEOUT=${DATABASE_HEALTH_TIMEOUT:-120}
CLI_BUILD_TIMEOUT=${CLI_BUILD_TIMEOUT:-300}
PHP_VERSION_CHECK_TIMEOUT=${PHP_VERSION_CHECK_TIMEOUT:-30}
PHPUNIT_TEST_TIMEOUT=${PHPUNIT_TEST_TIMEOUT:-300}

# Function to show usage
show_usage() {
    local mysql_display=($(get_mysql_version_display))
    
    echo "Usage: $0 [php_version] [mysql_version] [--watch] [--no-teardown]"
    echo ""
    echo "Available PHP versions: ${PHP_VERSIONS[@]}"
    echo "Available MySQL versions: ${mysql_display[@]}"
    echo ""
    echo "Examples:"
    echo "  $0 8.3 8.0                    # Test specific combination"
    echo "  $0 all all                    # Test all combinations"
    echo "  $0 8.3 8.0 --watch            # Watch mode - run tests once then keep active"
    echo "  $0 8.3 8.0 --no-teardown      # Single run without cleanup"
    echo "  $0 8.3 8.0 --watch --no-teardown  # Watch mode with no cleanup on exit"
    echo "  $0 8.3 8.0 --fast            # Fast restart mode - less aggressive cleanup"
    echo "  $0                            # Interactive mode"
    echo ""
    echo "Modes:"
    echo "  Single run:    Tests run once, then cleanup (unless --no-teardown)"
    echo "  Watch mode:    Tests run once, services stay active until Ctrl+C"
    echo "  No teardown:   Services remain active after script completion"
    echo ""
    echo "All option behavior:"
    echo "  Single mode:   Each combination runs and cleans up sequentially"
    echo "  Watch mode:    Each combination runs in sequence, user must Ctrl+C between each"
    echo "  Fast mode:     Skips heavy cleanup (images/cache) for much faster restarts"
    echo ""
}

# Function to check for port conflicts
check_port_conflicts() {
    local ports=(
        "${DB_PORT_MYSQL80:-3306}" 
        "${DB_PORT_MYSQL84:-3307}" 
        "${DB_PORT_MYSQL93:-3308}" 
        "${PHPMYADMIN_PORT_MYSQL80:-18080}" 
        "${PHPMYADMIN_PORT_MYSQL84:-18081}" 
        "${PHPMYADMIN_PORT_MYSQL93:-18082}"
    )
    local conflicts=()
    
    echo "=== Checking for port conflicts ==="
    for port in "${ports[@]}"; do
        if lsof -Pi :$port -sTCP:LISTEN -t >/dev/null 2>&1; then
            conflicts+=($port)
            echo "⚠️  Port $port is already in use"
        fi
    done
    
    if [ ${#conflicts[@]} -gt 0 ]; then
        echo ""
        echo "❌ Port conflicts detected on: ${conflicts[*]}"
        echo "Please stop services using these ports or use different ports."
        echo "You can check what's using a port with: lsof -i :PORT_NUMBER"
        echo ""
        return 1
    else
        echo "✅ No port conflicts detected"
        echo ""
        return 0
    fi
}

# Function to check if a container is responsive
check_container_responsive() {
    local service_name=$1
    local container_name="dbdiff-${service_name}-1"
    
    echo "🔍 Checking if container $container_name is responsive..."
    
    # Check if container exists and is running
    if ! docker ps --format "table {{.Names}}" | grep -q "^${container_name}$"; then
        echo "❌ Container $container_name is not running"
        return 1
    fi
    
    # Try a simple command to test responsiveness
    if timeout 10 docker exec $container_name echo "Container is responsive" >/dev/null 2>&1; then
        echo "✅ Container $container_name is responsive"
        return 0
    else
        echo "❌ Container $container_name is not responsive"
        return 1
    fi
}

# Function to check if Docker is available
is_docker_available() {
    # Check both Docker daemon and socket availability
    docker info >/dev/null 2>&1 && [ -S /var/run/docker.sock ] 2>/dev/null
}

# Function to cleanup docker resources
cleanup_docker() {
    echo "=== Cleaning up Docker resources ==="
    
    # Check if Docker is available before attempting cleanup
    if ! is_docker_available; then
        echo "⚠️  Docker daemon not available - skipping cleanup"
        return 0
    fi
    
    # Stop and remove containers
    echo "Stopping containers..."
    if docker-compose down --remove-orphans --volumes 2>/dev/null; then
        echo "✅ Containers stopped"
    else
        echo "⚠️  Failed to stop containers"
    fi
    
    # Remove project containers
    local containers=$(docker container ls -a --filter "name=dbdiff" --format "{{.ID}}" 2>/dev/null)
    if [ -n "$containers" ]; then
        echo "Removing project containers..."
        echo "$containers" | xargs -r docker rm -f 2>/dev/null && echo "✅ Project containers removed"
    fi
    
    if [ "$FAST_MODE" = "true" ]; then
        echo "🏃 Fast mode: Skipping image, prune, and cache cleanup"
        echo "✅ Cleanup completed"
        echo ""
        return 0
    fi
    
    # Remove project images (including PHPMyAdmin)
    local images=$(docker images --filter "reference=dbdiff*" --format "{{.ID}}" 2>/dev/null)
    local phpmyadmin_images=$(docker images --filter "reference=phpmyadmin/phpmyadmin*" --format "{{.ID}}" 2>/dev/null)
    if [ -n "$images" ] || [ -n "$phpmyadmin_images" ]; then
        echo "Removing project images..."
        if [ -n "$images" ]; then
            echo "$images" | xargs -r docker rmi -f 2>/dev/null
        fi
        if [ -n "$phpmyadmin_images" ]; then
            echo "$phpmyadmin_images" | xargs -r docker rmi -f 2>/dev/null
        fi
        echo "✅ Project images removed"
    fi
    
    # Clean unused resources
    echo "Cleaning unused resources..."
    docker system prune -a -f --volumes >/dev/null 2>&1 && echo "✅ Unused resources cleaned"
    
    # Clean build cache
    echo "Cleaning build cache..."
    docker builder prune -a -f >/dev/null 2>&1 && echo "✅ Build cache cleaned"
    
    echo "✅ Cleanup completed"
    echo ""
}

# Function to show Docker disk usage
show_docker_disk_usage() {
    if is_docker_available; then
        echo "📊 Docker disk usage:"
        docker system df 2>/dev/null || echo "Could not get Docker disk usage"
    else
        echo "📊 Docker unavailable - cannot show disk usage"
    fi
    echo ""
}

# Function to run docker-compose with timeout and progress monitoring
run_docker_compose_with_timeout() {
    local timeout=$1
    local description=$2
    shift 2
    local cmd=("$@")
    
    # Check Docker availability before running command
    if ! is_docker_available; then
        echo "❌ Docker daemon not available"
        return 1
    fi
    
    # Create a temporary file to track the process
    local temp_file=$(mktemp)
    local start_time=$(date +%s)
    
    # Start the command in background and capture its PID
    (
        trap 'echo "Subprocess interrupted"; exit 130' INT TERM
        docker-compose "${cmd[@]}" 2>/dev/null
        echo $? > "$temp_file"
    ) &
    local pid=$!
    
    # Monitor progress
    local elapsed=0
    local check_interval=5
    
    while kill -0 $pid 2>/dev/null; do
        sleep $check_interval
        elapsed=$((elapsed + check_interval))
        
        if [ $elapsed -ge $timeout ]; then
            echo "❌ Command timed out after ${timeout}s"
            kill -TERM $pid 2>/dev/null || true
            sleep 2
            kill -9 $pid 2>/dev/null || true
            rm -f "$temp_file"
            return 124
        fi
    done
    
    # Get the exit code
    wait $pid 2>/dev/null
    local exit_code=$?
    
    if [ -f "$temp_file" ]; then
        local file_exit_code=$(cat "$temp_file" 2>/dev/null)
        if [ -n "$file_exit_code" ]; then
            exit_code=$file_exit_code
        fi
        rm -f "$temp_file"
    fi
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    if [ $exit_code -eq 0 ]; then
        echo "✅ $description completed (${duration}s)"
    else
        echo "❌ $description failed (exit code: $exit_code, ${duration}s)"
    fi
    
    return $exit_code
}

# Function to show periodic status updates
show_progress() {
    local message="$1"
    local pid="$2"
    local interval="${3:-10}"
    local elapsed=0
    
    while kill -0 "$pid" 2>/dev/null; do
        sleep $interval
        elapsed=$((elapsed + interval))
        echo "⏳ $message... (${elapsed}s elapsed)"
        
        # Show docker status every 30 seconds
        if [ $((elapsed % 30)) -eq 0 ]; then
            echo "📊 Current Docker status:"
            docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | head -5
        fi
    done
}

# Function to get appropriate docker-compose run flags
get_docker_run_flags() {
    local flags="--rm"
    
    # Always use non-TTY mode for better script compatibility
    # Check which flag is supported by testing docker-compose version
    if docker-compose run --help | grep -q "\-\-no-TTY"; then
        flags="$flags --no-TTY"
    elif docker-compose run --help | grep -q "\-T"; then
        flags="$flags -T"
    fi
    
    echo "$flags"
}

# Function to test a specific PHP/MySQL combination
test_combination() {
    local php_version=$1
    local mysql_version=$2
    local service_name="cli-php${php_version//.}-${mysql_version}"
    local db_service="db-${mysql_version}"
    
    echo "=== Testing $service_name ==="
    echo "📊 PHP: $php_version | MySQL: $(convert_service_to_mysql_version "$mysql_version")"
    echo "🐳 Service: $service_name | DB: $db_service"
    echo ""
    
    # Start database service first with timeout monitoring
    echo "🗄️  Starting database service: $db_service"
    if ! run_docker_compose_with_timeout $DATABASE_STARTUP_TIMEOUT "Database startup" up -d --build $db_service; then
        echo "❌ Failed to start database service $db_service"
        return 1
    fi
    
    echo ""
    echo "⏳ Waiting for database to become healthy..."
    local timeout=$DATABASE_HEALTH_TIMEOUT
    local elapsed=0
    local interval=5
    
    while [ $elapsed -lt $timeout ]; do
        if docker-compose ps --format json $db_service | grep -q '"Health":"healthy"'; then
            echo "✅ Database $db_service is healthy!"
            break
        elif docker-compose ps --format json $db_service | grep -q '"Health":"unhealthy"'; then
            echo "❌ Database $db_service is unhealthy. Checking logs..."
            docker-compose logs --tail=20 $db_service
            return 1
        fi
        
        sleep $interval
        elapsed=$((elapsed + interval))
    done
     if [ $elapsed -ge $timeout ]; then
        echo "❌ Database $db_service failed to become healthy within ${timeout}s"
        docker-compose logs --tail=30 $db_service
        return 1
    fi

    # Start PHPMyAdmin service for this database
    local phpmyadmin_service="phpmyadmin-${mysql_version}"
    echo ""
    echo "🌐 Starting PHPMyAdmin service: $phpmyadmin_service"
    if ! run_docker_compose_with_timeout 120 "PHPMyAdmin service startup" up -d $phpmyadmin_service; then
        echo "⚠️  Failed to start PHPMyAdmin service $phpmyadmin_service (non-critical, continuing with tests)"
    else
        echo "✅ PHPMyAdmin started successfully"
    fi

    # Build CLI service if needed
    echo ""
    echo "🔨 Building CLI service: $service_name"
    if ! run_docker_compose_with_timeout $CLI_BUILD_TIMEOUT "CLI service build" build $service_name; then
        echo "❌ Failed to build CLI service $service_name"
        return 1
    fi
    
    # Test PHP version info
    echo ""
    echo "📋 Getting PHP version..."
    local run_flags=$(get_docker_run_flags)
    
    if run_docker_compose_with_timeout $PHP_VERSION_CHECK_TIMEOUT "PHP version check" run $run_flags --entrypoint="" $service_name php --version; then
        echo "✅ PHP version check successful"
    else
        echo "❌ Failed to get PHP version for $service_name"
        return 1
    fi
    
    echo ""
    echo "🧪 Running PHPUnit Tests..."
    
    # Run PHPUnit with timeout and progress monitoring
    local test_start_time=$(date +%s)
    if run_docker_compose_with_timeout $PHPUNIT_TEST_TIMEOUT "PHPUnit test execution" run $run_flags --entrypoint="" $service_name ./vendor/bin/phpunit --colors=always --testdox --display-deprecations --display-phpunit-deprecations --display-notices --display-warnings; then
        local test_end_time=$(date +%s)
        local test_duration=$((test_end_time - test_start_time))
        echo "✅ All tests PASSED for $service_name (${test_duration}s)"
    else
        local exit_code=$?
        local test_end_time=$(date +%s)
        local test_duration=$((test_end_time - test_start_time))
        
        if [ $exit_code -eq 124 ]; then
            echo "❌ PHPUnit tests TIMED OUT for $service_name after ${test_duration}s (${PHPUNIT_TEST_TIMEOUT} second limit)"
        else
            echo "❌ Some tests FAILED for $service_name after ${test_duration}s (exit code: $exit_code)"
        fi
        
        # Show container logs for debugging
        echo "--- Database Logs ---"
        docker-compose logs --tail=20 $db_service
        echo "--- End Logs ---"
        
        return 1
    fi
    
    echo ""
    echo "✅ Test completed successfully for $service_name"
    
    # Only cleanup if not in watch mode and not in no-teardown mode
    if [ "$WATCH_MODE" != "true" ] && [ "$NO_TEARDOWN" != "true" ]; then
        echo "🧹 Cleaning up after test..."
        
        if is_docker_available; then
            # Stop all containers
            docker-compose down --remove-orphans --volumes >/dev/null 2>&1
            
            # Remove project containers
            docker container ls -a --filter "name=dbdiff" --format "{{.ID}}" | xargs -r docker rm -f >/dev/null 2>&1
            
            # Remove project images  
            docker images --filter "reference=dbdiff*" --format "{{.ID}}" | xargs -r docker rmi -f >/dev/null 2>&1
            
            # Clean unused resources
            docker system prune -a -f --volumes >/dev/null 2>&1
            
            # Clean build cache
            docker builder prune -a -f >/dev/null 2>&1
            
            echo "✅ Cleanup completed"
        else
            echo "⚠️  Docker unavailable - skipping cleanup"
        fi
    elif [ "$NO_TEARDOWN" = "true" ]; then
        echo "🔄 No teardown mode: Keeping containers running..."
    else
        echo "🔄 Watch mode: Keeping containers running for next test..."
    fi
    
    echo "--------------------------------------"
    echo ""
}

# Function to force clean everything before starting
force_cleanup_before_start() {
    echo "🧹 Performing initial cleanup to ensure clean state..."
    
    if [ "$FAST_MODE" = "true" ]; then
        echo "🏃 Fast mode: Skipping heavy Docker cleanup before start"
        return 0
    fi
    
    # Check if Docker is available before attempting cleanup
    if ! is_docker_available; then
        echo "⚠️  Docker is not available - skipping Docker cleanup, only killing processes"
        pkill -9 -f "docker-compose" 2>/dev/null || true
        pkill -9 -f "docker.*build" 2>/dev/null || true
        echo "✅ Process cleanup completed (Docker unavailable)"
        return 0
    fi
    
    # Kill any existing docker-compose processes
    echo "Killing existing docker-compose processes..."
    pkill -9 -f "docker-compose" 2>/dev/null || true
    pkill -9 -f "docker.*build" 2>/dev/null || true
    
    # Stop all containers related to this project
    echo "Stopping project containers..."
    docker-compose down --remove-orphans --volumes 2>/dev/null || true
    
    # Remove any hanging containers
    echo "Removing hanging containers..."
    docker container ls -a --filter "name=dbdiff" --format "{{.ID}}" | xargs -r docker rm -f 2>/dev/null || true
    
    # Remove all project images to start fresh
    echo "Removing project images..."
    docker images --filter "reference=dbdiff*" --format "{{.ID}}" | xargs -r docker rmi -f 2>/dev/null || true
    docker images --filter "reference=phpmyadmin/phpmyadmin*" --format "{{.ID}}" | xargs -r docker rmi -f 2>/dev/null || true
    
    # Clean up all unused resources aggressively
    echo "Cleaning all unused Docker resources..."
    docker system prune -a -f --volumes 2>/dev/null || true
    
    # Clean up build cache to avoid issues
    echo "Cleaning build cache..."
    docker builder prune -a -f 2>/dev/null || true
    
    # Show disk usage after cleanup
    echo "💾 Disk usage after cleanup:"
    show_docker_disk_usage
    
    echo "✅ Initial cleanup completed"
    echo ""
}

# Function to run tests in watch mode
run_watch_mode() {
    local php_arg=$1
    local mysql_arg=$2
    
    # Check if this is a multiple combination scenario
    if [ "$php_arg" = "all" ] || [ "$mysql_arg" = "all" ]; then
        run_watch_mode_all $php_arg $mysql_arg
        return
    fi
    
    # Single combination watch mode
    echo "🔄 Watch mode enabled - running tests once, then keeping containers active"
    echo "Press Ctrl+C to exit and cleanup"
    echo ""
    
    echo "🔄 Running tests - $(date)"
    echo "======================================"
    
    # Test the specific combination
    test_combination $php_arg $mysql_arg
    
    echo "✅ Initial test run completed"
    echo ""
    
    # Start additional services for manual use
    start_additional_services_for_watch $php_arg $mysql_arg
    
    echo ""
    echo "🐳 Containers and resources are now active and ready for manual use"
    echo ""
    
    # Show running services and their URLs
    show_running_services
    
    echo "⏳ Waiting for Ctrl+C to cleanup and exit..."
    echo "💡 Tip: Keep this terminal open to see service URLs, use another terminal for manual testing"
    echo ""
    
    # Wait indefinitely until interrupt
    while true; do
        sleep 60
    done
}

# Function to run multiple combinations in watch mode (one at a time)
run_watch_mode_all() {
    local php_arg=$1
    local mysql_arg=$2
    
    local combinations=()
    
    # Build list of combinations to test
    if [ "$php_arg" = "all" ] && [ "$mysql_arg" = "all" ]; then
        # All combinations
        for php_version in "${PHP_VERSIONS[@]}"; do
            for mysql_version in "${MYSQL_VERSIONS[@]}"; do
                combinations+=("$php_version:$mysql_version")
            done
        done
    elif [ "$php_arg" = "all" ]; then
        # All PHP versions with specific MySQL
        for php_version in "${PHP_VERSIONS[@]}"; do
            combinations+=("$php_version:$mysql_arg")
        done
    elif [ "$mysql_arg" = "all" ]; then
        # Specific PHP with all MySQL versions
        for mysql_version in "${MYSQL_VERSIONS[@]}"; do
            combinations+=("$php_arg:$mysql_version")
        done
    fi
    
    echo "🔄 Watch mode with multiple combinations enabled"
    echo "📋 Will test ${#combinations[@]} combinations sequentially"
    echo "⏭️  Press Ctrl+C to move to next combination or exit if on last one"
    echo ""
    
    for i in "${!combinations[@]}"; do
        local combination="${combinations[$i]}"
        local php_version="${combination%:*}"
        local mysql_version="${combination#*:}"
        local current=$((i + 1))
        local total=${#combinations[@]}
        
        echo "🔄 Combination $current/$total: PHP $php_version + MySQL $mysql_version"
        echo "==============================================================="
        
        # Run the test combination
        test_combination $php_version $mysql_version
        
        if [ $? -ne 0 ]; then
            echo "❌ Test failed for combination $current/$total"
            read -p "Continue to next combination? (y/N): " continue_choice
            if [[ ! "$continue_choice" =~ ^[Yy]$ ]]; then
                echo "Stopping at user request"
                break
            fi
        fi
        
        # Start additional services for manual use
        start_additional_services_for_watch $php_version $mysql_version
        
        echo ""
        echo "🐳 Combination $current/$total is now active and ready for manual use"
        echo ""
        
        # Show running services and their URLs
        show_running_services
        
        if [ $current -lt $total ]; then
            echo "⏭️  Press Ctrl+C to move to next combination: PHP ${combinations[$((i+1))]%:*} + MySQL ${combinations[$((i+1))]#*:}"
        else
            echo "🏁 This is the last combination. Press Ctrl+C to exit and cleanup."
        fi
        echo "💡 Tip: Use another terminal for manual testing"
        echo ""
        
        # Wait for interrupt to move to next combination
        local interrupted=false
        trap 'interrupted=true' INT
        while [ "$interrupted" = "false" ]; do
            sleep 1
        done
        trap cleanup_on_interrupt INT
        
        echo ""
        echo "🧹 Moving to next combination - cleaning up current services..."
        
        # Clean up current combination unless it's the last one and NO_TEARDOWN is set
        if [ $current -lt $total ] || [ "$NO_TEARDOWN" != "true" ]; then
            cleanup_docker
        fi
        
        echo ""
        
        # Break if this was the last combination
        if [ $current -ge $total ]; then
            echo "🏁 All combinations completed!"
            break
        fi
    done
}

# Function to start additional services for watch mode (CLI and PHPMyAdmin)
start_additional_services_for_watch() {
    local php_version=$1
    local mysql_version=$2
    local cli_service="cli-php${php_version//.}-${mysql_version}"
    local phpmyadmin_service="phpmyadmin-${mysql_version}"
    
    echo "🚀 Starting additional services for manual use..."
    
    # Start CLI service
    echo "Starting CLI service: $cli_service"
    if ! run_docker_compose_with_timeout 120 "CLI service startup" up -d $cli_service; then
        echo "⚠️  Failed to start CLI service $cli_service (non-critical)"
    fi
    
    # Start PHPMyAdmin service
    echo "Starting PHPMyAdmin service: $phpmyadmin_service"
    if ! run_docker_compose_with_timeout 120 "PHPMyAdmin service startup" up -d $phpmyadmin_service; then
        echo "⚠️  Failed to start PHPMyAdmin service $phpmyadmin_service (non-critical)"
    fi
    
    echo "✅ Additional services started"
}

# Function to show running services with their localhost URLs
show_running_services() {
    echo "🌐 Running Services & Access URLs:"
    echo "================================="
    
    # Check if any containers are running
    local running_containers=$(docker-compose ps --format table 2>/dev/null | grep -v "Name\|----" | grep -E "(Up|running)" || true)
    
    if [ -z "$running_containers" ]; then
        echo "ℹ️  No containers are currently running"
        return
    fi
    
    # Get list of running service names
    local running_services=$(docker-compose ps --services --filter status=running 2>/dev/null || true)
    
    # Database services
    echo "📊 Database Services:"
    if echo "$running_services" | grep -q "db-mysql80"; then
        echo "  • MySQL ${MYSQL_VERSION_80:-8.0}:  mysql://$DB_USER:$DB_PASSWORD@localhost:${DB_PORT_MYSQL80:-3306}/$DB_NAME"
        echo "    Root access: mysql://root:$DB_ROOT_PASSWORD@localhost:${DB_PORT_MYSQL80:-3306}/$DB_NAME"
    fi
    if echo "$running_services" | grep -q "db-mysql84"; then
        echo "  • MySQL ${MYSQL_VERSION_84:-8.4}:  mysql://$DB_USER:$DB_PASSWORD@localhost:${DB_PORT_MYSQL84:-3307}/$DB_NAME"
        echo "    Root access: mysql://root:$DB_ROOT_PASSWORD@localhost:${DB_PORT_MYSQL84:-3307}/$DB_NAME"
    fi
    if echo "$running_services" | grep -q "db-mysql93"; then
        echo "  • MySQL ${MYSQL_VERSION_93:-9.3}:  mysql://$DB_USER:$DB_PASSWORD@localhost:${DB_PORT_MYSQL93:-3308}/$DB_NAME"
        echo "    Root access: mysql://root:$DB_ROOT_PASSWORD@localhost:${DB_PORT_MYSQL93:-3308}/$DB_NAME"
    fi
    
    # Only show database section if we have any DB services running
    if echo "$running_services" | grep -q "db-mysql"; then
        echo ""
    fi
    
    # PHPMyAdmin services
    local has_phpmyadmin=false
    echo "🔧 PHPMyAdmin Web Interfaces:"
    if echo "$running_services" | grep -q "phpmyadmin-mysql80"; then
        echo "  • MySQL ${MYSQL_VERSION_80:-8.0}:  http://localhost:${PHPMYADMIN_PORT_MYSQL80:-18080}"
        has_phpmyadmin=true
    fi
    if echo "$running_services" | grep -q "phpmyadmin-mysql84"; then
        echo "  • MySQL ${MYSQL_VERSION_84:-8.4}:  http://localhost:${PHPMYADMIN_PORT_MYSQL84:-18081}"
        has_phpmyadmin=true
    fi
    if echo "$running_services" | grep -q "phpmyadmin-mysql93"; then
        echo "  • MySQL ${MYSQL_VERSION_93:-9.3}:  http://localhost:${PHPMYADMIN_PORT_MYSQL93:-18082}"
        has_phpmyadmin=true
    fi
    
    if [ "$has_phpmyadmin" = "false" ]; then
        echo "  (No PHPMyAdmin services currently running)"
    fi
    echo ""
    
    # CLI services
    local has_cli=false
    echo "💻 PHP CLI Services (use docker-compose exec):"
    if echo "$running_services" | grep -q "cli-php74-mysql80"; then
        echo "  • php74-mysql80: docker-compose exec cli-php74-mysql80 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php74-mysql84"; then
        echo "  • php74-mysql84: docker-compose exec cli-php74-mysql84 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php74-mysql93"; then
        echo "  • php74-mysql93: docker-compose exec cli-php74-mysql93 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php83-mysql80"; then
        echo "  • php83-mysql80: docker-compose exec cli-php83-mysql80 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php83-mysql84"; then
        echo "  • php83-mysql84: docker-compose exec cli-php83-mysql84 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php83-mysql93"; then
        echo "  • php83-mysql93: docker-compose exec cli-php83-mysql93 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php84-mysql80"; then
        echo "  • php84-mysql80: docker-compose exec cli-php84-mysql80 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php84-mysql84"; then
        echo "  • php84-mysql84: docker-compose exec cli-php84-mysql84 bash"
        has_cli=true
    fi
    if echo "$running_services" | grep -q "cli-php84-mysql93"; then
        echo "  • php84-mysql93: docker-compose exec cli-php84-mysql93 bash"
        has_cli=true
    fi
    
    if [ "$has_cli" = "false" ]; then
        echo "  (No CLI services currently running)"
    fi
    echo ""
    
    # Show actual running containers
    echo "🐳 Currently Running Containers:"
    docker-compose ps --format table 2>/dev/null | grep -E "(Name|Up|running|----)" || echo "Unable to fetch container status"
    echo ""
}

# Function to test signal handling (for debugging)
test_signal_handling() {
    echo "🧪 Testing signal handling..."
    echo "Press Ctrl+C within 10 seconds to test interrupt handling..."
    
    for i in {1..10}; do
        echo "Waiting... $i/10"
        sleep 1
    done
    
    echo "✅ Signal handling test completed"
}

# Function to start a watchdog that helps with stuck processes
start_watchdog() {
    (
        sleep 60  # Wait 60 seconds
        while true; do
            # Check if any docker-compose run commands have been running too long
            local long_running=$(ps aux | grep "docker-compose.*run" | grep -v grep | awk '$10 > 120 {print $2}')
            if [ -n "$long_running" ]; then
                echo ""
                echo "⚠️  WATCHDOG: Detected long-running docker-compose processes"
                echo "PIDs: $long_running"
                echo "💡 If the script seems stuck, press Ctrl+C or run: kill -9 $long_running"
                echo ""
            fi
            sleep 30
        done
    ) &
    echo $! > /tmp/test_runner_watchdog.pid
}

# Function to stop the watchdog
stop_watchdog() {
    if [ -f /tmp/test_runner_watchdog.pid ]; then
        local watchdog_pid=$(cat /tmp/test_runner_watchdog.pid)
        kill $watchdog_pid 2>/dev/null || true
        rm -f /tmp/test_runner_watchdog.pid
    fi
}

# Function to debug docker-compose run issues
debug_docker_run() {
    local service_name=$1
    echo "🔍 Debugging docker-compose run for service: $service_name"
    echo "Docker-compose version:"
    docker-compose --version
    echo ""
    echo "Available docker-compose run flags:"
    docker-compose run --help | grep -E "(-T|--no-TTY|--no-tty)" || echo "No TTY flags found"
    echo ""
    echo "Testing simple command:"
    local run_flags=$(get_docker_run_flags)
    echo "Using flags: $run_flags"
    docker-compose run $run_flags --entrypoint="" $service_name echo "Test successful"
}

# Function to convert service name back to human-readable MySQL version
convert_service_to_mysql_version() {
    local service_name="$1"
    
    # Convert service name back to version number
    for mapping in "${MYSQL_VERSION_MAPPING[@]}"; do
        local version_num="${mapping%:*}"
        local mapped_service="${mapping#*:}"
        if [ "$service_name" = "$mapped_service" ]; then
            echo "$version_num"
            return 0
        fi
    done
    
    # If no mapping found, return original input
    echo "$service_name"
}

# Function to convert MySQL version number to service name
convert_mysql_version() {
    local input_version="$1"
    
    # If it's already in mysql80 format, return as-is
    if [[ "$input_version" =~ ^mysql[0-9]+$ ]]; then
        echo "$input_version"
        return 0
    fi
    
    # Convert version number to service name
    for mapping in "${MYSQL_VERSION_MAPPING[@]}"; do
        local version_num="${mapping%:*}"
        local service_name="${mapping#*:}"
        if [ "$input_version" = "$version_num" ]; then
            echo "$service_name"
            return 0
        fi
    done
    
    # If no mapping found, return original input
    echo "$input_version"
}

# Function to get human-readable MySQL versions for display
get_mysql_version_display() {
    local versions=()
    for mapping in "${MYSQL_VERSION_MAPPING[@]}"; do
        local version_num="${mapping%:*}"
        versions+=("$version_num")
    done
    echo "${versions[@]}"
}

# Parse command line arguments
WATCH_MODE="false"
NO_TEARDOWN="false"
FAST_MODE="false"

# Check for flags
for arg in "$@"; do
    if [ "$arg" = "--watch" ]; then
        WATCH_MODE="true"
    elif [ "$arg" = "--no-teardown" ]; then
        NO_TEARDOWN="true"
    elif [ "$arg" = "--fast" ]; then
        FAST_MODE="true"
    fi
done

# Remove flags from arguments
args=()
for arg in "$@"; do
    if [ "$arg" != "--watch" ] && [ "$arg" != "--no-teardown" ] && [ "$arg" != "--fast" ]; then
        args+=("$arg")
    fi
done

if [ ${#args[@]} -eq 0 ]; then
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
    local mysql_display=($(get_mysql_version_display))
    select mysql_choice in "${mysql_display[@]}" "all"; do
        if [ -n "$mysql_choice" ]; then
            break
        fi
    done
    
    # Ask about watch mode
    echo "Enable watch mode? (y/N)"
    read -r watch_choice
    if [[ "$watch_choice" =~ ^[Yy]$ ]]; then
        WATCH_MODE="true"
    fi
    
    # Ask about no teardown mode
    echo "Enable no teardown mode (keep containers after completion)? (y/N)"
    read -r no_teardown_choice
    if [[ "$no_teardown_choice" =~ ^[Yy]$ ]]; then
        NO_TEARDOWN="true"
    fi
    
    # Ask about fast mode
    echo "Enable fast restart mode (skip heavy cleanup)? (y/N)"
    read -r fast_choice
    if [[ "$fast_choice" =~ ^[Yy]$ ]]; then
        FAST_MODE="true"
    fi
    
    PHP_ARG=$php_choice
    MYSQL_ARG=$mysql_choice
elif [ ${#args[@]} -eq 2 ]; then
    PHP_ARG=${args[0]}
    MYSQL_ARG=${args[1]}
else
    show_usage
    exit 1
fi

# Convert MySQL version if it's a number (e.g., 8.0 -> mysql80)
if [ "$MYSQL_ARG" != "all" ]; then
    MYSQL_ARG=$(convert_mysql_version "$MYSQL_ARG")
fi

echo "=== DBDiff Test Runner ==="
echo "PHP version: $PHP_ARG"
echo "MySQL version: $(convert_service_to_mysql_version "$MYSQL_ARG")"
if [ "$WATCH_MODE" = "true" ]; then
    echo "Mode: Watch (containers stay active until Ctrl+C)"
elif [ "$NO_TEARDOWN" = "true" ]; then
    echo "Mode: Single run with no teardown (containers stay active)"
else
    echo "Mode: Single run (containers cleaned up after tests)"
fi
if [ "$FAST_MODE" = "true" ]; then
    echo "Efficiency: Fast Restart (skipping heavy cleanup)"
fi
echo ""

# Start watchdog to monitor for hanging processes
start_watchdog

# Check Docker daemon availability first
if ! is_docker_available; then
    echo "❌ Docker daemon is not accessible or not running"
    echo "Please start Docker Desktop or the Docker daemon and try again"
    exit 1
fi

# Check for port conflicts before starting
if ! check_port_conflicts; then
    echo "Exiting due to port conflicts. Please resolve them first."
    exit 1
fi

# Run tests based on mode
if [ "$WATCH_MODE" = "true" ]; then
    run_watch_mode $PHP_ARG $MYSQL_ARG
else
    # Single run mode
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
    echo ""
    
    if [ "$NO_TEARDOWN" = "true" ]; then
        # Show running services and keep them active
        echo "📋 Services are now active and ready for manual use:"
        show_running_services
        echo "🔄 No teardown mode: Containers will remain running"
        echo "💡 Use 'docker-compose down' or run stop.sh to cleanup manually"
    else
        # Show running services before cleanup (user has a few seconds to see them)
        echo "📋 Services that were running during tests:"
        show_running_services
        echo "ℹ️  Services will be cleaned up automatically in 5 seconds..."
        echo "💡 Use --watch mode to keep services running for manual inspection"
        sleep 5
    fi
fi
