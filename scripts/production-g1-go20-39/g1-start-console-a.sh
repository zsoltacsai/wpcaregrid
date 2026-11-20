#!/usr/bin/env bash
# WP CareGrid GO-20.39: console A of the controlled G1 production start. Root console, Owner GO for the start required (separate from this file).
# Order inside ONE run, no loop, no retry: gates -> final pre-start run + gate -> ONE helper start -> POST run + gate. Console B (the S7-B wrapper,
# --mode contain) must already be armed. This script never stops, kills, resets or restarts anything: containment belongs to console B.
#   g1-start-console-a.sh --pre-script <path> --pre-sha <64 hex> --confirm "START production <first 12 hex of the pre-script sha>"
# Output: fixed key=value lines; every result is also written to the root-only run directory (0700, files 0600).
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C
unset BASH_ENV ENV CDPATH IFS 2>/dev/null
set -u; set -o noclobber

ROOT_UID_EXPECT="0"
TOOLKIT="/root/wpcg-g1-start-toolkit"
RUN_PARENT="/root"
HELPER="/usr/local/libexec/wpcaregrid-g1-mysql.py"
HELPER_SHA="9ff1b969fa2371d3967c15ff9f6fcf4553ecf5fcda2b039b46e924d885bbf274"
COLLECTOR_PATH="/home/zsolt/Projects/project-wpcaregrid/wpcaregrid/storage/delivery/go20-37-v2.2/go20-37-collector-v2.2.sh"
COLLECTOR_SHA="25c985276f1016eabe8a0338caaecc1134c38999d84180170308a76ea9f54956"
GATE_SHA="1780fd1e30800b6ca6a0799aa3e94b1b1f105a549e293d84c858a30afa9ef326"
THRESHOLDS_SHA="d5a029bda6f51c97d73f99180a864307b8a95ddbd8a4ffd4780b10565bf57260"
POST_SHA="14c875db52a4cc910ad04f32a5b7033bfedc6f9ce301d89de7f381609fa122a4"
POSTGATE_SHA="7be4b22aa01da7f5bbae6ba41854c85a4db5dbdd6a264d8113be43150e3910fd"
PKGSTATE_SHA_FILE="$TOOLKIT/pkg-state.sh"   # hash is printed, the Owner compares it with the approved value
S7B_LOCK="/run/wpcg-s7b.lock"
T8_MARKER="/run/wpcg-t8-paused"
PRE_TIMEOUT=600; START_TIMEOUT=180; POST_TIMEOUT=300; MAX_PRE_TO_START_SEC=1800
stop() { printf 'G1_START=STOP;CODE=%s;PHASE=%s\n' "$1" "$PHASE"; [ -n "$RUN" ] && [ -f "$RUN/result.txt" ] && printf 'STOP;CODE=%s;PHASE=%s\n' "$1" "$PHASE" >> "$RUN/result.txt"; exit "${2:-1}"; }
sha_of() { sha256sum -- "$1" 2>/dev/null | cut -d' ' -f1; }
PHASE="args"; RUN=""

main() {
  [ "$(id -u)" = "$ROOT_UID_EXPECT" ] || stop not_root 2
  [ "$#" -eq 6 ] || stop usage 2
  local pre="" presha="" conf=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --pre-script) [ -z "$pre" ] || stop usage 2; pre="${2:-}"; shift 2 ;;
      --pre-sha)    [ -z "$presha" ] || stop usage 2; presha="${2:-}"; shift 2 ;;
      --confirm)    [ -z "$conf" ] || stop usage 2; conf="${2:-}"; shift 2 ;;
      *) stop usage 2 ;;
    esac
  done
  [[ "$presha" =~ ^[0-9a-f]{64}$ ]] || stop pre_sha_format 2
  [ "$conf" = "START production ${presha:0:12}" ] || stop confirmation_mismatch 2
  for t in python3 bash env timeout flock awk sed grep cut date od tr stat sha256sum; do command -v "$t" >/dev/null 2>&1 || stop missing_tool 2; done
  # ---- gates before anything is executed
  PHASE="gates"
  case "$pre" in /*) ;; *) stop pre_path_not_absolute ;; esac
  [ -f "$pre" ] && [ ! -L "$pre" ] || stop pre_script_invalid
  [ "$(sha_of "$pre")" = "$presha" ] || stop pre_script_hash
  [ "$(sha_of "$TOOLKIT/go20-38-pre-gate-v4a.sh")" = "$GATE_SHA" ] || stop gate_hash
  [ "$(sha_of "$TOOLKIT/go20-38-pre-thresholds-v1.txt")" = "$THRESHOLDS_SHA" ] || stop thresholds_hash
  [ "$(sha_of "$TOOLKIT/go20-38-post-script-v2.py")" = "$POST_SHA" ] || stop post_script_hash
  [ "$(sha_of "$TOOLKIT/go20-38-post-gate-v2.sh")" = "$POSTGATE_SHA" ] || stop post_gate_hash
  [ "$(sha_of "$HELPER")" = "$HELPER_SHA" ] || stop helper_hash
  [ "$(sha_of "$COLLECTOR_PATH")" = "$COLLECTOR_SHA" ] || stop collector_hash
  [ -f "$PKGSTATE_SHA_FILE" ] && [ ! -L "$PKGSTATE_SHA_FILE" ] || stop pkg_state_missing
  [ -e "$T8_MARKER" ] || stop t8_timers_not_paused
  { exec 8>>"$S7B_LOCK"; } 2>/dev/null || stop s7b_lock_unreadable
  if flock -n 8; then stop console_b_not_armed; fi   # the lock must be HELD by the running S7-B wrapper
  local armed; armed=$(ls -1dt "$RUN_PARENT"/wpcg-s7b-* 2>/dev/null | head -n 1)
  [ -n "$armed" ] && grep -q '^S7B_ARMED .* mode=contain ' "$armed/evidence.txt" 2>/dev/null || stop console_b_not_in_contain_mode
  [ -d "$RUN_PARENT" ] && [ ! -L "$RUN_PARENT" ] || stop run_parent_invalid
  local rid; rid="$(date -u +%Y%m%dT%H%M%SZ)-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
  RUN="$RUN_PARENT/wpcg-g1-start-$rid"; mkdir -m 0700 -- "$RUN" || stop run_dir_failed
  { : > "$RUN/result.txt"; } || stop run_dir_failed
  printf 'START_RUN=%s;PRE_SHA256=%s;PKG_STATE_SHA256=%s\n' "$rid" "$presha" "$(sha_of "$PKGSTATE_SHA_FILE")" | tee -a "$RUN/result.txt"
  # ---- package state (read-only classifier)
  PHASE="pkg_state"
  bash --noprofile --norc "$PKGSTATE_SHA_FILE" > "$RUN/pkg-state.txt" 2>&1; local prc=$?
  tail -n 1 "$RUN/pkg-state.txt" | tee -a "$RUN/result.txt"
  [ "$prc" -eq 0 ] && [ "$(tail -n 1 "$RUN/pkg-state.txt")" = "PKG_STATE=CLEAR_RESIDENT_ONLY" ] || stop package_state_not_clear
  # ---- final pre-start run + gate
  PHASE="pre_run"
  local t0; t0=$(date -u +%s.%N)
  timeout -k 10 "$PRE_TIMEOUT" env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C bash --noprofile --norc "$pre" < /dev/null > "$RUN/pre.out" 2> "$RUN/pre.err"; local rc=$?
  T_PRE_END=$(date -u +%s.%N)
  [ "$rc" -eq 0 ] || stop pre_script_rc_nonzero
  [ ! -s "$RUN/pre.err" ] || stop pre_script_stderr
  PHASE="pre_gate"
  bash --noprofile --norc "$TOOLKIT/go20-38-pre-gate-v4a.sh" "$RUN/pre.out" "$presha" "$THRESHOLDS_SHA" > "$RUN/pre-gate.txt" 2>&1; local grc=$?
  tail -n 2 "$RUN/pre-gate.txt" | tee -a "$RUN/result.txt"
  [ "$grc" -eq 0 ] || stop pre_gate_not_clean
  grep -qx 'PRE_OUTCOME=NO_BLOCKER_FOUND' "$RUN/pre.out" || stop pre_outcome_not_green
  local ls; ls=$(grep -E '^LOGSTATE inode=[0-9]+ size=[0-9]+ t0=[0-9]+$' "$RUN/pre.out"); [ -n "$ls" ] && [ "$(printf '%s\n' "$ls" | wc -l)" -eq 1 ] || stop logstate_missing
  local logarg; logarg=$(printf '%s' "$ls" | sed -E 's/^LOGSTATE inode=([0-9]+) size=([0-9]+) t0=([0-9]+)$/\1:\2:\3/')
  [ "${logarg%%:*}" != "0" ] || stop logstate_inode_zero
  local boot; boot=$(tr -d '-' < /proc/sys/kernel/random/boot_id | cut -c1-32)
  # ---- the ONE start (freshness is checked at the last moment)
  PHASE="start"
  local cursor; cursor=$(timeout 10 journalctl -n0 --show-cursor 2>/dev/null | sed -n 's/^-- cursor: //p' | head -n 1)
  T_START_CMD=$(date -u +%s.%N)
  awk -v a="$T_PRE_END" -v b="$T_START_CMD" -v m="$MAX_PRE_TO_START_SEC" 'BEGIN{exit (b-a<=m)?0:1}' || stop pre_run_stale
  timeout -k 10 "$START_TIMEOUT" python3 -I -B "$HELPER" start --instance wpcaregrid-production > "$RUN/helper-start.out" 2> "$RUN/helper-start.err"; local hrc=$?
  T_START_RETURN=$(date -u +%s.%N)
  local hstat; hstat=$(grep -oE '(START=PASS|G1_MYSQL=STOP;CODE=[a-z_]+|RUNTIME=PASS)' "$RUN/helper-start.out" | head -n 1 | tr -c 'A-Za-z0-9_.:=/-' '_' | cut -c1-40)
  printf 'HELPER_START rc=%s status=%s t_start_cmd=%s t_start_return=%s\n' "$hrc" "${hstat:-unknown}" "$T_START_CMD" "$T_START_RETURN" | tee -a "$RUN/result.txt"
  # ---- POST always runs, whatever the helper returned
  PHASE="post"
  T_POST_BEGIN=$(date -u +%s.%N)
  local pargs=("$logarg" --t-pre-end "$T_PRE_END" --t-start-cmd "$T_START_CMD" --t-start-return "$T_START_RETURN" --t-post-begin "$T_POST_BEGIN" --boot-id "$boot" --helper-rc "$hrc")
  [ -z "$cursor" ] || [[ ! "$cursor" =~ ^[A-Za-z0-9_=\;:.-]{1,200}$ ]] || pargs+=(--cursor "$cursor")
  [ -z "${hstat:-}" ] || pargs+=(--helper-status "$hstat")
  timeout -k 10 "$POST_TIMEOUT" python3 -I -B "$TOOLKIT/go20-38-post-script-v2.py" "${pargs[@]}" > "$RUN/post.out" 2> "$RUN/post.err"; local porc=$?
  bash --noprofile --norc "$TOOLKIT/go20-38-post-gate-v2.sh" "$RUN/post.out" "$POST_SHA" > "$RUN/post-gate.txt" 2>&1; local pgrc=$?
  tail -n 2 "$RUN/post-gate.txt" | tee -a "$RUN/result.txt"
  printf 'G1_START=DONE;HELPER_RC=%s;POST_RC=%s;POST_GATE_RC=%s;RUN_DIR=%s;NO_RETRY=yes;CLEANUP=none\n' "$hrc" "$porc" "$pgrc" "$RUN" | tee -a "$RUN/result.txt"
  [ "$hrc" -eq 0 ] && [ "$porc" -eq 0 ] && [ "$pgrc" -eq 0 ] && exit 0
  exit 1
}
main "$@"
