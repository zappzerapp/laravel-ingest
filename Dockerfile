FROM composer:latest as composer

FROM php:8.3-cli

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    zip \
    libzip-dev \
    libsqlite3-dev \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-install pdo_sqlite zip ftp \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

RUN chown -R nobody:nobody /var/www/html

CMD ["tail", "-f", "/dev/null"]
USER nobody