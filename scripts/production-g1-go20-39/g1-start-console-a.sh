#!/usr/bin/env bash
# WP CareGrid GO-20.39: console A of the controlled G1 production start. Root console, Owner GO for the start required (separate from this file).
# Order inside ONE run, no loop, no retry: gates -> final pre-start run + gate -> ONE helper start -> POST run + gate. Console B (the S7-B wrapper,
# --mode contain) must already be armed. This script never stops, kills, resets or restarts anything: containment belongs to console B.
#   g1-start-console-a.sh --pre-script <path> --pre-sha <64 hex> --confirm "START production <first 12 hex of the pre-script sha>"
# Output: fixed key=value lines; every result is also written to the root-only run directory (0700, files 0600).
# CG-1 (S7 containment): the helper is started ONLY after (a) the exact console B run instance recorded at the gates is verified again (lock held, same directory
# identity, one armed record for this directory, no result/start/trigger line) and (b) a START_INTENT / START_ACK handshake with THAT instance, performed after every
# long or variable-time step (PKG-STATE, pre run, pre gate, journal cursor) and immediately before the helper call: intent = fresh 128-bit nonce bound to the run directory,
# ACK = written by console B after it committed to keep waiting for the start for >= ACK_MIN_COMMIT_SEC from the ACK. The ACK wait is bounded (20 polls, no retry, no
# restart of the handshake). Intent -> helper call is capped at MAX_INTENT_TO_START_SEC. A missing, malformed, foreign, replayed or late ACK, or a console B that is gone,
# finished or replaced, is a STOP before the helper. NOT closable by code: console B exiting in the few milliseconds between the final liveness check and the moment
# the unit leaves `inactive` (see docs/PRODUCTION_G1_START_PROCEDURE.md, CG-1).
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
# CG-1 handshake constants. ACK_MIN_COMMIT_SEC (console B INTENT_WAIT_SEC) = MAX_INTENT_TO_START_SEC 30 + START_TIMEOUT 180 + kill grace 10 + one sample 5 + reserve 25 = 250.
ACK_POLL_SEC=1; MAX_INTENT_TO_START_SEC=30; ACK_MIN_COMMIT_SEC=250
ARMED_DIR=""; ARMED_ID=""; BC_WHY=""; ACK_WHY=""; H_RID=""; H_NONCE=""
stop() { printf 'G1_START=STOP;CODE=%s;PHASE=%s\n' "$1" "$PHASE"; [ -n "$RUN" ] && [ -f "$RUN/result.txt" ] && printf 'STOP;CODE=%s;PHASE=%s\n' "$1" "$PHASE" >> "$RUN/result.txt"; exit "${2:-1}"; }
sha_of() { sha256sum -- "$1" 2>/dev/null | cut -d' ' -f1; }
PHASE="args"; RUN=""
# b_check: 0 only if the exact console B run instance recorded at the gates is still armed and alive; otherwise BC_WHY names the reason
b_check() {
  local cur ev
  BC_WHY=""
  if flock -n 8; then BC_WHY="lock_free"; return 1; fi
  cur=$(ls -1d "$RUN_PARENT"/wpcg-s7b-* 2>/dev/null | sort | tail -n 1)
  [ -n "$ARMED_DIR" ] && [ "$cur" = "$ARMED_DIR" ] || { BC_WHY="run_dir_changed"; return 1; }
  [ "$(stat -c '%d:%i' -- "$ARMED_DIR" 2>/dev/null)" = "$ARMED_ID" ] || { BC_WHY="run_dir_identity"; return 1; }
  ev="$ARMED_DIR/evidence.txt"
  [ -f "$ev" ] && [ ! -L "$ev" ] || { BC_WHY="evidence_missing"; return 1; }
  [ "$(grep -c '^S7B_ARMED ' "$ev" 2>/dev/null)" = "1" ] || { BC_WHY="armed_record"; return 1; }
  case "$(grep '^S7B_ARMED ' "$ev" 2>/dev/null)" in "S7B_ARMED version="*" instance=production mode=contain unit="*" run_dir=$ARMED_DIR") ;; *) BC_WHY="armed_record"; return 1 ;; esac
  if grep -qE '^(S7B_RESULT=|S7B_FINAL |S7B_START_OBSERVED |S7B_TRIGGER )' "$ev" 2>/dev/null; then BC_WHY="b_already_started_or_finished"; return 1; fi
  return 0
}
# ack_valid: 0 only for an ACK written by THIS console B run for THIS intent (run id + nonce), root-owned, 0600, one line, with a long enough commitment, B process alive
ack_valid() {
  local a="$ARMED_DIR/START_ACK" meta line
  ACK_WHY=""
  if [ ! -e "$a" ] && [ ! -L "$a" ]; then ACK_WHY="absent"; return 1; fi
  [ -f "$a" ] && [ ! -L "$a" ] || { ACK_WHY="not_regular"; return 1; }
  meta=$(stat -c '%u %a %s' -- "$a" 2>/dev/null) || { ACK_WHY="stat"; return 1; }
  [ "${meta%% *}" = "$ROOT_UID_EXPECT" ] || { ACK_WHY="owner"; return 1; }
  case "$meta" in *" 600 "*) ;; *) ACK_WHY="mode"; return 1 ;; esac
  IFS= read -r line < "$a" 2>/dev/null || { ACK_WHY="unreadable"; return 1; }
  [ "${meta##* }" = "$(( ${#line} + 1 ))" ] || { ACK_WHY="not_one_line"; return 1; }
  [[ "$line" =~ ^START_ACK\ v1\ run=([0-9]{8}T[0-9]{6}Z-[0-9a-f]{12})\ nonce=([0-9a-f]{32})\ pid=([0-9]{1,10})\ w=([0-9]{1,5})$ ]] || { ACK_WHY="format"; return 1; }
  [ "${BASH_REMATCH[1]}" = "$H_RID" ] || { ACK_WHY="other_run"; return 1; }
  [ "${BASH_REMATCH[2]}" = "$H_NONCE" ] || { ACK_WHY="nonce"; return 1; }
  [ "${BASH_REMATCH[4]}" -ge "$ACK_MIN_COMMIT_SEC" ] || { ACK_WHY="commitment_short"; return 1; }
  [ -d "/proc/${BASH_REMATCH[3]}" ] || { ACK_WHY="b_process_gone"; return 1; }
  return 0
}

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
  for t in python3 bash env timeout flock awk sed grep cut date od tr stat sha256sum sleep ls head sort tail; do command -v "$t" >/dev/null 2>&1 || stop missing_tool 2; done
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
  local armed; armed=$(ls -1d "$RUN_PARENT"/wpcg-s7b-* 2>/dev/null | sort | tail -n 1)   # newest run = greatest run id (UTC timestamp first); never by mtime: a START_ACK written into a directory changes its mtime
  [ -n "$armed" ] && grep -q '^S7B_ARMED .* mode=contain ' "$armed/evidence.txt" 2>/dev/null || stop console_b_not_in_contain_mode
  ARMED_DIR="$armed"; ARMED_ID=$(stat -c '%d:%i' -- "$armed" 2>/dev/null)
  b_check || stop console_b_state_invalid   # the record must name exactly this directory (run_dir=...) and show no result/start/trigger line yet
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
  # ---- the ONE start: everything long or variable (journal cursor) comes BEFORE the handshake; the handshake and the last checks are immediately before the helper
  PHASE="start"
  local cursor; cursor=$(timeout 10 journalctl -n0 --show-cursor 2>/dev/null | sed -n 's/^-- cursor: //p' | head -n 1)
  PHASE="start_handshake"
  H_RID="${ARMED_DIR##*/wpcg-s7b-}"
  b_check || stop console_b_changed_before_start
  H_NONCE=$(od -An -N16 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n'); [[ "$H_NONCE" =~ ^[0-9a-f]{32}$ ]] || stop start_nonce_failed
  { [ ! -e "$ARMED_DIR/START_INTENT" ] && [ ! -L "$ARMED_DIR/START_INTENT" ] && [ ! -e "$ARMED_DIR/START_ACK" ] && [ ! -L "$ARMED_DIR/START_ACK" ]; } || stop start_handshake_preexisting
  local T_INTENT; T_INTENT=$(date -u +%s.%N)
  ( umask 077; printf 'START_INTENT v1 run=%s nonce=%s\n' "$H_RID" "$H_NONCE" > "$ARMED_DIR/START_INTENT" ) 2>/dev/null || stop start_intent_not_written
  local ack_ok=0 n
  for n in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
    if ack_valid; then ack_ok=1; break; fi
    sleep "$ACK_POLL_SEC"
  done
  if [ "$ack_ok" -ne 1 ]; then if [ "$ACK_WHY" = "absent" ]; then stop console_b_no_ack; fi; stop console_b_bad_ack; fi
  printf 'START_HANDSHAKE run=%s nonce=%s ack=valid commit_s>=%s\n' "$H_RID" "$H_NONCE" "$ACK_MIN_COMMIT_SEC" | tee -a "$RUN/result.txt"
  T_START_CMD=$(date -u +%s.%N)
  awk -v a="$T_PRE_END" -v b="$T_START_CMD" -v m="$MAX_PRE_TO_START_SEC" 'BEGIN{exit (b-a<=m)?0:1}' || stop pre_run_stale
  awk -v a="$T_INTENT" -v b="$T_START_CMD" -v m="$MAX_INTENT_TO_START_SEC" 'BEGIN{exit (b-a<=m)?0:1}' || stop start_ack_expired
  b_check || stop console_b_changed_before_start   # LAST check: the exact B instance is still armed and alive; the helper call is the very next command
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
