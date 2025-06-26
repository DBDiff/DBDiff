# Docker Configuration for DBDiff

This Docker setup provides multiple PHP and MySQL version combinations for testing DBDiff across different environments.

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

### Using the Test Runner Script

The `test-runner.sh` script provides an easy way to test different PHP/MySQL combinations with automatic cleanup to save disk space.

```bash
# Test specific PHP/MySQL combination
sudo ./test-runner.sh 8.3 mysql80

# Test all PHP versions with specific MySQL version
sudo ./test-runner.sh all mysql80

# Test specific PHP version with all MySQL versions
sudo ./test-runner.sh 8.3 all

# Test all combinations (requires lots of disk space)
sudo ./test-runner.sh all all

# Interactive mode - select from menus
sudo ./test-runner.sh

# Show help
./test-runner.sh --help
```

**Note:** The test runner automatically cleans up Docker containers, images, volumes, and build cache after each test to save disk space.

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

All MySQL instances use the same credentials:
- **Root Password**: `rootpass`
- **Database**: `diff1`
- **User**: `dbdiff`
- **Password**: `dbdiff`

### External Access Ports
- MySQL 8.0: `localhost:3306`
- MySQL 8.4: `localhost:3307` 
- MySQL 9.3: `localhost:3308`

### PHPMyAdmin Access
- MySQL 8.0: http://localhost:8080
- MySQL 8.4: http://localhost:8081
- MySQL 9.3: http://localhost:8082

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
