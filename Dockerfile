ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# exif is required by spatie/image; pdo_mysql and pdo_pgsql run the test suite against MySQL, MariaDB and PostgreSQL
RUN docker-php-ext-install exif pdo_mysql pdo_pgsql

COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app
