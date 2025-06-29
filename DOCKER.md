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
- `DB_PORT_MYSQL80`, `DB_PORT_MYSQL84`, `DB_PORT_MYSQL93` - Database ports
- `PHPMYADMIN_PORT_MYSQL80`, `PHPMYADMIN_PORT_MYSQL84`, `PHPMYADMIN_PORT_MYSQL93` - PHPMyAdmin ports  
- `DB_ROOT_PASSWORD`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` - Database credentials
- `DATABASE_STARTUP_TIMEOUT`, `PHPUNIT_TEST_TIMEOUT` - Timeout configurations

## Available Configurations

### PHP Versions
- PHP 7.4
- PHP 8.3
- PHP 8.4

### MySQL Versions
- MySQL 8.0 (port 3306)
- MySQL 8.4 (port 3307)
- MySQL 9.3 (port 3308)

## Services

### CLI Services (for running DBDiff)
- `cli-php74-mysql80` - PHP 7.4 with MySQL 8.0
- `cli-php74-mysql84` - PHP 7.4 with MySQL 8.4
- `cli-php74-mysql93` - PHP 7.4 with MySQL 9.3
- `cli-php83-mysql80` - PHP 8.3 with MySQL 8.0
- `cli-php83-mysql84` - PHP 8.3 with MySQL 8.4
- `cli-php83-mysql93` - PHP 8.3 with MySQL 9.3
- `cli-php84-mysql80` - PHP 8.4 with MySQL 8.0
- `cli-php84-mysql84` - PHP 8.4 with MySQL 8.4
- `cli-php84-mysql93` - PHP 8.4 with MySQL 9.3

### Database Services
- `db-mysql80` - MySQL 8.0 (accessible on localhost:3306)
- `db-mysql84` - MySQL 8.4 (accessible on localhost:3307)
- `db-mysql93` - MySQL 9.3 (accessible on localhost:3308)

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
# PHP 7.4 with MySQL 8.0
docker-compose run --rm cli-php74-mysql80 server1.php server2.php database1 database2

# PHP 8.3 with MySQL 8.0
docker-compose run --rm cli-php83-mysql80 server1.php server2.php database1 database2

# PHP 8.4 with MySQL 8.4
docker-compose run --rm cli-php84-mysql84 server1.php server2.php database1 database2

# PHP 8.4 with MySQL 9.3
docker-compose run --rm cli-php84-mysql93 server1.php server2.php database1 database2
```

### Run PHPUnit tests with specific combination
```bash
# Run tests with PHP 7.4 and MySQL 8.0
docker-compose run --rm cli-php74-mysql80 phpunit

# Run tests with PHP 8.3 and MySQL 8.0
docker-compose run --rm cli-php83-mysql80 phpunit

# Run tests with PHP 8.4 and MySQL 8.4
docker-compose run --rm cli-php84-mysql84 phpunit
```

### Using the Start Script

The `start.sh` script provides an easy way to test different PHP/MySQL combinations with automatic cleanup to save disk space.

```bash
# Test specific PHP/MySQL combination
./start.sh 8.3 8.0

# Test all PHP versions with specific MySQL version
./start.sh all 8.0

# Test specific PHP version with all MySQL versions
./start.sh 8.3 all

# Test all combinations (requires lots of disk space)
./start.sh all all

# Watch mode - single combination
./start.sh 8.3 8.0 --watch

# Watch mode - multiple combinations (run each sequentially)
./start.sh all 8.0 --watch

# No teardown mode (keep containers after tests complete)
./start.sh 8.3 8.0 --no-teardown

# Watch mode with no teardown (keep all combinations active)
./start.sh all 8.0 --watch --no-teardown

# Interactive mode - select from menus
sudo ./start.sh

# Show help
./start.sh --help
```

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

### PHPMyAdmin Access (configurable via .env)
- MySQL 8.0: http://localhost:18080 (PHPMYADMIN_PORT_MYSQL80)
- MySQL 8.4: http://localhost:18081 (PHPMYADMIN_PORT_MYSQL84)
- MySQL 9.3: http://localhost:18082 (PHPMYADMIN_PORT_MYSQL93)

## Building Custom Combinations

The Dockerfile accepts a `PHP_VERSION` build argument, so you can easily add more PHP versions by updating the docker-compose.yml file with additional services using different PHP_VERSION values.

Example:
```yaml
cli-php82-mysql80:
  depends_on:
    - db-mysql80
  build:
    context: .
    dockerfile: docker/Dockerfile
    args:
      PHP_VERSION: 8.2
  environment:
    - DB_HOST=db-mysql80
```
