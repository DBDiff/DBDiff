FROM php:7.3-cli

WORKDIR /usr/src/dbdiff

RUN apt-get update && apt-get install -y git-core
# For speed / optimisation
RUN apt-get install -y unzip && rm -rf /var/lib/apt/lists/*
# Add MySQL PDO extension
RUN docker-php-ext-install mysqli pdo pdo_mysql
# Add Composer
ADD https://getcomposer.org/composer.phar /usr/local/bin/composer

RUN chmod +x /usr/local/bin/composer
# Copy all the root files into the container
COPY . ./
# Install dependencies with Composer
RUN composer install

ENTRYPOINT ["./docker/docker-entrypoint.sh"]