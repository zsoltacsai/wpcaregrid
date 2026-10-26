#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 077
export PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
unset BASH_ENV ENV CDPATH PHPRC PHP_INI_SCAN_DIR

readonly PROFILE_LINK='/etc/nginx/snippets/wpcaregrid-staging-runtime.conf'
readonly PROFILE_DIR='/etc/nginx/snippets'
readonly APP_ARTISAN='/var/www/wpcaregrid/artisan'

fail() {
    printf '%s\n' 'wpcaregrid PHP runtime refused: profile unavailable or invalid' >&2
    exit 78
}

blocked() {
    local marker="$1"
    if [[ -e "$marker" || -L "$marker" ]]; then
        [[ -f "$marker" && ! -L "$marker" && "$(stat -c '%u:%a' "$marker")" == '0:644' ]] || fail
        exit 75
    fi
}

for directory in /etc /etc/nginx "$PROFILE_DIR" /usr /usr/bin; do
    [[ -d "$directory" && ! -L "$directory" ]] || fail
    [[ "$(stat -c '%u' "$directory")" == '0' ]] || fail
    mode="$(stat -c '%a' "$directory")"
    [[ "$mode" =~ ^[0-7]{3,4}$ ]] && (( (8#$mode & 022) == 0 )) || fail
done
[[ -L "$PROFILE_LINK" && "$(stat -c '%u' "$PROFILE_LINK")" == '0' ]] || fail
profile_path="$(readlink -- "$PROFILE_LINK")" || fail
[[ "$(dirname -- "$profile_path")" == "$PROFILE_DIR" ]] || fail
[[ -f "$profile_path" && ! -L "$profile_path" ]] || fail
[[ "$(stat -c '%u:%a' "$profile_path")" == '0:644' ]] || fail

case "$profile_path" in
    "$PROFILE_DIR/wpcaregrid-staging-php84.conf")
        [[ "$(cat -- "$profile_path")" == 'fastcgi_pass unix:/run/php/php8.4-fpm.sock;' ]] || fail
        readonly PHP_BIN='/usr/bin/php8.4'
        readonly EXPECTED_MINOR=4
        ;;
    "$PROFILE_DIR/wpcaregrid-staging-php85.conf")
        [[ "$(cat -- "$profile_path")" == 'fastcgi_pass unix:/run/php/php8.5-fpm.sock;' ]] || fail
        readonly PHP_BIN='/usr/bin/php8.5'
        readonly EXPECTED_MINOR=5
        ;;
    *)
        fail
        ;;
esac
[[ -x "$PHP_BIN" && ! -L "$PHP_BIN" ]] || fail
[[ "$(stat -c '%u' "$PHP_BIN")" == '0' ]] || fail
mode="$(stat -c '%a' "$PHP_BIN")"
[[ "$mode" =~ ^[0-7]{3,4}$ ]] && (( (8#$mode & 022) == 0 )) || fail
[[ "$("$PHP_BIN" -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')" == "8.${EXPECTED_MINOR}" ]] || fail
[[ "${1:-}" == "$APP_ARTISAN" ]] || fail
case "${2:-}" in
    queue:work) blocked /var/lib/wpcaregrid/workers-disabled ;;
    schedule:run) blocked /var/lib/wpcaregrid/scheduler-paused ;;
esac
shift
exec "$PHP_BIN" "$APP_ARTISAN" "$@"
