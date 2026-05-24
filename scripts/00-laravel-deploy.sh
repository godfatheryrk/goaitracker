#!/usr/bin/env bash
set -euo pipefail
echo "Running composer install..."
composer install --no-dev --working-dir=/var/www/html --optimize-autoloader
echo "Caching config / routes / views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Running migrations against Neon..."
php artisan migrate --force
echo "Deploy script complete.
