#!/usr/bin/env bash
set -euo pipefail
echo "Running composer install..."
composer install --no-dev --working-dir=/var/www/html --optimize-autoloader
echo "Caching config / routes / views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
  SQLITE_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
  echo "SQLite mode: ensuring $SQLITE_PATH exists and is writable..."
  mkdir -p "$(dirname "$SQLITE_PATH")"
  touch "$SQLITE_PATH"
  chown -R www-data:www-data "$(dirname "$SQLITE_PATH")"
  echo "Running migrations against SQLite..."
else
  echo "Running migrations against Neon..."
fi
php artisan migrate --force
echo "Deploy script complete."
