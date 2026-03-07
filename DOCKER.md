# Docker Configuration for DBDiff

This Docker setup provides multiple PHP and MySQL version combinations for testing DBDiff across different environments.

## Configuration

The Docker setup is configurable via environment variables. Copy `.env.example` to `.env` and customize:

```bash
cp .env.example .env
# Edit .env to customize ports, database credentials, timeouts, etc.
```

### Environment Variables

Key configuration options:
- `DB_PORT_MYSQL80`, `DB_PORT_MYSQL84`, `DB_PORT_MYSQL93` - MySQL database ports
- `DB_PORT_POSTGRES16` - PostgreSQL 16 database port (default: `5432`)
- `PHPMYADMIN_PORT_MYSQL80`, `PHPMYADMIN_PORT_MYSQL84`, `PHPMYADMIN_PORT_MYSQL93` - PHPMyAdmin ports  
- `DB_ROOT_PASSWORD`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` - Database credentials (shared by MySQL and PostgreSQL)
- `DATABASE_STARTUP_TIMEOUT`, `PHPUNIT_TEST_TIMEOUT` - Timeout configurations

## Podman Support

Podman is a daemonless, rootless, Docker-compatible container engine. All `docker-compose` commands work identically with `podman-compose`. Podman is a great alternative if you do not have Docker Desktop installed or prefer not to run a background daemon.

### Installing Podman

**Ubuntu / Debian:**
```bash
sudo apt-get install -y podman podman-compose
```

**macOS (via Homebrew):**
```bash
brew install podman podman-compose
podman machine init && podman machine start
```

**Windows (via winget):**
```powershell
winget install RedHat.Podman
winget install RedHat.Podman-Desktop  # optional GUI
```

> Podman Desktop is a cross-platform GUI available at https://podman-desktop.io/ — it provides a Docker Desktop-like interface and can run the same compose files without any changes.

### Using podman-compose instead of docker-compose

Simply substitute `docker-compose` for `podman-compose` in any command in this guide:

```bash
# Start PostgreSQL and run Postgres e2e tests
podman-compose up -d db-postgres16
podman-compose run --rm \
  -e DB_HOST_POSTGRES=db-postgres16 \
  cli-php83-postgres16 \
  bash -c "scripts/run-tests.sh --postgres db-postgres16"

# Stop everything
podman-compose down
```

The `start.sh` and `stop.sh` scripts also support Podman automatically if `docker-compose` is not found:

```bash
# Prefer podman-compose when docker-compose is unavailable
COMPOSE_CMD=podman-compose ./start.sh 8.3 8.0
```

### Rootless networking note

Podman runs containers as your own user by default. If port binding below 1024 fails, either:
- Map to a high port in `.env` (e.g. `DB_PORT_MYSQL80=13306`)
- Or run `sudo sysctl net.ipv4.ip_unprivileged_port_start=0` once

## Available Configurations

### PHP Versions
- PHP 8.1
- PHP 8.2
- PHP 8.3
- PHP 8.4
- PHP 8.5

### MySQL Versions
- MySQL 8.0 (port 3306)
- MySQL 8.4 (port 3307)
- MySQL 9.3 (port 3308)
- MySQL 9.6 (port 3309)

### PostgreSQL Versions
- PostgreSQL 16 (port 5432)

## Services

### CLI Services (for running DBDiff)
- `cli-php81-mysql80` - PHP 8.1 with MySQL 8.0
- `cli-php81-mysql84` - PHP 8.1 with MySQL 8.4
- `cli-php81-mysql93` - PHP 8.1 with MySQL 9.3
- `cli-php81-mysql96` - PHP 8.1 with MySQL 9.6
- `cli-php82-mysql80` - PHP 8.2 with MySQL 8.0
- `cli-php82-mysql84` - PHP 8.2 with MySQL 8.4
- `cli-php82-mysql93` - PHP 8.2 with MySQL 9.3
- `cli-php82-mysql96` - PHP 8.2 with MySQL 9.6
- `cli-php83-mysql80` - PHP 8.3 with MySQL 8.0
- `cli-php83-mysql84` - PHP 8.3 with MySQL 8.4
- `cli-php83-mysql93` - PHP 8.3 with MySQL 9.3
- `cli-php84-mysql80` - PHP 8.4 with MySQL 8.0
- `cli-php84-mysql84` - PHP 8.4 with MySQL 8.4
- `cli-php84-mysql93` - PHP 8.4 with MySQL 9.3
- `cli-php83-postgres16` - PHP 8.3 with PostgreSQL 16
- `cli-php84-postgres16` - PHP 8.4 with PostgreSQL 16

### Database Services
- `db-mysql80` - MySQL 8.0 (accessible on localhost:3306)
- `db-mysql84` - MySQL 8.4 (accessible on localhost:3307)
- `db-mysql93` - MySQL 9.3 (accessible on localhost:3308)
- `db-postgres16` - PostgreSQL 16 (accessible on localhost:5432)

### PHPMyAdmin Services
- `phpmyadmin-mysql80` - PHPMyAdmin for MySQL 8.0 (http://localhost:8080)
- `phpmyadmin-mysql84` - PHPMyAdmin for MySQL 8.4 (http://localhost:8081)
- `phpmyadmin-mysql93` - PHPMyAdmin for MySQL 9.3 (http://localhost:8082)

## Usage Examples

### Start specific database and PHPMyAdmin
```bash
# Start MySQL 8.0 with PHPMyAdmin
docker-compose up -d db-mysql80 phpmyadmin-mysql80

# Start MySQL 8.4 with PHPMyAdmin
docker-compose up -d db-mysql84 phpmyadmin-mysql84

# Start MySQL 9.3 with PHPMyAdmin
docker-compose up -d db-mysql93 phpmyadmin-mysql93
```

### Run DBDiff with specific PHP/MySQL combination
```bash
# PHP 8.3 with MySQL 8.0
docker-compose run --rm cli-php83-mysql80 server1.php server2.php database1 database2

# PHP 8.4 with MySQL 8.4
docker-compose run --rm cli-php84-mysql84 server1.php server2.php database1 database2

# PHP 8.4 with MySQL 9.3
docker-compose run --rm cli-php84-mysql93 server1.php server2.php database1 database2
```

### Run DBDiff with PostgreSQL
```bash
# Start the PostgreSQL service
docker-compose up -d db-postgres16

# Run a Postgres diff with PHP 8.3
docker-compose run --rm cli-php83-postgres16 \
  bash -c "./dbdiff --driver=pgsql \
  --server1=dbdiff:dbdiff@db-postgres16:5432 \
  server1.mydb_staging:server1.mydb_production"
```

### Run PHPUnit tests with specific combination
```bash
# Run tests with PHP 8.3 and MySQL 8.0
docker-compose run --rm cli-php83-mysql80 phpunit

# Run tests with PHP 8.4 and MySQL 8.4
docker-compose run --rm cli-php84-mysql84 phpunit

# Run PostgreSQL end-to-end tests (record baseline on first run)
docker-compose run --rm \
  -e DB_HOST_POSTGRES=db-postgres16 \
  cli-php83-postgres16 \
  bash -c "DBDIFF_RECORD_MODE=true scripts/run-tests.sh --postgres db-postgres16"

# Run PostgreSQL end-to-end tests (compare against baseline)
docker-compose run --rm \
  -e DB_HOST_POSTGRES=db-postgres16 \
  cli-php83-postgres16 \
  bash -c "scripts/run-tests.sh --postgres db-postgres16"

# Run SQLite end-to-end tests (no extra service needed)
docker-compose run --rm cli-php83-mysql80 bash -c "scripts/run-tests.sh --sqlite"
```

### Using the Start Script

The `start.sh` script provides an easy way to test different PHP/MySQL combinations with automatic cleanup to save disk space.

```bash
# Test specific PHP/MySQL combination
./start.sh 8.3 8.0

# Test all combinations (sequentially)
./start.sh all all

# HIGH PERFORMANCE: Test all combinations in parallel
./start.sh all all --parallel

# RECORD MODE: Update expected test fixtures for all versions
./start.sh all all --record

# FAST RESTART: Skip heavy Docker cleanup for faster iterations
./start.sh 8.3 8.0 --fast

# Watch mode - single combination
./start.sh 8.3 8.0 --watch

# Interactive mode - select from menus
./start.sh
```

**Advanced Flags:**

- **Parallel Mode (`--parallel`)**: Spawns multiple background processes to test different MySQL major versions concurrently. This provides a ~3x speedup when running `all all`.
- **Fast Mode (`--fast`)**: Skips the aggressive deletion of Docker images and build caches between runs. Use this when iterating on code and the underlying environment/dependencies haven't changed.
- **Record Mode (`--record`)**: Tunnels the `DBDIFF_RECORD_MODE` environment variable into the test containers. This will cause tests to overwrite the expected fixtures in `tests/expected/` with the actual current output.
- **Watch Mode (`--watch`)**: 
  - Single combination: Tests run once, then containers stay active for manual use.
  - Multiple combinations: Each combination runs in sequence, press Ctrl+C to move to the next.
- **No Teardown (`--no-teardown`)**: Containers remain active after script completion (works with both single and watch modes).

**Test Runner Modes:**

- **Single Run**: Tests run once, then containers are cleaned up automatically
- **Watch Mode (`--watch`)**: 
  - Single combination: Tests run once, then containers stay active for manual use
  - Multiple combinations: Each combination runs sequentially, press Ctrl+C to move to next
- **No Teardown (`--no-teardown`)**: Containers remain active after script completion (works with both single and watch modes)

**"All" Option Behavior:**
- **Single Mode**: Each combination runs and cleans up sequentially (unless --no-teardown)
- **Watch Mode**: Each combination runs in sequence, user must Ctrl+C to move to next combination
- **Watch + No Teardown**: Each combination stays active permanently (no cleanup between combinations or at exit)

**Note:** The start script automatically cleans up Docker containers, images, volumes, and build cache after each test to save disk space (unless --no-teardown is used).

**Stop Script:**
Use `./stop.sh` to manually clean up all containers and resources, especially useful after using `--no-teardown`:

```bash
# Normal cleanup
./stop.sh

# Aggressive cleanup (removes everything)
./stop.sh --aggressive

# Show help
./stop.sh --help
```

### Start all services
```bash
# Start all databases and PHPMyAdmin instances
docker-compose up -d

# Or start specific combination
docker-compose up -d db-mysql80 phpmyadmin-mysql80 cli-php83-mysql80
```

### Cleanup
```bash
# Stop all services
docker-compose down

# Remove all containers and images
docker-compose down --rmi all --volumes --remove-orphans
```

## Database Connection Details

Default MySQL credentials (configurable via `.env`):
- **Root Password**: `rootpass` (DB_ROOT_PASSWORD)
- **Database**: `diff1` (DB_NAME)
- **User**: `dbdiff` (DB_USER) 
- **Password**: `dbdiff` (DB_PASSWORD)

### External Access Ports (configurable via .env)
- MySQL 8.0: `localhost:3306` (DB_PORT_MYSQL80)
- MySQL 8.4: `localhost:3307` (DB_PORT_MYSQL84)
- MySQL 9.3: `localhost:3308` (DB_PORT_MYSQL93)
- PostgreSQL 16: `localhost:5432` (DB_PORT_POSTGRES16)

### PHPMyAdmin Access (configurable via .env)
- MySQL 8.0: http://localhost:18080 (PHPMYADMIN_PORT_MYSQL80)
- MySQL 8.4: http://localhost:18081 (PHPMYADMIN_PORT_MYSQL84)
- MySQL 9.3: http://localhost:18082 (PHPMYADMIN_PORT_MYSQL93)

## Continuous Integration

The project uses GitHub Actions to ensure full compatibility across all supported versions on every pull request.

**Matrix Grid:**
- **PHP**: 8.3, 8.4, 8.5
- **MySQL**: 8.0, 8.4, 9.3, 9.6
- **PostgreSQL**: 16
- **SQLite**: bundled (runs in every MySQL CLI container via `pdo_sqlite`)

## Deterministic Testing

DBDiff is now built with **deterministic SQL generation**. This means:
1. Tables are processed in alphabetical order.
2. `ALTER` statements within a table (columns, keys, constraints) are sorted by item name.
3. Data operations (`INSERT`, `UPDATE`, `DELETE`) are sorted by primary key.

This ensures that test fixtures are stable across platforms and different database versions.

## Support for Modern PHP & PHPUnit

- Fully compatible with **PHP 8.4**.
- Test suite modernized for **PHPUnit 11**.
- Clean test output with automatic suppression of third-party legacy deprecations in the `vendor/` folder.
