#!/bin/sh
set -eu

# Installed root-owned as an OpenSSH authorized_keys forced command.
reject() {
    printf '%s\n' 'DIAGNOSTIC_STATUS=REJECTED'
    exit 64
}

[ "${SSH_ORIGINAL_COMMAND-}" = 'm12-config-readback' ] || reject
/usr/local/libexec/wpcaregrid-staging-diagnostics-activation-check >/dev/null 2>&1 || reject

ulimit -c 0
exec /usr/bin/sudo -n -- /usr/local/sbin/wpcaregrid-staging-m12-readback
