#!/bin/sh
set -eu

# Installed root-owned as an OpenSSH authorized_keys forced command.
if [ "${SSH_ORIGINAL_COMMAND-}" != 'm12-config-readback' ]; then
    printf '%s\n' 'DIAGNOSTIC_STATUS=REJECTED'
    exit 64
fi

ulimit -c 0
exec /usr/bin/sudo -n -- /usr/bin/php /usr/local/libexec/wpcaregrid-staging-m12-readback.php
