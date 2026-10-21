#!/bin/sh
set -eu

# OpenSSH invokes the account shell with -c. Requiring the forced dispatcher
# here also blocks shell access through a key or authentication method that did
# not carry the expected authorized_keys command option.
if [ "$#" -ne 2 ] \
    || [ "$1" != '-c' ] \
    || [ "$2" != '/usr/local/libexec/wpcaregrid-staging-diagnostics-dispatch' ] \
    || [ "${SSH_ORIGINAL_COMMAND-}" != 'm12-config-readback' ]; then
    exit 64
fi

exec /usr/local/libexec/wpcaregrid-staging-diagnostics-dispatch
