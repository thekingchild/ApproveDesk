#!/bin/sh
set -eu

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ ! -e public/storage ]; then
    ln -s ../storage/app/public public/storage
fi

if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"

