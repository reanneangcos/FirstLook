FROM composer:2.10.3 AS composer
FROM php:8.5.8-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libonig-dev libsqlite3-dev libzip-dev \
    && docker-php-ext-install mbstring pdo_sqlite bcmath pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/triageflow-entrypoint
RUN chmod +x /usr/local/bin/triageflow-entrypoint
ENTRYPOINT ["triageflow-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
