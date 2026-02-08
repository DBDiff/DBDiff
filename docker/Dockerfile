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
COPY --chown=dbdiff:dbdiff composer.json ./

# Install dependencies (without dev for production, or with dev for tests)
# Note: In a production image you would use --no-dev
RUN composer install --no-interaction --no-progress --optimize-autoloader

# Copy the rest of the application files
# The .dockerignore handles the exclusion of sensitive/unnecessary files
COPY --chown=dbdiff:dbdiff . .

ENTRYPOINT ["./docker/docker-entrypoint.sh"]
