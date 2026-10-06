#!/usr/bin/env bash

set -Eeuo pipefail

readonly APP_DIR='/var/www/wpcaregrid'
readonly CONFIG_CACHE="${APP_DIR}/bootstrap/cache/config.php"
readonly LOCK_FILE='/var/lib/wpcaregrid/scheduler.lock'
readonly PAUSE_FILE='/var/lib/wpcaregrid/scheduler-paused'

report() {
    local priority=$1
    local message=$2

    printf 'wpcaregrid scheduler %s\n' "$message" >&2
    logger -t wpcaregrid-scheduler -p "daemon.${priority}" -- "$message"
}

if [[ ! -f "$LOCK_FILE" || -L "$LOCK_FILE" ]]; then
    report err 'refusing to start: scheduler lock is missing or unsafe'
    exit 1
fi

exec 9<"$LOCK_FILE" || {
    report err 'refusing to start: scheduler lock cannot be opened'
    exit 1
}

if ! flock -s -n 9; then
    report notice 'skipped: deployment guard active'
    exit 0
fi

if [[ -e "$PAUSE_FILE" ]]; then
    report notice 'skipped: persistent deployment pause marker is active'
    exit 0
fi

if [[ ! -r "$CONFIG_CACHE" ]]; then
    report err 'refusing Laravel bootstrap: production config cache is missing or unreadable'
    exit 1
fi

cd "$APP_DIR"
exec php artisan schedule:run
