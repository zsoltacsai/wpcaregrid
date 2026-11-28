#!/usr/bin/env bash
# WP CareGrid GO-20.39: PKG-STATE v1, a fail-closed classifier that tells the resident unattended-upgrades helper apart from real package activity.
# Root console, read-only (no lock attempt, no change). Security updates are never disabled or paused. Output: class tokens and counts only (no command lines).
#   PKG_STATE=CLEAR_RESIDENT_ONLY  only the resident helper runs, apt-daily*.service inactive, no dpkg/apt lock held
#   PKG_STATE=STOP;REASON=...      anything else, including any UNKNOWN
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C
unset BASH_ENV ENV CDPATH IFS 2>/dev/null
set -u
ROOT_UID_EXPECT="0"
PROCROOT="/proc"
LOCKS_FILE="/proc/locks"
LOCK_FILES="/var/lib/dpkg/lock /var/lib/dpkg/lock-frontend /var/lib/apt/lists/lock /var/cache/apt/archives/lock"
APT_UNITS="apt-daily.service apt-daily-upgrade.service"
stop() { echo "PKG_STATE=STOP;REASON=$1"; exit 1; }
[ "$(id -u)" = "$ROOT_UID_EXPECT" ] || stop not_root
[ "$#" -eq 0 ] || stop usage
for t in awk stat timeout systemctl tr cat; do command -v "$t" >/dev/null 2>&1 || stop missing_tool; done

resident=0; upgrade=0; other=0; unknown=0
for d in "$PROCROOT"/[0-9]*; do
  [ -d "$d" ] || continue
  comm=$(cat "$d/comm" 2>/dev/null) || { [ -d "$d" ] && unknown=$((unknown+1)); continue; }
  case "$comm" in
    apt|apt-get|aptitude|dpkg|apt.systemd.dai) other=$((other+1)) ;;
    unattended-upgr)
      cmd=$(tr '\0' ' ' < "$d/cmdline" 2>/dev/null) || { unknown=$((unknown+1)); continue; }
      st=$(awk '{ s=$0; sub(/^[^)]*\) /, "", s); split(s, a, " "); print a[1] }' "$d/stat" 2>/dev/null)
      case "$cmd" in
        *unattended-upgrade-shutdown*) if [ "$st" = "S" ]; then resident=$((resident+1)); else other=$((other+1)); fi ;;
        */unattended-upgrade\ *|*/unattended-upgrade) upgrade=$((upgrade+1)) ;;
        '') unknown=$((unknown+1)) ;;
        *) other=$((other+1)) ;;
      esac ;;
  esac
done
echo "PKG_STATE_PROCS helper_resident=$resident upgrade_run=$upgrade other_active=$other unknown=$unknown"

bad_unit=0
for u in $APT_UNITS; do
  v=$(timeout 5 systemctl show -p ActiveState --value -- "$u" 2>/dev/null) || v=""
  case "$v" in [a-z-]*) ;; *) v="unknown" ;; esac
  echo "PKG_STATE_UNIT name=$u state=$v"
  case "$v" in inactive|failed) ;; *) bad_unit=$((bad_unit+1)) ;; esac
done

held=0; lunknown=0
[ -r "$LOCKS_FILE" ] || lunknown=1
for f in $LOCK_FILES; do
  [ -e "$f" ] || continue
  ino=$(stat -c '%i' -- "$f" 2>/dev/null) || { lunknown=$((lunknown+1)); continue; }
  if [ -r "$LOCKS_FILE" ]; then
    awk -v ino="$ino" '{ n=split($6, p, ":"); if (p[n] == ino) f=1 } END { exit f ? 0 : 1 }' "$LOCKS_FILE" 2>/dev/null && held=$((held+1))
  fi
done
echo "PKG_STATE_LOCKS held=$held unknown=$lunknown"

[ "$unknown" -eq 0 ] || stop process_unknown
[ "$upgrade" -eq 0 ] || stop upgrade_run_active
[ "$other" -eq 0 ] || stop apt_or_dpkg_active
[ "$bad_unit" -eq 0 ] || stop apt_unit_active_or_unknown
[ "$lunknown" -eq 0 ] || stop lock_state_unknown
[ "$held" -eq 0 ] || stop package_lock_held
echo "PKG_STATE=CLEAR_RESIDENT_ONLY"
