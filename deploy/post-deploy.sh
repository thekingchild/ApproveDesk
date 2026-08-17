#!/usr/bin/env bash

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

if [[ ! -f artisan ]]; then
    echo "ApproveDesk artisan entrypoint not found in $APP_DIR" >&2
    exit 1
fi

if [[ ! -d vendor ]]; then
    composer install --prefer-dist --no-dev --optimize-autoloader --no-interaction --no-progress
fi

php artisan down --retry=60 || true

bring_up() {
    php artisan up || true
}

trap bring_up EXIT

php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

bring_up
trap - EXIT

echo "ApproveDesk deployed successfully at $(date -u +%FT%TZ)"
