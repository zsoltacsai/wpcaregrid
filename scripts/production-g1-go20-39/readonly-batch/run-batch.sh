#!/bin/bash
# Local runner: ONE ssh session over the existing ControlMaster; refuses to run without it (no agent-less ssh, no fallback).
set -u
CP=${CP:-/home/zsolt/.ssh/wpcg-go2039.sock}; H=deploy@stg1.wpcaregrid.com
D=$(cd "$(dirname "$0")" && pwd); OUT=${1:-/tmp/wpcg-readonly-batch.out}
[ -S "$CP" ] || { echo "BATCH=STOP;CODE=controlmaster_socket_missing"; exit 2; }
ssh -o ControlPath="$CP" -O check "$H" >/dev/null 2>&1 || { echo "BATCH=STOP;CODE=controlmaster_not_alive"; exit 2; }
ssh -o ControlPath="$CP" -o ControlMaster=no -o BatchMode=yes "$H" 'bash -s' < "$D/remote-readonly.sh" > "$OUT" 2>&1; rc=$?
echo "BATCH=DONE;RC=$rc;OUT=$OUT"
