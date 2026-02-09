ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# Set working directory
WORKDIR /usr/src/dbdiff

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git-core \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Add MySQL PDO extension
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Add Composer
ADD https://getcomposer.org/composer.phar /usr/local/bin/composer
RUN chmod +x /usr/local/bin/composer

# Create a non-root user for security (Sonar hotspot fix)
RUN useradd -m -s /bin/bash dbdiff && \
    chown -R dbdiff:dbdiff /usr/src/dbdiff

# Switch to the non-root user
USER dbdiff

# Copy only the essentials first to leverage Docker cache
# Ensure strict permissions: read-only for everyone (444)
COPY --chown=dbdiff:dbdiff --chmod=444 composer.json ./

# Install dependencies
RUN composer install --no-interaction --no-progress --optimize-autoloader

# Explicitly copy source code and assets to avoid insensitive data inclusion (S6470)
# And enforce restrictive permissions (read-only/executable 555 for dirs/scripts)
COPY --chown=dbdiff:dbdiff --chmod=555 src/ src/
COPY --chown=dbdiff:dbdiff --chmod=555 templates/ templates/
COPY --chown=dbdiff:dbdiff --chmod=555 scripts/ scripts/
COPY --chown=dbdiff:dbdiff --chmod=555 docker/ docker/

# Copy individual files (chmod 555 for executables, 444 for regular files)
COPY --chown=dbdiff:dbdiff --chmod=555 dbdiff ./
COPY --chown=dbdiff:dbdiff --chmod=555 dbdiff.php ./
COPY --chown=dbdiff:dbdiff --chmod=555 start.sh ./
COPY --chown=dbdiff:dbdiff --chmod=555 stop.sh ./
COPY --chown=dbdiff:dbdiff --chmod=444 README.md LICENSE SECURITY.md ./

# Copy tests last (if needed for the image to run tests)
COPY --chown=dbdiff:dbdiff --chmod=555 tests/ tests/

ENTRYPOINT ["./docker/docker-entrypoint.sh"]
