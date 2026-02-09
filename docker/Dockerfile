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
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN chmod 755 /usr/local/bin/composer

# Create a non-root user for execution
RUN useradd -m -s /bin/bash dbdiff

# Prepare application directory
RUN mkdir -p /usr/src/dbdiff && chown root:root /usr/src/dbdiff

# Switch BACK to root for copying files (to ensure they are owned by root)
# We will drop privileges only at runtime or when strictly necessary
USER root

# Copy composer.json as root:root with read-only permissions for others
# Docker Cache Layer 1
COPY --chown=root:root --chmod=644 composer.json ./

# Copy scripts early so Composer post-install hooks can run during install
COPY --chown=root:root --chmod=755 scripts/ scripts/

# Install dependencies as root (standard practice in many Dockerfiles)
# We are still building the image here
RUN composer install --no-interaction --no-progress --optimize-autoloader

# Explicitly copy source code and assets
# Enforce root ownership and strict read-only permissions for non-root users
# 755 = rwxr-xr-x (Owner: RWX, Group: RX, Others: RX)
# This allows 'dbdiff' user to read/execute but NOT modify
COPY --chown=root:root --chmod=755 src/ src/
COPY --chown=root:root --chmod=755 templates/ templates/
COPY --chown=root:root --chmod=755 docker/ docker/

# Copy individual files
COPY --chown=root:root --chmod=755 dbdiff ./
COPY --chown=root:root --chmod=755 dbdiff.php ./
COPY --chown=root:root --chmod=755 start.sh ./
COPY --chown=root:root --chmod=755 stop.sh ./
COPY --chown=root:root --chmod=644 README.md LICENSE SECURITY.md ./

# Copy tests
COPY --chown=root:root --chmod=755 tests/ tests/

# CRITICAL: We need a writable directory for any runtime artifacts if the app creates them.
# If DBDiff writes to .phpunit.cache or similar, we must create that folder and give ownership to dbdiff.
RUN mkdir -p .phpunit.cache && chown dbdiff:dbdiff .phpunit.cache

# Final switch to non-root user for runtime execution
USER dbdiff

ENTRYPOINT ["./docker/docker-entrypoint.sh"]
