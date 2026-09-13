#!/usr/bin/env bash

set -Eeuo pipefail

MOSCOW_WS_DIR="${MOSCOW_WS_DIR:-/var/www/moscow-ws}"

if [[ ! -f "${MOSCOW_WS_DIR}/artisan" ]]; then
    echo "Laravel application not found at ${MOSCOW_WS_DIR}."
    echo "Set MOSCOW_WS_DIR to the deployed application directory and run again."
    exit 1
fi

cd "${MOSCOW_WS_DIR}"

if [[ ! -f .env ]]; then
    echo "Missing ${MOSCOW_WS_DIR}/.env. Copy .env.production.example and enter the production values first."
    exit 1
fi

if grep -Eq 'DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=SET_AS_DEPLOYMENT_SECRET|APP_KEY=GENERATE_ON_THE_SERVER' .env; then
    echo "Production placeholders remain in .env. Set the database password and APP_KEY first."
    exit 1
fi

git fetch origin main
git checkout main
git pull --ff-only origin main

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan config:clear

php artisan down --retry=60
trap 'php artisan up' EXIT

php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart

php artisan up
trap - EXIT
