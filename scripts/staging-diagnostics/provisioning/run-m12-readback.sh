#!/bin/sh
set -eu

# sudoers pins this executable with an empty argument list. Keep the check here
# as defense in depth before starting the fixed PHP helper.
if [ "$#" -ne 0 ]; then
    exit 64
fi

exec /usr/bin/php /usr/local/libexec/wpcaregrid-staging-m12-readback.php
