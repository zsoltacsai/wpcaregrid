#!/bin/sh
set -eu

case "${SSH_ORIGINAL_COMMAND-}" in
    preflight) phase=preflight ;;
    continue-migrations) phase=migrate ;;
    finalize) phase=finalize ;;
    *) printf '%s\n' '{"status":"STOP","code":"operator_command_invalid"}'; exit 64 ;;
esac

exec /usr/bin/sudo -n /usr/local/sbin/wpcaregrid-staging-recovery-runner "$phase"
