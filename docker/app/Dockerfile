# syntax=docker/dockerfile:1

# WorkFlowHub production image.
#
# One container running PHP-FPM and Nginx under supervisord. Built in three
# stages so that neither Composer nor Node ships in the runtime layer.

# -----------------------------------------------------------------------------
# Stage 1 — PHP dependencies
# -----------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Manifests first so the dependency layer survives application code changes.
# Scripts and autoloader are deferred because package:discover needs the full
# source tree, which has not been copied yet.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY . .

RUN composer dump-autoload \
        --no-dev \
        --no-interaction \
        --optimize \
        --classmap-authoritative

# -----------------------------------------------------------------------------
# Stage 2 — Front-end assets
# -----------------------------------------------------------------------------
# Debian rather than Alpine: package.json pins the linux-x64-gnu builds of
# rollup, lightningcss and the Tailwind oxide binary, which are glibc artefacts
# and will not run against musl.
FROM node:24-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./

RUN npm ci --no-audit --no-fund

# Tailwind v4 scans Flux's stubs through an @source directive in app.css, so the
# vendor tree has to be present before the build runs.
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources

# Tailwind v4's automatic source detection skips whatever git ignores. Without
# this file it walks vendor/ as well, producing a different stylesheet here than
# the one built and reviewed on a developer machine.
COPY .gitignore ./

RUN npm run build

# -----------------------------------------------------------------------------
# Stage 3 — Runtime
# -----------------------------------------------------------------------------
# php:8.5-fpm-alpine resolves to PHP 8.5.9 on Alpine 3.24, which carries nginx
# and supervisor in its repositories. Every extension Composer requires is
# already enabled in this base image; only the PostgreSQL driver is missing.
FROM php:8.5-fpm-alpine AS runtime

# libpq is a runtime dependency, not a build one: pdo_pgsql links against
# libpq.so.5 and fails to load without it. postgresql-dev pulls libpq in as a
# transitive dependency, so removing the build deps would take it back out
# again unless it is requested in its own right.
RUN set -eux; \
    apk add --no-cache \
        nginx \
        supervisor \
        libpq; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    apk del --no-network .build-deps; \
    rm -rf /var/cache/apk/*; \
    php -m | grep -q pdo_pgsql

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-workflowhub.ini
COPY docker/app/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-workflowhub.conf
COPY docker/app/nginx.conf /etc/nginx/nginx.conf
COPY docker/app/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/app/exit-on-fatal.py /usr/local/bin/exit-on-fatal
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# Everything the unprivileged runtime user writes to has to exist and be owned
# before the drop to www-data: Laravel's caches, plus nginx and supervisor
# scratch space, which normally live under root-owned paths.
RUN set -eux; \
    chmod +x /usr/local/bin/entrypoint /usr/local/bin/exit-on-fatal; \
    rm -rf docker; \
    mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /tmp/nginx \
        /var/lib/nginx/logs; \
    chown -R www-data:www-data \
        storage \
        bootstrap/cache \
        /tmp/nginx \
        /var/lib/nginx; \
    chmod -R u+rwX storage bootstrap/cache

USER www-data

# Unprivileged, so above 1024. Terminates TLS upstream in any real deployment.
EXPOSE 8080

# Laravel's built-in health endpoint, enabled via `health: '/up'` in
# bootstrap/app.php. cgi-fcgi is deliberately not used here: this checks the
# whole request path, nginx included, rather than just PHP-FPM.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") !== false ? 0 : 1);'

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "--configuration", "/etc/supervisor/supervisord.conf"]
