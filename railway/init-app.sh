#!/usr/bin/env bash
# Pre-deploy for the Railway web service. Cron service must not run this.
set -euo pipefail

php artisan migrate --force
php artisan filament:assets
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache
