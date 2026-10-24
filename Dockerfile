FROM php:8.3-apache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates unzip git \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
COPY api/ /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/render-entrypoint
RUN chmod +x /usr/local/bin/render-entrypoint

EXPOSE 10000
CMD ["render-entrypoint"]
