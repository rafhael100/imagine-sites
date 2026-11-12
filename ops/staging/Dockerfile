# F1-73: disposable test-only image. Not a production web server.
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring dom xml zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /workspace
# Explicit copy list: no .git, runtime secrets, or public web configuration.
COPY apps/sites/ ./apps/sites/
COPY src/ ./src/
COPY fixtures/ ./fixtures/
COPY labs/ ./labs/
COPY contracts/ ./contracts/

# Composer's package:discover script may write bootstrap/cache. Create these
# directories BEFORE installing dependencies; an empty directory is omitted
# by the build-context ignore rules.
# The runtime image creates an EMPTY lab-only .env; never copies one from the host.
RUN groupadd --gid 10001 siteslab \
    && useradd --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin siteslab \
    && mkdir -p apps/sites/storage/framework/cache/data \
        apps/sites/storage/framework/views apps/sites/storage/framework/sessions \
        apps/sites/storage/logs apps/sites/bootstrap/cache \
    && : > apps/sites/.env \
    && chmod 0600 apps/sites/.env \
    && cd apps/sites \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins \
    && chown -R 10001:10001 storage bootstrap/cache \
    && chown 10001:10001 .env

USER 10001:10001
WORKDIR /workspace/apps/sites
ENV APP_ENV=testing APP_DEBUG=false
# Laravel package discovery runs as the unprivileged CI runtime user,
# not as Composer's build-time root. No automatic package scripts/plugins.
RUN php artisan package:discover --ansi
# Nothing listens on HTTP: one-off test process exits on completion.
CMD ["php", "artisan", "test", "--no-ansi"]
