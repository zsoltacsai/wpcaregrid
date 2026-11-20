#!/usr/bin/env bash
# WP CareGrid GO-20.39: controlled, reversible pause of the two timers that make the ratified T8 window unsatisfiable. Root console, Owner GO required.
#   t8-timers.sh status | pause | restore
# pause  : stops (never disables/masks/edits) phpsessionclean.timer and wpcg-staging-next-scheduler.timer, waits for their services to be inactive, verifies.
# restore: starts them again and verifies a next elapse exists. UnitFileState stays `enabled`, so a reboot restores them too.
# State marker: /run/wpcg-t8-paused (root-only, tmpfs) written by pause, so restore only undoes what pause did.
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C
unset BASH_ENV ENV CDPATH IFS 2>/dev/null
set -u; set -o noclobber
ROOT_UID_EXPECT="0"
STATE_FILE="/run/wpcg-t8-paused"
TIMERS="phpsessionclean.timer wpcg-staging-next-scheduler.timer"
SERVICES="phpsessionclean.service wpcg-staging-next-scheduler.service"
SERVICE_WAIT_SEC=60
stop() { echo "T8_TIMERS=STOP;CODE=$1"; exit 1; }
show() { timeout 10 systemctl show -p "$1" --value -- "$2" 2>/dev/null; }

status() {
  local t; for t in $TIMERS; do echo "T8_TIMER name=$t active=$(show ActiveState "$t") enabled=$(show UnitFileState "$t") next=$(show NextElapseUSecRealtime "$t" | tr -c 'A-Za-z0-9 :+-\n' '_')"; done
  [ -e "$STATE_FILE" ] && echo "T8_PAUSED_MARKER=present" || echo "T8_PAUSED_MARKER=absent"
}
pause() {
  [ ! -e "$STATE_FILE" ] || stop already_paused
  local t s v
  for t in $TIMERS; do [ "$(show ActiveState "$t")" = "active" ] || stop timer_not_active_before; [ "$(show UnitFileState "$t")" = "enabled" ] || stop timer_not_enabled; done
  { echo "paused_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"; echo "timers=$TIMERS"; } > "$STATE_FILE" || stop state_write_failed
  for t in $TIMERS; do timeout 15 systemctl stop -- "$t" >/dev/null 2>&1 || stop stop_failed; done
  local waited=0
  while :; do
    local busy=0; for s in $SERVICES; do v=$(show ActiveState "$s"); case "$v" in inactive|failed) ;; *) busy=1 ;; esac; done
    [ "$busy" -eq 0 ] && break
    waited=$(( waited + 5 )); [ "$waited" -le "$SERVICE_WAIT_SEC" ] || stop service_still_active
    sleep 5
  done
  for t in $TIMERS; do [ "$(show ActiveState "$t")" = "inactive" ] || stop timer_still_active; [ "$(show UnitFileState "$t")" = "enabled" ] || stop enablement_changed; done
  echo "T8_TIMERS=PAUSED;TIMERS=2;ENABLEMENT=unchanged;SERVICES_WAITED=${waited}s"
}
restore() {
  [ -e "$STATE_FILE" ] || stop not_paused_by_this_tool
  local t v
  for t in $TIMERS; do timeout 15 systemctl start -- "$t" >/dev/null 2>&1 || stop start_failed; done
  for t in $TIMERS; do
    [ "$(show ActiveState "$t")" = "active" ] || stop timer_not_active_after
    [ "$(show UnitFileState "$t")" = "enabled" ] || stop enablement_changed
    v=$(show NextElapseUSecRealtime "$t"); [ -n "$v" ] && [ "$v" != "n/a" ] || stop no_next_elapse
  done
  echo "restored_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$STATE_FILE" 2>/dev/null
  echo "T8_TIMERS=RESTORED;TIMERS=2;ENABLEMENT=unchanged"
}
[ "$(id -u)" = "$ROOT_UID_EXPECT" ] || stop not_root
[ "$#" -eq 1 ] || stop usage
case "$1" in status) status ;; pause) pause ;; restore) restore ;; *) stop usage ;; esac
