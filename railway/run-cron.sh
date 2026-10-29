#!/usr/bin/env bash
# Start command for the Railway cron service (mailbox poll via schedule).
set -euo pipefail

exec php artisan schedule:work --verbose --no-interaction
