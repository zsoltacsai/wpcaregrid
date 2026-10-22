#!/bin/sh
set -eu

STATE_DIR=/var/lib/wpcaregrid-staging-diagnostics
PENDING_FILE=$STATE_DIR/provisioning.pending
BOOTSTRAP_PENDING_FILE=$STATE_DIR/provisioning.bootstrap-pending
ACTIVE_FILE=$STATE_DIR/provisioning.active

fail() {
    printf '%s\n' 'DIAGNOSTIC_ACTIVATION=DENIED'
    exit 65
}

for parent in /var /var/lib; do
    [ ! -L "$parent" ] && [ -d "$parent" ] || fail
    parent_metadata=$(/usr/bin/stat -c '%u:%a:%F' -- "$parent" 2>/dev/null) || fail
    case "$parent_metadata" in 0:*:directory) ;; *) fail ;; esac
    parent_mode=$(/usr/bin/stat -c '%a' -- "$parent" 2>/dev/null) || fail
    case "$parent_mode" in *[!0-7]*) fail ;; esac
    [ $((0$parent_mode & 0022)) -eq 0 ] || fail
done
[ ! -L "$STATE_DIR" ] && [ -d "$STATE_DIR" ] || fail
[ "$(/usr/bin/stat -c '%u:%a:%F' -- "$STATE_DIR" 2>/dev/null)" = '0:711:directory' ] || fail
[ ! -e "$PENDING_FILE" ] && [ ! -L "$PENDING_FILE" ] || fail
[ ! -e "$BOOTSTRAP_PENDING_FILE" ] && [ ! -L "$BOOTSTRAP_PENDING_FILE" ] || fail
[ ! -L "$ACTIVE_FILE" ] && [ -f "$ACTIVE_FILE" ] || fail
[ "$(/usr/bin/stat -c '%u:%a:%F' -- "$ACTIVE_FILE" 2>/dev/null)" = '0:444:regular file' ] || fail
[ "$(/usr/bin/stat -c '%s' -- "$ACTIVE_FILE" 2>/dev/null)" = '48' ] || fail
IFS= read -r activation_line < "$ACTIVE_FILE" || fail
case "$activation_line" in
    active=*) activation_sha=${activation_line#active=} ;;
    *) fail ;;
esac
[ "${#activation_sha}" -eq 40 ] || fail
case "$activation_sha" in
    *[!0123456789abcdef]*) fail ;;
esac

exit 0
