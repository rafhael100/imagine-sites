# F1-373: independent Sites Laravel staging image; no deployment.
FROM php:8.3-fpm-bookworm@sha256:d7677e2a3c1579fbe52848cc446bc24860187c876c383a43b7d4b8e06dce537e AS php-build
ARG SITES_BUILD_JOBS=2
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libonig-dev libzip-dev unzip git; \
    docker-php-ext-install -j"${SITES_BUILD_JOBS}" mbstring pdo_pgsql zip; \
    rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/imagine/apps/sites
COPY apps/sites/composer.json apps/sites/composer.lock ./
COPY apps/sites/app ./app
COPY apps/sites/bootstrap ./bootstrap
COPY apps/sites/config ./config
COPY apps/sites/public ./public
COPY apps/sites/routes ./routes
COPY apps/sites/artisan ./artisan
COPY apps/sites/database ./database
COPY ops/staging/provision-runtime.php ./provision-runtime.php
COPY ops/staging/verify-runtime.php ./verify-runtime.php
COPY ops/staging/verify-database-tls.php ./verify-database-tls.php
COPY src /opt/imagine/src
RUN composer install --no-dev --no-interaction --no-ansi --no-progress \
    --prefer-dist --no-scripts --optimize-autoloader && \
    composer check-platform-reqs --no-dev && \
    php artisan package:discover --ansi && \
    php artisan route:list --json > /tmp/f373-build-routes.json

FROM php:8.3-fpm-bookworm@sha256:d7677e2a3c1579fbe52848cc446bc24860187c876c383a43b7d4b8e06dce537e AS sites-php
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

FROM nginx:stable-alpine@sha256:901e593c95dc85f5a729f5765516321366ef4ac401821431d408897e95287d0a AS sites-proxy
COPY ops/staging/nginx-main.conf /etc/nginx/nginx.conf
COPY ops/staging/nginx.conf /etc/nginx/conf.d/default.conf
COPY ops/staging/index.html /opt/imagine/status/index.html
USER nginx
EXPOSE 8443 9443
CMD ["nginx", "-g", "daemon off;"]
