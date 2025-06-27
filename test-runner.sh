#!/bin/bash

# Test runner script for different PHP versions
# Usage: ./test-runner.sh [php_version] [mysql_version]
# Example: ./test-runner.sh 8.3 mysql80
#          ./test-runner.sh all all (runs all combinations)
#          ./test-runner.sh (interactive mode)

# Enable Docker Compose Bake for better build performance
export COMPOSE_BAKE=true

# Set up signal handling for cleanup
trap cleanup_on_exit EXIT
trap cleanup_on_interrupt INT TERM

cleanup_on_exit() {
    local exit_code=$?
    stop_watchdog
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
    echo "🛑 Interrupted! Force cleaning up..."
    
    stop_watchdog
    cleanup_docker
    echo "Cleanup completed. Exiting..."
    exit 130
}

PHP_VERSIONS=("7.4" "8.3" "8.4")
MYSQL_VERSIONS=("mysql80" "mysql84" "mysql93")

# Function to show usage
show_usage() {
    echo "Usage: $0 [php_version] [mysql_version] [--watch]"
    echo ""
    echo "Available PHP versions: ${PHP_VERSIONS[@]}"
    echo "Available MySQL versions: ${MYSQL_VERSIONS[@]}"
    echo ""
    echo "Examples:"
    echo "  $0 8.3 mysql80          # Test specific combination"
    echo "  $0 all all              # Test all combinations"
    echo "  $0 8.3 mysql80 --watch  # Watch mode - run tests repeatedly"
    echo "  $0                      # Interactive mode"
    echo ""
    echo "Watch mode:"
    echo "  In watch mode, tests run continuously until you press Ctrl+C"
    echo "  Containers are kept running between tests for faster execution"
    echo ""
}

# Function to check for port conflicts
check_port_conflicts() {
    local ports=("3306" "3307" "3308" "18080" "18081" "18082")
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
    
    # Remove project images
    local images=$(docker images --filter "reference=dbdiff*" --format "{{.ID}}" 2>/dev/null)
    if [ -n "$images" ]; then
        echo "Removing project images..."
        echo "$images" | xargs -r docker rmi -f 2>/dev/null && echo "✅ Project images removed"
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
    echo "📊 PHP: $php_version | MySQL: $mysql_version"
    echo "🐳 Service: $service_name | DB: $db_service"
    echo ""
    
    # Start database service first with timeout monitoring
    echo "🗄️  Starting database service: $db_service"
    if ! run_docker_compose_with_timeout 180 "Database startup" up -d --build $db_service; then
        echo "❌ Failed to start database service $db_service"
        return 1
    fi
    
    echo ""
    echo "⏳ Waiting for database to become healthy..."
    local timeout=120
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
    
    # Build CLI service if needed
    echo ""
    echo "🔨 Building CLI service: $service_name"
    if ! run_docker_compose_with_timeout 300 "CLI service build" build $service_name; then
        echo "❌ Failed to build CLI service $service_name"
        return 1
    fi
    
    # Test PHP version info
    echo ""
    echo "📋 Getting PHP version..."
    local run_flags=$(get_docker_run_flags)
    
    if run_docker_compose_with_timeout 30 "PHP version check" run $run_flags --entrypoint="" $service_name php --version; then
        echo "✅ PHP version check successful"
    else
        echo "❌ Failed to get PHP version for $service_name"
        return 1
    fi
    
    echo ""
    echo "🧪 Running PHPUnit Tests..."
    
    # Run PHPUnit with timeout and progress monitoring
    local test_start_time=$(date +%s)
    if run_docker_compose_with_timeout 300 "PHPUnit test execution" run $run_flags --entrypoint="" $service_name ./vendor/bin/phpunit --colors=always --verbose; then
        local test_end_time=$(date +%s)
        local test_duration=$((test_end_time - test_start_time))
        echo "✅ All tests PASSED for $service_name (${test_duration}s)"
    else
        local exit_code=$?
        local test_end_time=$(date +%s)
        local test_duration=$((test_end_time - test_start_time))
        
        if [ $exit_code -eq 124 ]; then
            echo "❌ PHPUnit tests TIMED OUT for $service_name after ${test_duration}s (5 minute limit)"
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
    
    # Only cleanup in non-watch mode
    if [ "$WATCH_MODE" != "true" ]; then
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
    else
        echo "🔄 Watch mode: Keeping containers running for next test..."
    fi
    
    echo "--------------------------------------"
    echo ""
}

# Function to force clean everything before starting
force_cleanup_before_start() {
    echo "🧹 Performing initial cleanup to ensure clean state..."
    
    # Check if Docker is available before attempting cleanup
    if ! is_docker_available; then
        echo "⚠️  Docker is not available - skipping Docker cleanup, only killing processes"
        pkill -9 -f "docker-compose" 2>/dev/null || true
        pkill -9 -f "docker.*build" 2>/dev/null || true
        echo "✅ Process cleanup completed (Docker unavailable)"
        return 0
    fi
    
    # Show disk usage before cleanup
    echo "💾 Disk usage before cleanup:"
    show_docker_disk_usage
    
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
    
    echo "🔄 Watch mode enabled - running tests once, then keeping containers active"
    echo "Press Ctrl+C to exit and cleanup"
    echo ""
    
    echo "🔄 Running tests - $(date)"
    echo "======================================"
    
    # Determine which combinations to test
    if [ "$php_arg" = "all" ] && [ "$mysql_arg" = "all" ]; then
        # Test all combinations
        for php_version in "${PHP_VERSIONS[@]}"; do
            for mysql_version in "${MYSQL_VERSIONS[@]}"; do
                test_combination $php_version $mysql_version
            done
        done
    elif [ "$php_arg" = "all" ]; then
        # Test all PHP versions with specific MySQL
        for php_version in "${PHP_VERSIONS[@]}"; do
            test_combination $php_version $mysql_arg
        done
    elif [ "$mysql_arg" = "all" ]; then
        # Test specific PHP with all MySQL versions
        for mysql_version in "${MYSQL_VERSIONS[@]}"; do
            test_combination $php_arg $mysql_version
        done
    else
        # Test specific combination
        test_combination $php_arg $mysql_arg
    fi
    
    echo "✅ Initial test run completed"
    echo ""
    echo "🐳 Containers and resources are now active and ready for manual use"
    echo "� You can use docker-compose commands in another terminal to interact with them"
    echo "⏳ Waiting for Ctrl+C to cleanup and exit..."
    echo ""
    
    # Wait indefinitely until interrupt
    while true; do
        sleep 60
    done
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

# Parse command line arguments
WATCH_MODE="false"

# Check for --watch flag
for arg in "$@"; do
    if [ "$arg" = "--watch" ]; then
        WATCH_MODE="true"
        break
    fi
done

# Remove --watch from arguments
args=()
for arg in "$@"; do
    if [ "$arg" != "--watch" ]; then
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
    select mysql_choice in "${MYSQL_VERSIONS[@]}" "all"; do
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
    
    PHP_ARG=$php_choice
    MYSQL_ARG=$mysql_choice
elif [ ${#args[@]} -eq 2 ]; then
    PHP_ARG=${args[0]}
    MYSQL_ARG=${args[1]}
else
    show_usage
    exit 1
fi

echo "=== DBDiff Test Runner ==="
echo "PHP version: $PHP_ARG"
echo "MySQL version: $MYSQL_ARG"
if [ "$WATCH_MODE" = "true" ]; then
    echo "Mode: Watch (continuous testing)"
else
    echo "Mode: Single run"
fi
echo ""

# Start watchdog to monitor for hanging processes
start_watchdog

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
fi
