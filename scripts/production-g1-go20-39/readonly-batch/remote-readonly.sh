#!/bin/bash
# GO-20.39 host read-only batch (runs as deploy over the shared ControlMaster; no sudo except the documented read-only wrapper actions).
# Writes nothing on the host. Usage: ssh ... deploy@host 'bash -s' < remote-readonly.sh
export LANG=C LC_ALL=C PATH=/usr/sbin:/usr/bin:/sbin:/bin
W=${WPCG_WRAPPER:-/usr/local/sbin/wpcg-deploy-48h}
SUDO=${WPCG_SUDO:-sudo}   # test seam only; the batch always uses plain sudo -n
echo "BATCH_NOW_UTC=$(date -u +%FT%TZ)"
echo "== meminfo"; grep -E '^(MemTotal|MemAvailable|SwapTotal|SwapFree):' /proc/meminfo
echo "== pressure"; cat /proc/pressure/memory 2>&1
for t in phpsessionclean.timer wpcg-staging-next-scheduler.timer; do
  echo "== timer $t"
  systemctl show "$t" -p Id -p ActiveState -p SubState -p UnitFileState -p TimersCalendar -p NextElapseUSecRealtime -p LastTriggerUSec -p Persistent --no-pager 2>&1 | cut -c1-200
done
echo "== listeners (3306/3308/3309/33060/33061)"
ss -ltnH 2>/dev/null | awk '{print $4}' | grep -E ':(3306|3308|3309|33060|33061)$' || echo "none of 3306/3308/3309/33060/33061 listening"
for p in 3308 3309 33061; do ss -ltnH 2>/dev/null | awk '{print $4}' | grep -q ":$p\$" && echo "PORT_$p=LISTENING" || echo "PORT_$p=FREE"; done
for a in status archive-verify pkg-state timers-status; do
  echo "== wrapper $a"
  # Capture the COMPLETE output first (no early pipe close, no truncation, no SIGPIPE), keep the real exit status, then print.
  wout=$("$SUDO" -n "$W" "$a" 2>&1); wrc=$?
  printf '%s\n' "$wout" | grep -v '^tr: range-endpoints'
  echo "RC_$a=$wrc"
done
echo "== resolved G1 buffer-pool/log-buffer: NOT collected (the dry-run pre.out lives under /root; deploy has no access, no sudo/search attempted)"
echo "BATCH_END"
