#!/bin/sh
#
# Container entrypoint.
#
# Laravel's caches are built here rather than in the Dockerfile because
# config:cache freezes the values of APP_KEY, DB_* and every other environment
# variable at the moment it runs. Baking that into the image would either ship
# build-time placeholders or, worse, real secrets in a layer.
#
# No migrations run here. Several tasks may start concurrently behind a load
# balancer, and schema changes belong in a separate one-off job rather than in
# whichever container happens to boot first.

set -e

cd /var/www/html

# A mounted volume can shadow the directories created at build time.
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    /tmp/nginx

# Stale caches from a previous boot would otherwise survive a config change.
php artisan config:clear >/dev/null 2>&1 || true

# Refuse to start without an application key rather than booting into a broken
# state. Without APP_KEY every encrypted payload fails: sessions cannot be read,
# so nobody can log in, and signed URLs cannot be verified. Those failures
# surface per-request, well after the container has reported itself healthy, so
# the cheaper place to catch it is here.
if [ -z "${APP_KEY:-}" ]; then
    echo "entrypoint: FATAL - APP_KEY is not set." >&2
    echo "entrypoint: The application cannot encrypt or decrypt session and cookie" >&2
    echo "entrypoint: payloads without it, so the container will not start." >&2
    echo "entrypoint: Generate one with 'php artisan key:generate --show' and supply" >&2
    echo "entrypoint: it to the container as the APP_KEY environment variable." >&2
    exit 1
fi

# Refuse to serve production traffic with debug mode on. Laravel's debug error
# page renders the stack trace along with the environment behind it, which
# includes APP_KEY and the database credentials, to anyone who can provoke an
# exception. Unlike the key check this cannot be caught by the application
# failing to work, because everything works exactly as normal until the first
# error.
if [ "${APP_ENV:-}" = "production" ] && [ "${APP_DEBUG:-}" = "true" ]; then
    echo "entrypoint: FATAL - APP_DEBUG is true while APP_ENV is production." >&2
    echo "entrypoint: Debug mode exposes stack traces containing APP_KEY and the" >&2
    echo "entrypoint: database credentials to anyone who triggers an error, so the" >&2
    echo "entrypoint: container will not start." >&2
    echo "entrypoint: Set APP_DEBUG=false for production deployments." >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
