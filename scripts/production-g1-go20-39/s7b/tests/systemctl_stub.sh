#!/usr/bin/env bash
# systemctl stub for the sandbox (pure bash, no forks on the hot path): only `show` (property filter) and `stop --no-block`
SB="$S7B_SANDBOX"
case "$1" in
  show)
    shift; props=" "; unit=""
    while [ $# -gt 0 ]; do case "$1" in -p) props+="$2 "; shift 2 ;; --) unit="$2"; shift 2 ;; *) shift ;; esac; done
    echo "show $unit" >> "$SB/calls.log"
    f="$SB/state/legacy.show"; case "$unit" in mysql@*) f="$SB/state/g1.show" ;; esac
    if [ -f "$SB/show_fail" ]; then read -r sf < "$SB/show_fail"; [ "$sf" = "$unit" ] && exit 1; fi
    if [ -f "$SB/show_garbage" ]; then echo "ActiveState=weird value!"; exit 0; fi
    if [ -f "$SB/fail_after" ]; then read -r fu fn < "$SB/fail_after"; if [ "$fu" = "$unit" ]; then c=0; [ -f "$SB/cnt" ] && read -r c < "$SB/cnt"; c=$((c+1)); echo "$c" > "$SB/cnt"; [ "$c" -gt "$fn" ] && exit 1; fi; fi
    om=""; [ -f "$SB/show_missing" ] && read -r om < "$SB/show_missing"
    while IFS='=' read -r k v; do [ "$k" = "$om" ] && continue; case "$props" in *" $k "*) echo "$k=$v" ;; esac; done < "$f"
    [ -f "$SB/show_dup" ] && echo "ActiveState=inactive"
    exit 0 ;;
  stop)
    echo "stop-args: $*" >> "$SB/calls.log"
    [ -f "$SB/stop_fail" ] && exit 1
    python3 -S -E "$SB/sim.py" stop; exit 0 ;;
  *) echo "FORBIDDEN systemctl $*" >> "$SB/calls.log"; exit 9 ;;
esac
