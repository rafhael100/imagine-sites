# F1-373: independent Sites Laravel staging image; no deployment.
FROM php:8.3-fpm-bookworm AS php-build
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libonig-dev libzip-dev unzip git; \
    docker-php-ext-install -j"$(nproc)" mbstring pdo_pgsql zip; \
    rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/imagine/apps/sites
COPY apps/sites/composer.json apps/sites/composer.lock ./
COPY apps/sites/app ./app
COPY apps/sites/bootstrap ./bootstrap
COPY apps/sites/config ./config
COPY apps/sites/public ./public
COPY apps/sites/routes ./routes
COPY apps/sites/artisan ./artisan
COPY src /opt/imagine/src
RUN composer install --no-dev --no-interaction --no-ansi --no-progress \
    --prefer-dist --no-scripts --optimize-autoloader && \
    composer check-platform-reqs --no-dev

FROM php:8.3-fpm-bookworm AS sites-php
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq5 libonig5 libzip4; \
    rm -rf /var/lib/apt/lists/*
COPY --from=php-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=php-build --chown=www-data:www-data /opt/imagine/apps/sites /opt/imagine/apps/sites
COPY --from=php-build --chown=www-data:www-data /opt/imagine/src /opt/imagine/src
COPY ops/homologation/php.ini /usr/local/etc/php/conf.d/99-sites-homologation.ini
COPY ops/homologation/php-fpm-pool.conf /usr/local/etc/php-fpm.d/99-sites-env.conf
WORKDIR /opt/imagine/apps/sites
RUN set -eux; \
    mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM nginx:stable-alpine AS sites-proxy
COPY ops/homologation/nginx.conf /etc/nginx/conf.d/default.conf
COPY apps/sites/public /opt/imagine/apps/sites/public
EXPOSE 8080
USER nginx
CMD ["nginx", "-g", "daemon off;"]
