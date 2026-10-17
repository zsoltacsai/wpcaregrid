#!/usr/bin/env bash

# Staging-only harmless target-code fixture. Run as deploy after the narrow
# sudoers cutover; this attempts only to print the effective user identity.
set -Eeuo pipefail

if sudo -n /usr/bin/id >/dev/null 2>&1; then
    printf 'Unexpected root command access remains available.\n' >&2
    exit 1
fi

printf 'Arbitrary sudo was denied as expected.\n'
