FROM composer:latest AS composer

FROM php:8.4-cli

ARG INSTALL_XDEBUG=true

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    zip \
    libzip-dev \
    libsqlite3-dev \
    && if [ "$INSTALL_XDEBUG" = "true" ]; then \
      pecl install xdebug && \
      docker-php-ext-enable xdebug; \
    fi \
    && docker-php-ext-install pdo_sqlite zip ftp \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

COPY --chown=nobody:nogroup . .

CMD ["tail", "-f", "/dev/null"]
USER nobody
