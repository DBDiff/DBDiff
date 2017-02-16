FROM php:7-cli

WORKDIR /usr/src/dbdiff

RUN apt-get update && \
    apt-get install -y git-core unzip && \
    rm -rf /var/lib/apt/lists/*

ADD https://getcomposer.org/composer.phar /usr/local/bin/composer

RUN chmod +x /usr/local/bin/composer

COPY . ./

RUN composer install

ENTRYPOINT ["./dbdiff"]
