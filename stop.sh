#!/bin/bash

# Stop script for DBDiff Docker containers and services
# This provides the same teardown functionality as start.sh --no-teardown cleanup

echo "🛑 DBDiff Stop Script"
echo "This will stop and clean up all DBDiff Docker containers, images, volumes, and networks"
echo ""

# Source .env file for configuration
if [ -f .env ]; then
    set -a
    source .env
    set +a
    echo "✅ Configuration loaded from .env"
else
    echo "⚠️  .env file not found - using defaults"
fi

# Set defaults if not provided in .env
DATABASE_STARTUP_TIMEOUT=${DATABASE_STARTUP_TIMEOUT:-90}
DATABASE_HEALTH_TIMEOUT=${DATABASE_HEALTH_TIMEOUT:-30}
CLI_BUILD_TIMEOUT=${CLI_BUILD_TIMEOUT:-120}
PHPUNIT_TEST_TIMEOUT=${PHPUNIT_TEST_TIMEOUT:-300}
PHP_VERSION_CHECK_TIMEOUT=${PHP_VERSION_CHECK_TIMEOUT:-30}

# Function to check if Docker daemon is accessible
is_docker_available() {
    docker info >/dev/null 2>&1
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

# Function to perform comprehensive Docker cleanup (matches start.sh cleanup_docker)
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
    
    echo "✅ Docker cleanup completed"
    echo ""
}

# Function to perform aggressive cleanup (matches start.sh force_cleanup_before_start)
force_cleanup() {
    echo "🧹 Performing aggressive cleanup..."
    
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
    
    echo "✅ Aggressive cleanup completed"
    echo ""
}

# Function to show help
show_help() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Stop and clean up DBDiff Docker containers, images, volumes, and networks."
    echo ""
    echo "Options:"
    echo "  --help, -h      Show this help message"
    echo "  --aggressive    Perform aggressive cleanup (removes everything)"
    echo "  --normal        Perform normal cleanup (default)"
    echo ""
    echo "Examples:"
    echo "  $0                    # Normal cleanup"
    echo "  $0 --normal           # Normal cleanup"
    echo "  $0 --aggressive       # Aggressive cleanup"
    echo ""
    echo "This script provides the same teardown functionality as start.sh"
    echo "and is particularly useful after running start.sh with --no-teardown."
}

# Main execution logic
main() {
    local cleanup_mode="normal"
    
    # Parse command line arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --help|-h)
                show_help
                exit 0
                ;;
            --aggressive)
                cleanup_mode="aggressive"
                shift
                ;;
            --normal)
                cleanup_mode="normal"
                shift
                ;;
            *)
                echo "❌ Unknown option: $1"
                echo "Use --help to see available options"
                exit 1
                ;;
        esac
    done
    
    # Kill any start.sh processes
    echo "Killing start script processes..."
    pkill -9 -f "start.sh" 2>/dev/null || true
    
    # Kill watchdog if it exists
    if [ -f /tmp/test_runner_watchdog.pid ]; then
        local watchdog_pid=$(cat /tmp/test_runner_watchdog.pid)
        kill $watchdog_pid 2>/dev/null || true
        rm -f /tmp/test_runner_watchdog.pid
        echo "✅ Watchdog process cleaned up"
    fi
    
    # Check Docker daemon availability
    if ! is_docker_available; then
        echo "❌ Docker daemon is not accessible or not running"
        echo "Please start Docker Desktop and run this script again if needed"
        exit 1
    fi
    
    # Show initial disk usage
    echo "💾 Disk usage before cleanup:"
    show_docker_disk_usage
    
    # Perform cleanup based on mode
    case $cleanup_mode in
        aggressive)
            echo "🔥 Performing AGGRESSIVE cleanup..."
            force_cleanup
            ;;
        normal)
            echo "🧹 Performing NORMAL cleanup..."
            cleanup_docker
            ;;
    esac
    
    echo "✅ Stop script completed!"
    echo "📝 All DBDiff Docker resources have been cleaned up"
    echo "You can now run start.sh again when needed."
}

# Run main function with all arguments
main "$@"
