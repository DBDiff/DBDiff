#!/bin/bash

# Emergency cleanup script for when test-runner.sh gets stuck
# Run this from another terminal: ./emergency-cleanup.sh

echo "🚨 EMERGENCY CLEANUP SCRIPT"
echo "This will forcefully kill all Docker processes and clean up aggressively"
echo ""

# Function to check if Docker daemon is accessible
check_docker_daemon() {
    if ! docker info >/dev/null 2>&1; then
        echo "❌ Docker daemon is not accessible or not running"
        echo "Please start Docker Desktop or the Docker daemon first"
        return 1
    fi
    return 0
}

# Function to safely execute docker commands with error handling
safe_docker_cmd() {
    local description="$1"
    shift
    local cmd=("$@")
    
    echo "🔧 $description..."
    if ! check_docker_daemon; then
        echo "⚠️  Skipping Docker command due to daemon unavailability"
        return 1
    fi
    
    if "${cmd[@]}" 2>/dev/null; then
        echo "✅ $description completed"
        return 0
    else
        echo "⚠️  $description failed or had no effect"
        return 1
    fi
}

# Show disk usage before cleanup (if Docker is available)
if check_docker_daemon; then
    echo "💾 Disk usage before emergency cleanup:"
    docker system df 2>/dev/null || echo "Could not get Docker disk usage"
    echo ""
else
    echo "⚠️  Docker daemon not available - skipping disk usage check"
    echo ""
fi

# Step 1: Kill processes (always works regardless of Docker daemon state)
echo "=== STEP 1: Killing processes ==="
echo "Killing test runner script..."
pkill -9 -f "test-runner.sh" 2>/dev/null || true

echo "Killing docker-compose processes..."
pkill -9 -f "docker-compose" 2>/dev/null || true

echo "Killing docker build/run processes..."
pkill -9 -f "docker.*build" 2>/dev/null || true
pkill -9 -f "docker.*run" 2>/dev/null || true

echo "✅ Process cleanup completed"
echo ""

# Only proceed with Docker cleanup if daemon is accessible
if ! check_docker_daemon; then
    echo "❌ Stopping here - Docker daemon is not accessible"
    echo "Please start Docker Desktop and run this script again if needed"
    exit 1
fi

# Step 2: Stop and kill containers
echo "=== STEP 2: Stopping containers ==="
safe_docker_cmd "Killing all running containers" docker kill $(docker ps -q)
safe_docker_cmd "Stopping all containers" docker stop $(docker ps -a -q)
safe_docker_cmd "Docker-compose down" docker-compose down --remove-orphans --volumes
echo ""

# Step 3: Remove containers
echo "=== STEP 3: Removing containers ==="
safe_docker_cmd "Removing dbdiff containers" bash -c 'docker container ls -a --filter "name=dbdiff" --format "{{.ID}}" | xargs -r docker rm -f'
safe_docker_cmd "Removing all stopped containers" docker container prune -f
echo ""

# Step 4: Remove images
echo "=== STEP 4: Removing images ==="
safe_docker_cmd "Removing dbdiff images" bash -c 'docker images --filter "reference=dbdiff*" --format "{{.ID}}" | xargs -r docker rmi -f'
safe_docker_cmd "Removing dangling images" bash -c 'docker images -f "dangling=true" -q | xargs -r docker rmi -f'
safe_docker_cmd "Removing all unused images" docker image prune -a -f
echo ""

# Step 5: Remove volumes
echo "=== STEP 5: Removing volumes ==="
safe_docker_cmd "Removing all unused volumes" docker volume prune -f
echo ""

# Step 6: Remove networks
echo "=== STEP 6: Removing networks ==="
safe_docker_cmd "Removing all unused networks" docker network prune -f
echo ""

# Step 7: Remove build cache
echo "=== STEP 7: Removing build cache ==="
safe_docker_cmd "Removing all build cache" docker builder prune -a -f
echo ""

# Step 8: Final system cleanup
echo "=== STEP 8: Final system cleanup ==="
safe_docker_cmd "System-wide cleanup" docker system prune -a -f --volumes
echo ""

# Kill watchdog if it exists
if [ -f /tmp/test_runner_watchdog.pid ]; then
    local watchdog_pid=$(cat /tmp/test_runner_watchdog.pid)
    kill $watchdog_pid 2>/dev/null || true
    rm -f /tmp/test_runner_watchdog.pid
    echo "✅ Watchdog process cleaned up"
fi

# Show disk usage after cleanup
if check_docker_daemon; then
    echo "💾 Disk usage after emergency cleanup:"
    docker system df 2>/dev/null || echo "Could not get Docker disk usage"
else
    echo "⚠️  Docker daemon not available for final disk usage check"
fi

echo ""
echo "✅ Emergency cleanup completed!"
echo "📝 Note: If Docker daemon was not running, start Docker Desktop first"
echo "You can now run the test-runner.sh script again."
