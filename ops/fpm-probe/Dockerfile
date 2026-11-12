# F1-237: ephemeral CI-only PHP-FPM image with synthetic routes CACHED.
# Never deploy to VPS, registry or Cloudflare. No production credentials.
FROM php:8.4-fpm-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring dom xml zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /workspace
COPY apps/sites/ ./apps/sites/
COPY src/ ./src/
COPY fixtures/ ./fixtures/
COPY labs/ ./labs/
COPY contracts/ ./contracts/
RUN groupadd --gid 10001 sitesfpm \
    && useradd --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin sitesfpm \
    && mkdir -p apps/sites/storage/framework/cache/data apps/sites/storage/framework/views \
        apps/sites/storage/framework/sessions apps/sites/storage/logs apps/sites/bootstrap/cache \
    && : > apps/sites/.env \
    && chmod 0600 apps/sites/.env \
    && cd apps/sites \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins \
    && chown -R 10001:10001 storage bootstrap/cache .env
# Build a real route cache with synthetic lab routes ON under PHP CLI,
# then run exactly that cache under a different PHP SAPI (fpm-fcgi).
WORKDIR /workspace/apps/sites
ENV APP_ENV=testing APP_DEBUG=false SITES_SYNTHETIC_HTTP=1 \
    SITES_SYNTHETIC_REVIEW_UI=1 SITES_SYNTHETIC_RICH_REVIEW=1 \
    SITES_F222_SEO_COPY_REVIEW=1
USER 10001:10001
RUN php artisan package:discover --ansi \
    && php artisan route:cache \
    && php artisan route:list --json | php -r '$routes=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); foreach($routes as $route){if(($route["uri"]??null)==="_lab/sites/v1/create-project")exit(0);} exit(1);'
# Base image listens only inside the Compose internal network on port 9000.
CMD ["php-fpm","-F"]
