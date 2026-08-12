# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM php:8.4-apache AS runtime

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache; \
    # libpq5 arrives as a dependency of the dev package; pdo_pgsql needs it at runtime,
    # so keep it from being swept up by --auto-remove.
    apt-mark manual libpq5; \
    apt-get purge -y --auto-remove libpq-dev; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -q pdo_pgsql

RUN a2enmod rewrite headers \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername
COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --from=vendor /app ./
RUN rm -rf var/cache var/log && mkdir -p var/cache var/log && chown -R www-data:www-data var

# Build the prod container with placeholder values; real ones arrive at runtime.
RUN APP_ENV=prod APP_DEBUG=0 \
    APP_SECRET=build-time-placeholder \
    ADMIN_TOKEN=build-time-placeholder-token \
    DATABASE_URL=postgresql://build:build@127.0.0.1:5432/build \
    php bin/console cache:warmup --env=prod \
    && chown -R www-data:www-data var

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    PORT=10000

EXPOSE 10000
ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
