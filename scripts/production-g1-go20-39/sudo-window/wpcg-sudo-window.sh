#!/usr/bin/env bash
# GO-20.39 time-boxed sudo window for user "deploy" (root console only).
#   grant  [hours]  install a command-allow-list sudoers drop-in + reboot-surviving expiry timer (default 48)
#   revoke          remove ONLY the drop-in and the two units; touches no data or application file
#   status          show drop-in, timer, expiry, recent sudo log
# No NOPASSWD ALL, no shell, no editor, no systemctl, no reboot. Every listed tool validates its own arguments.
set -uo pipefail
umask 077
SUDOERS=/etc/sudoers.d/90-wpcg-go2039
UNIT=wpcg-go2039-sudo-expiry
TK=/root/wpcg-g1-start-toolkit
die(){ echo "SUDO_WINDOW=STOP;CODE=$1"; exit 1; }
[ "$(id -u)" = 0 ] || die not_root
cmd=${1:-}; hrs=${2:-48}
case "$cmd" in
grant)
  [[ $hrs =~ ^[0-9]{1,3}$ ]] && [ "$hrs" -ge 1 ] && [ "$hrs" -le 48 ] || die hours_1_to_48
  [ -d "$TK" ] && [ "$(stat -c %U:%a "$TK")" != "" ] || die toolkit_missing
  [ ! -e "$SUDOERS" ] || die already_granted
  [ ! -e /etc/systemd/system/$UNIT.timer ] || die timer_exists
  for t in g1-cold-archive.sh g1-restore-datadir.sh t8-timers.sh pkg-state.sh s7b-containment.sh g1-start-console-a.sh; do
    [ -f "$TK/$t" ] || die "missing_$t"
  done
  [ "$(stat -c %U "$TK")" = root ] || die toolkit_not_root_owned
  exp=$(date -u -d "+$hrs hours" '+%Y-%m-%d %H:%M:%S UTC')
  tmp=$(mktemp /etc/sudoers.d/.wpcg-go2039.XXXXXX) || die mktemp
  {
    echo "# GO-20.39 time-boxed; expires $exp via $UNIT.timer"
    echo "Defaults:deploy !requiretty, log_allowed, log_denied, logfile=/var/log/wpcg-sudo-go2039.log"
    for t in g1-cold-archive.sh g1-restore-datadir.sh t8-timers.sh pkg-state.sh s7b-containment.sh g1-start-console-a.sh; do
      echo "deploy ALL=(root) NOPASSWD: $TK/$t, $TK/$t *"
    done
  } > "$tmp"
  chmod 0440 "$tmp"; chown root:root "$tmp"
  visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; die visudo_failed; }
  cat > /etc/systemd/system/$UNIT.service <<U
[Unit]
Description=Revoke GO-20.39 deploy sudo window (removes only the sudoers drop-in and these units)
[Service]
Type=oneshot
ExecStart=/bin/rm -f $SUDOERS
ExecStart=/bin/sh -c 'systemctl disable $UNIT.timer; rm -f /etc/systemd/system/$UNIT.timer /etc/systemd/system/$UNIT.service; systemctl daemon-reload'
U
  cat > /etc/systemd/system/$UNIT.timer <<U
[Unit]
Description=GO-20.39 deploy sudo window expiry
[Timer]
OnCalendar=$exp
Persistent=true
AccuracySec=1s
[Install]
WantedBy=timers.target
U
  chmod 0644 /etc/systemd/system/$UNIT.service /etc/systemd/system/$UNIT.timer
  systemctl daemon-reload && systemctl enable --now $UNIT.timer >/dev/null 2>&1 || { rm -f "$tmp"; die timer_enable_failed; }
  mv "$tmp" "$SUDOERS" || die install_failed
  echo "SUDO_WINDOW=GRANTED;EXPIRES=$exp;TIMER=$UNIT.timer;NEXT=$(systemctl show $UNIT.timer -p NextElapseUSecRealtime --value)"
  ;;
revoke)
  rm -f "$SUDOERS"
  systemctl disable --now $UNIT.timer >/dev/null 2>&1
  rm -f /etc/systemd/system/$UNIT.timer /etc/systemd/system/$UNIT.service; systemctl daemon-reload
  echo "SUDO_WINDOW=REVOKED"
  ;;
status)
  [ -e "$SUDOERS" ] && { ls -l "$SUDOERS"; head -1 "$SUDOERS"; } || echo "drop-in absent"
  systemctl list-timers "$UNIT.timer" --all --no-pager 2>&1 | head -3
  tail -n 10 /var/log/wpcg-sudo-go2039.log 2>/dev/null
  ;;
*) die usage;;
esac
