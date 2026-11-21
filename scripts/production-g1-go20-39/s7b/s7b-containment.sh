#!/usr/bin/env bash
# WP CareGrid GO-20.38 S7-B containment wrapper (console B), candidate v3 (CG-1: START_INTENT / START_ACK handshake).
# NOT installed, NOT approved. Local development artefact. Real use needs a separate Owner execution GO.
#
# Role: run on the SECOND root console (console B) BEFORE console A issues the single helper start. It observes the G1 unit for at most
# 300 s after the activation is first seen (5 s samples), watches memory/OOM and the legacy mysql.service, and in --mode contain performs
# at most ONE `systemctl stop --no-block` (wait <= 120 s). A cgroup.kill is NEVER automatic: it needs a full validation AND the Owner typing
# an exact confirmation phrase on the console. Any uncertainty is STOP/UNKNOWN. It never starts a unit, never reset-failed, never
# daemon-reload, never retries, never writes /proc/sys, never edits AppArmor/MySQL/systemd configuration, never removes anything.
#
# Timing semantics: the 150 s hang cutoff and the 300 s window are counted from the FIRST OBSERVATION of activation (up to 5 s after the real
# start command), so the real latency from the start command to the hang trigger is 150..~160 s and the window ends 300..~306 s after it.
# Exit codes: 0 only = window completed with G1 active/running verified and no trigger; 1 = a trigger/outcome that needs the Owner's decision;
# 2 = refused at arm / usage; 4 = G1 NOT SUPERVISED (no start observed, or observation lost before the start was seen); 5 = ABORTED (the script died
# without a result: treat G1 as unsupervised); 130 = interrupted.
# stdout must be a terminal in contain mode (use script(1) for a transcript, not a pipe to tee).
#
# CG-1 handshake (console A <-> console B, arm phase only). Console A may issue the helper start only after it has written a START_INTENT file into THIS run
# directory and read back a matching START_ACK written by THIS process. Intent: one line `START_INTENT v1 run=<run id> nonce=<32 hex>` (regular file, root-owned,
# mode 0600, no symlink). B accepts exactly ONE intent per run (never a second one, never a replay), verifies run id and nonce, and BEFORE it writes
# `START_ACK v1 run=<run id> nonce=<nonce> pid=<B pid> w=<INTENT_WAIT_SEC>` it commits to keep waiting for the start until ACK_TIME + INTENT_WAIT_SEC (the arm deadline
# only moves forward, never back). INTENT_WAIT_SEC = 250 s = 30 s (console A: intent -> helper call, enforced by console A) + 180 s (helper timeout) + 10 s (kill grace)
# + 5 s (one sample) + 25 s reserve. A missing, malformed, foreign-run, replayed or late intent/ACK means NO start (console A stops before the helper).
# What the handshake can NOT do: if B exits after console A's last liveness check and before the unit leaves `inactive`, the start is unsupervised. That window is not
# closable by code and stays an Owner decision (docs/PRODUCTION_G1_START_PROCEDURE.md, CG-1).
#
# Usage (absolute path, root-owned file):   /root/go20-38-s7b-containment-v1.sh --instance production|restore --mode observe|contain

# ------------------------------------------------------------ constants (no environment or argument can change them)
SAMPLE_SEC=5            # sampling interval
WINDOW_SEC=300          # supervision window, counted from the first observation of activation
ARM_MAX_SEC=120         # how long to wait for the start after arming (until a valid START_INTENT is acknowledged)
INTENT_WAIT_SEC=250     # commitment after a valid ACK: 30 (A: intent->helper) + 180 (helper timeout) + 10 (kill grace) + 5 (sample) + 25 (reserve)
HANG_CUTOFF_SEC=150     # activating longer than this (from first observation) = start hang
STOP_WAIT_SEC=120       # wait after the single stop attempt
KILL_CONFIRM_SEC=60     # time the Owner has to type the confirmation phrase
KILL_VERIFY_SEC=10      # wait after a confirmed cgroup.kill
MEM_TRIGGER_MIB=256     # MemAvailable below this = memory trigger
OBS_LOST_SAMPLES=3      # consecutive unreadable samples = observation lost
MAX_KILL_PIDS=64
ROOT_UID_EXPECT="0"
RUN_PARENT="/root"
PROCROOT="/proc"
CGROOT="/sys/fs/cgroup"
LOCKFILE="/run/wpcg-s7b.lock"
LEGACY_UNIT="mysql.service"
WRAPPER_VERSION="s7b-containment-v3"
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C
unset BASH_ENV ENV CDPATH IFS 2>/dev/null
set -u
set -o noclobber

# test seams (the test copy replaces exactly these five function bodies and the constants; nothing else differs)
now()  { local u; read -r u _ < /proc/uptime 2>/dev/null; echo "${u%.*}"; }
nap()  { sleep "$1"; }
is_tty() { [ -t 0 ] && [ -t 1 ] && { : < /dev/tty; } 2>/dev/null; }
cgroup2_ok() { [ "$(stat -f -c %T -- "${1:-$CGROOT}" 2>/dev/null)" = "cgroup2fs" ]; }
confirm_read() { REPLY=""; IFS= read -r -t "$KILL_CONFIRM_SEC" REPLY < /dev/tty; }

RUN_DIR=""; EVID=""; LOG_STARTED=0; RESULT_PRINTED=0
EVID_FAIL=0
out()  {
  printf '%s\n' "$1"
  if [ -n "$EVID" ]; then
    { printf '%s\n' "$1" >> "$EVID"; } 2>/dev/null || { if [ "$EVID_FAIL" -eq 0 ]; then EVID_FAIL=1; printf '%s\n' "S7B_EVIDENCE_WRITE_FAILED"; fi; }
  fi
  return 0
}
die()  { RESULT_PRINTED=1; out "S7B_RESULT=STOP;CODE=$1"; exit "${2:-2}"; }
on_exit() { local rc=$?; if [ "$RESULT_PRINTED" -eq 0 ]; then RESULT_PRINTED=1; out "S7B_RESULT=ABORTED;RC=$rc;NOTE=unexpected_exit_G1_may_be_unsupervised"; exit 5; fi; }
is_uint() { case "$1" in ''|*[!0-9]*) return 1 ;; esac; [ "${#1}" -le 12 ]; }

# ------------------------------------------------------------------------ unit / system readers
# read_unit UNIT PFX : sets PFX_A (ActiveState) PFX_S (SubState) PFX_PID PFX_NR PFX_CG ; returns 1 on any failure or invalid value
read_unit() {
  local unit="$1" pfx="$2" raw k v a="" s="" pid="" nr="" cg="" ld="" rs="" rc f=""
  raw=$(timeout 5 systemctl show -p LoadState -p ActiveState -p SubState -p MainPID -p NRestarts -p ControlGroup -p Result -- "$unit" 2>/dev/null); rc=$?
  [ "$rc" -eq 0 ] || return 1
  while IFS='=' read -r k v; do
    case "$f" in *" $k "*) return 1 ;; esac   # a repeated key is invalid
    case "$k" in
      LoadState)   [[ "$v" =~ ^[a-z-]{1,24}$ ]] || return 1; ld="$v" ;;
      ActiveState) [[ "$v" =~ ^[a-z-]{1,24}$ ]] || return 1; a="$v" ;;
      SubState)    [[ "$v" =~ ^[a-z-]{1,40}$ ]] || return 1; s="$v" ;;
      MainPID)     is_uint "$v" || return 1; pid="$v" ;;
      NRestarts)   is_uint "$v" || return 1; nr="$v" ;;
      ControlGroup) [[ "$v" =~ ^[A-Za-z0-9@._/:-]{0,160}$ ]] || return 1; cg="$v" ;;
      Result)      [[ "$v" =~ ^[a-z-]{1,32}$ ]] || return 1; rs="$v" ;;
      '') continue ;;
      *) return 1 ;;
    esac
    f="$f $k "
  done <<< "$raw"
  for k in LoadState ActiveState SubState MainPID NRestarts ControlGroup Result; do case "$f" in *" $k "*) ;; *) return 1 ;; esac; done
  printf -v "${pfx}_L" '%s' "$ld"; printf -v "${pfx}_A" '%s' "$a"; printf -v "${pfx}_S" '%s' "$s"; printf -v "${pfx}_PID" '%s' "$pid"
  printf -v "${pfx}_NR" '%s' "$nr"; printf -v "${pfx}_CG" '%s' "$cg"; printf -v "${pfx}_R" '%s' "$rs"
  return 0
}
read_mem_mib() {  # MemAvailable in MiB or return 1
  local kb; kb=$(awk '$1=="MemAvailable:" && $3=="kB" {print $2; exit}' "$PROCROOT/meminfo" 2>/dev/null) || return 1
  is_uint "$kb" || return 1; MEM_MIB=$(( kb / 1024 )); return 0
}
read_oomkill() {  # oom_kill counter or return 1
  local v; v=$(awk '$1=="oom_kill" {print $2; exit}' "$PROCROOT/vmstat" 2>/dev/null) || return 1
  is_uint "$v" || return 1; OOMK="$v"; return 0
}

# ------------------------------------------------------------------------ the single stop and the gated kill
G1_STOP_ISSUED=0; KILL_DONE=0; KILL_WRITTEN=0; KILL_RESULT=""
emit_result() { RESULT_PRINTED=1; out "S7B_RESULT=$1"; }
final_line() {  # a last fresh read so the Owner sees the state the wrapper leaves behind (no action)
  local st="UNKNOWN"
  if read_unit "$G1_UNIT" Z; then st="${Z_A}/${Z_S}"; fi
  out "S7B_FINAL g1_now=$st stop_issued=$G1_STOP_ISSUED kill_written=$KILL_WRITTEN exit=$1"
}
do_stop_once() {
  [ "$G1_STOP_ISSUED" -eq 0 ] || return 1
  G1_STOP_ISSUED=1
  out "S7B_ACTION=stop_issued t=$(( $(now) - T_ARM ))"
  timeout 10 systemctl stop --no-block -- "$G1_UNIT" >/dev/null 2>&1 || { out "S7B_STOP_COMMAND=FAILED;NOTE=G1_may_still_be_running"; return 2; }
  return 0
}
wait_stop() {  # up to STOP_WAIT_SEC, 5 s polls; also keeps sampling memory and the legacy unit (no new action); sets STOP_OUTCOME
  local t0 t polls=0; t0=$(now)
  while :; do
    [ "$INTERRUPTED" -eq 0 ] || { STOP_OUTCOME="interrupted"; return 1; }
    nap "$SAMPLE_SEC"
    local lg="UNKNOWN" mm="UNKNOWN"
    read_unit "$LEGACY_UNIT" LG && lg="${LG_A}:${LG_PID}"
    read_mem_mib && mm="$MEM_MIB"
    if read_unit "$G1_UNIT" U; then
      out "S7B_STOPWAIT t=$(( $(now) - t0 )) g1=${U_A}/${U_S} mem=$mm legacy=$lg"
      case "$U_A" in inactive|failed) STOP_OUTCOME="stopped"; return 0 ;; esac
    else out "S7B_STOPWAIT t=$(( $(now) - t0 )) g1=UNKNOWN mem=$mm legacy=$lg"; fi
    polls=$((polls+1)); t=$(( $(now) - t0 ))
    if [ "$t" -ge "$STOP_WAIT_SEC" ] && [ "$polls" -ge $(( STOP_WAIT_SEC / SAMPLE_SEC )) ]; then STOP_OUTCOME="still_running"; return 1; fi
  done
}
kill_validate() {  # all conditions must hold; prints KILL_VALIDATION=PASS or STOP;CODE=...; sets KCG
  local cg lcg pid pids n c d kids main_in=0
  KCG=""
  [ "$INTERRUPTED" -eq 0 ] || { out "S7B_KILL_VALIDATION=STOP;CODE=interrupted"; return 1; }
  [ "$MODE" = "contain" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=mode_not_contain"; return 1; }
  is_tty || { out "S7B_KILL_VALIDATION=STOP;CODE=no_tty"; return 1; }
  [ "$KILL_DONE" -eq 0 ] || { out "S7B_KILL_VALIDATION=STOP;CODE=kill_already_done"; return 1; }
  [ "$STOP_ISSUED_OR_DEACT" -eq 1 ] || { out "S7B_KILL_VALIDATION=STOP;CODE=no_stop_job_proof"; return 1; }
  cgroup2_ok || { out "S7B_KILL_VALIDATION=STOP;CODE=not_cgroup2fs"; return 1; }
  read_unit "$G1_UNIT" K || { out "S7B_KILL_VALIDATION=STOP;CODE=g1_unit_unreadable"; return 1; }
  # the unit must be in a stop that is in progress RIGHT NOW (never kill a unit that systemd may restart)
  [ "$K_A" = "deactivating" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=no_stop_in_progress"; return 1; }
  cg="$K_CG"
  [ "$cg" = "$G1_CGPATH" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=cgroup_path_not_pinned"; return 1; }
  read_unit "$LEGACY_UNIT" L || { out "S7B_KILL_VALIDATION=STOP;CODE=legacy_unit_unreadable"; return 1; }
  lcg="$L_CG"
  case "$lcg" in /*) ;; *) out "S7B_KILL_VALIDATION=STOP;CODE=legacy_cgroup_unknown"; return 1 ;; esac
  [ "$L_A" = "active" ] && [ "$L_PID" = "$LEG_PID0" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=legacy_changed"; return 1; }
  case "$cg/" in "$lcg"/*) out "S7B_KILL_VALIDATION=STOP;CODE=legacy_overlap"; return 1 ;; esac
  case "$lcg/" in "$cg"/*) out "S7B_KILL_VALIDATION=STOP;CODE=legacy_overlap"; return 1 ;; esac
  [ "$cg" != "$lcg" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=legacy_overlap"; return 1; }
  d="$CGROOT$cg"
  [ -d "$d" ] && [ ! -L "$d" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=cgroup_dir_invalid"; return 1; }
  cgroup2_ok "$d" || { out "S7B_KILL_VALIDATION=STOP;CODE=target_not_cgroup2fs"; return 1; }
  kids=$(find "$d" -mindepth 1 -maxdepth 1 -type d 2>/dev/null) || { out "S7B_KILL_VALIDATION=STOP;CODE=child_cgroup_scan_failed"; return 1; }
  [ -z "$kids" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=child_cgroups_present"; return 1; }
  [ -f "$d/cgroup.kill" ] && [ ! -L "$d/cgroup.kill" ] && [ -w "$d/cgroup.kill" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=cgroup_kill_unavailable"; return 1; }
  [ -r "$d/cgroup.procs" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=cgroup_procs_unreadable"; return 1; }
  pids=$(cat "$d/cgroup.procs" 2>/dev/null) || { out "S7B_KILL_VALIDATION=STOP;CODE=cgroup_procs_unreadable"; return 1; }
  n=0; KLIST=""
  for pid in $pids; do
    is_uint "$pid" || { out "S7B_KILL_VALIDATION=STOP;CODE=pid_invalid"; return 1; }
    n=$((n+1)); [ "$n" -le "$MAX_KILL_PIDS" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=too_many_pids"; return 1; }
    [ "$pid" != "$LEG_PID0" ] || { out "S7B_KILL_VALIDATION=STOP;CODE=legacy_pid_in_cgroup"; return 1; }
    c=$(cat "$PROCROOT/$pid/comm" 2>/dev/null) || { out "S7B_KILL_VALIDATION=STOP;CODE=comm_unreadable"; return 1; }
    case "$c" in mysqld|python3) ;; *) out "S7B_KILL_VALIDATION=STOP;CODE=foreign_process_in_cgroup"; return 1 ;; esac
    KLIST="${KLIST}S7B_KILL_TARGET_PROC pid=$pid comm=$c"$'\n'
    [ "$pid" = "$K_PID" ] && main_in=1
  done
  [ "$n" -ge 1 ] || { out "S7B_KILL_VALIDATION=STOP;CODE=cgroup_empty"; return 1; }
  if [ "$K_PID" != "0" ]; then [ "$main_in" -eq 1 ] || { out "S7B_KILL_VALIDATION=STOP;CODE=mainpid_not_in_cgroup"; return 1; }; fi
  KCG="$cg"; KPIDS_N="$n"
  out "S7B_KILL_VALIDATION=PASS;PIDS=$n;MAINPID=$K_PID"
  return 0
}
do_kill_confirmed() {  # sets KILL_RESULT; returns 0 only if the kill was written AND the unit ended
  local nonce d t0
  KILL_RESULT="KILL_NOT_DONE"
  nonce=$(od -An -N4 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n'); [ "${#nonce}" -eq 8 ] || { out "S7B_KILL=STOP;CODE=nonce_failed"; return 1; }
  out "S7B_KILL_TARGET unit=$G1_UNIT cgroup=$KCG state=${K_A}/${K_S} mainpid=$K_PID pids=$KPIDS_N"
  [ -z "$KLIST" ] || out "${KLIST%$'\n'}"
  out "S7B_KILL_WARNING SIGKILL of a running database server forces InnoDB crash recovery at the next start; the datadir is preserved, nothing is deleted"
  out "S7B_KILL_CONFIRM_REQUIRED nonce=$nonce wait=${KILL_CONFIRM_SEC}"
  out "S7B_KILL_PROMPT type exactly: KILL $nonce"
  confirm_read || { out "S7B_KILL=NOT_CONFIRMED;REASON=timeout_or_eof"; return 1; }
  [ "$REPLY" = "KILL $nonce" ] || { out "S7B_KILL=NOT_CONFIRMED;REASON=phrase_mismatch"; return 1; }
  # re-validate immediately before the write (TOCTOU), then write once
  kill_validate || { out "S7B_KILL=STOP;CODE=revalidation_failed"; return 1; }
  d="$CGROOT$KCG"
  [ "$INTERRUPTED" -eq 0 ] || { out "S7B_KILL=STOP;CODE=interrupted"; return 1; }
  KILL_DONE=1
  # noclobber is active for the whole script; the existing cgroup control file needs the explicit override operator
  { printf '1' >| "$d/cgroup.kill"; } 2>/dev/null || { KILL_RESULT="KILL_WRITE_FAILED"; out "S7B_KILL=STOP;CODE=write_failed"; return 1; }
  KILL_WRITTEN=1
  out "S7B_KILL=WRITTEN"
  t0=$(now)
  while :; do
    nap 1
    if read_unit "$G1_UNIT" V; then case "$V_A" in inactive|failed) out "S7B_KILL_VERIFY=ended g1=${V_A}/${V_S}"; KILL_RESULT="KILL_WRITTEN_ENDED"; return 0 ;; esac; fi
    [ $(( $(now) - t0 )) -lt "$KILL_VERIFY_SEC" ] || break
  done
  out "S7B_KILL_VERIFY=NOT_ENDED"; KILL_RESULT="KILL_WRITTEN_NOT_ENDED"; return 1
}
containment() {  # containment CODE : the single stop (+ gated, confirmed kill) for a trigger; sets RESULT_CODE
  local code="$1"
  local gst="${G_A}/${G_S}"; [ "$code" != "OBSERVATION_LOST" ] || gst="stale:${gst}"
  out "S7B_TRIGGER code=$code t=$(( $(now) - T_ARM )) obs=$(( $(now) - T_S )) g1=${gst} nr=${G_NR} mem=${MEM_MIB} legacy=${LG_A}:${LG_PID}"
  snapshot "trigger_$code"
  if [ "$MODE" != "contain" ]; then RESULT_CODE="TRIGGER_NO_ACTION;CODE=$code;MODE=observe;G1_NOT_CONTAINED"; return 1; fi
  case "$G_A" in inactive|failed) case "$G_S" in *auto-restart*) ;; *) RESULT_CODE="TRIGGER_UNIT_ALREADY_ENDED;CODE=$code"; return 1 ;; esac ;; esac
  if [ "$code" = "STOP_HANG" ]; then STOP_ISSUED_OR_DEACT=1; STOP_OUTCOME="still_running"
  else
    do_stop_once; local rc=$?
    if [ "$rc" -ne 0 ]; then snapshot "stop_command_failed"; RESULT_CODE="STOP_COMMAND_FAILED;CODE=$code;G1_MAY_STILL_BE_RUNNING"; return 1; fi
    STOP_ISSUED_OR_DEACT=1
    wait_stop
  fi
  if [ "$STOP_OUTCOME" = "stopped" ]; then snapshot "after_stop"; RESULT_CODE="STOPPED;CODE=$code"; return 1; fi
  if [ "$STOP_OUTCOME" = "interrupted" ]; then snapshot "interrupted"; RESULT_CODE="INTERRUPTED_DURING_STOP_NO_KILL;CODE=$code;UNSUPERVISED_STOP_JOB_MAY_BE_QUEUED"; return 1; fi
  snapshot "stop_not_effective"
  out "S7B_STOP_OUTCOME=not_stopped_after_${STOP_WAIT_SEC}s"
  if kill_validate; then
    if do_kill_confirmed; then snapshot "after_kill"; RESULT_CODE="$KILL_RESULT;CODE=$code"
    elif [ "$KILL_WRITTEN" -eq 1 ]; then snapshot "after_kill"; RESULT_CODE="$KILL_RESULT;CODE=$code;KILL_WAS_WRITTEN"
    elif [ "$KILL_DONE" -eq 1 ]; then snapshot "kill_write_failed"; RESULT_CODE="$KILL_RESULT;CODE=$code;KILL_NOT_WRITTEN"
    else snapshot "kill_not_done"; RESULT_CODE="STOP_NOT_EFFECTIVE_NO_KILL;CODE=$code"; fi
  else snapshot "kill_refused"; RESULT_CODE="STOP_NOT_EFFECTIVE_KILL_REFUSED;CODE=$code"; fi
  return 1
}
snapshot() {  # fixed-property evidence only (no process arguments, no log text); goes to the evidence file only
  local tag="$1"
  [ -n "$EVID" ] || return 0
  {
    echo "SNAPSHOT tag=$tag uptime_s=$(now) utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    timeout 5 systemctl show -p ActiveState -p SubState -p Result -p MainPID -p NRestarts -p ControlGroup -p ExecMainStatus -- "$G1_UNIT" 2>/dev/null | head -n 12 | sed 's/^/SNAP_G1 /'
    timeout 5 systemctl show -p ActiveState -p SubState -p MainPID -- "$LEGACY_UNIT" 2>/dev/null | head -n 6 | sed 's/^/SNAP_LEGACY /'
    awk '$1=="MemAvailable:"||$1=="MemTotal:"||$1=="SwapFree:" {print "SNAP_MEM " $1 $2 $3}' "$PROCROOT/meminfo" 2>/dev/null
    awk '$1=="oom_kill" {print "SNAP_VM " $1 "=" $2}' "$PROCROOT/vmstat" 2>/dev/null
  } >> "$EVID" 2>/dev/null
  return 0
}

# ---------------------------------------------------------------------------------- CG-1 handshake (arm phase only)
INTENT_DONE=0; ARM_LIMIT="$ARM_MAX_SEC"
handle_intent() {  # at most ONE intent per run; sets ARM_LIMIT (seconds from T_ARM) only after the ACK commitment was written and read back
  [ "$INTENT_DONE" -eq 0 ] || return 0
  local f="$RUN_DIR/START_INTENT" a="$RUN_DIR/START_ACK" meta line rid want nonce ack el newlim old
  [ -e "$f" ] || [ -L "$f" ] || return 0
  INTENT_DONE=1
  rid="${RUN_DIR##*/wpcg-s7b-}"
  if [ ! -f "$f" ] || [ -L "$f" ]; then out "S7B_INTENT_REJECTED reason=not_regular"; return 0; fi
  meta=$(stat -c '%u %a %s' -- "$f" 2>/dev/null) || { out "S7B_INTENT_REJECTED reason=stat_failed"; return 0; }
  [ "${meta%% *}" = "$ROOT_UID_EXPECT" ] || { out "S7B_INTENT_REJECTED reason=owner"; return 0; }
  case "$meta" in *" 600 "*) ;; *) out "S7B_INTENT_REJECTED reason=mode"; return 0 ;; esac
  IFS= read -r line < "$f" 2>/dev/null || { out "S7B_INTENT_REJECTED reason=unreadable"; return 0; }
  [ "${meta##* }" = "$(( ${#line} + 1 ))" ] || { out "S7B_INTENT_REJECTED reason=not_one_line"; return 0; }
  [[ "$line" =~ ^START_INTENT\ v1\ run=([0-9]{8}T[0-9]{6}Z-[0-9a-f]{12})\ nonce=([0-9a-f]{32})$ ]] || { out "S7B_INTENT_REJECTED reason=format"; return 0; }
  want="${BASH_REMATCH[1]}"; nonce="${BASH_REMATCH[2]}"
  [ "$want" = "$rid" ] || { out "S7B_INTENT_REJECTED reason=other_run"; return 0; }
  el=$(( $(now) - T_ARM )); newlim=$(( el + INTENT_WAIT_SEC )); old="$ARM_LIMIT"
  [ "$newlim" -gt "$ARM_LIMIT" ] || newlim="$ARM_LIMIT"
  ARM_LIMIT="$newlim"
  ack="START_ACK v1 run=$rid nonce=$nonce pid=$$ w=$INTENT_WAIT_SEC"
  if { ( umask 077; printf '%s\n' "$ack" > "$a" ) 2>/dev/null; } && [ "$(cat "$a" 2>/dev/null)" = "$ack" ]; then
    out "S7B_INTENT_ACK run=$rid nonce=$nonce w=$INTENT_WAIT_SEC arm_limit=${ARM_LIMIT}s"
  else
    ARM_LIMIT="$old"; out "S7B_INTENT_ACK_FAILED reason=write_or_readback"
  fi
  return 0
}

# ------------------------------------------------------------------------------------------- main
finish() {  # finish EXITCODE : result line, final fresh read, exit
  emit_result "$RESULT_CODE"; final_line "$1"; exit "$1"
}
main() {
  trap 'INTERRUPTED=1' INT TERM HUP QUIT PIPE
  trap on_exit EXIT
  INTERRUPTED=0; RESULT_CODE=""; STOP_ISSUED_OR_DEACT=0; STOP_OUTCOME=""
  # self protection: absolute path, regular file, root-owned, not group/other-writable
  case "$0" in /*) ;; *) die self_not_absolute ;; esac
  [ -f "$0" ] && [ ! -L "$0" ] || die self_not_file
  [ "$(stat -c '%u' -- "$0" 2>/dev/null)" = "$ROOT_UID_EXPECT" ] || die self_owner
  local sm; sm=$(stat -c '%a' -- "$0" 2>/dev/null)
  case "$sm" in [0-7]|[0-7][0-7]|[0-7][0-7][0-7]|[0-7][0-7][0-7][0-7]) ;; *) die self_mode ;; esac
  [ $(( 8#$sm & 8#022 )) -eq 0 ] || die self_writable
  [ "$(id -u)" = "$ROOT_UID_EXPECT" ] || die not_root
  # arguments: exactly --instance X --mode Y
  [ "$#" -eq 4 ] || die usage
  INSTANCE=""; MODE=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --instance) [ -z "$INSTANCE" ] || die usage; INSTANCE="${2:-}"; shift 2 ;;
      --mode)     [ -z "$MODE" ] || die usage; MODE="${2:-}"; shift 2 ;;
      *) die usage ;;
    esac
  done
  case "$INSTANCE" in
    production) G1_UNIT="mysql@wpcaregrid-production.service"; G1_CGPATH="/system.slice/system-mysql.slice/mysql@wpcaregrid-production.service" ;;
    restore)    G1_UNIT="mysql@wpcaregrid-production-restore.service"; G1_CGPATH="/system.slice/system-mysql.slice/mysql@wpcaregrid-production-restore.service" ;;
    *) die usage ;;
  esac
  case "$MODE" in observe|contain) ;; *) die usage ;; esac
  for t in systemctl timeout awk stat od tr sleep date cat flock head sed chmod mkdir id find; do command -v "$t" >/dev/null 2>&1 || die missing_tool; done
  if [ "$MODE" = "contain" ]; then is_tty || die contain_needs_tty; fi
  { exec 9>>"$LOCKFILE"; } 2>/dev/null || die lock_unavailable
  flock -n 9 || die already_running
  # arm preconditions (fail closed)
  read_unit "$G1_UNIT" G || die arm_g1_unreadable
  [ "$G_L" = "loaded" ] || die arm_g1_not_loaded
  [ "$G_A" = "inactive" ] || die arm_g1_not_inactive
  [ "$G_NR" = "0" ] || die arm_g1_nrestarts_nonzero
  read_unit "$LEGACY_UNIT" LG || die arm_legacy_unreadable
  [ "$LG_L" = "loaded" ] || die arm_legacy_not_loaded
  [ "$LG_A" = "active" ] || die arm_legacy_not_active
  [ "$LG_PID" != "0" ] || die arm_legacy_mainpid_zero
  LEG_PID0="$LG_PID"
  read_mem_mib || die arm_mem_unreadable
  [ "$MEM_MIB" -ge "$MEM_TRIGGER_MIB" ] || die arm_mem_below_trigger
  read_oomkill || die arm_oomkill_unreadable
  OOMK0="$OOMK"
  [ -d "$RUN_PARENT" ] && [ ! -L "$RUN_PARENT" ] || die run_parent_invalid
  local rid; rid="$(date -u +%Y%m%dT%H%M%SZ)-$(od -An -N6 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')"
  [ "${#rid}" -eq 29 ] || die run_id_failed
  RUN_DIR="$RUN_PARENT/wpcg-s7b-$rid"
  mkdir -m 0700 -- "$RUN_DIR" 2>/dev/null || die run_dir_failed
  EVID="$RUN_DIR/evidence.txt"
  { : > "$EVID"; } 2>/dev/null || die evidence_failed
  chmod 0600 -- "$EVID" 2>/dev/null
  T_ARM=$(now); T_S=$T_ARM
  out "S7B_ARMED version=$WRAPPER_VERSION instance=$INSTANCE mode=$MODE unit=$G1_UNIT run_dir=$RUN_DIR"
  out "S7B_BASELINE legacy=${LG_A}:${LEG_PID0} mem_mib=$MEM_MIB oom_kill=$OOMK0 sample=${SAMPLE_SEC}s window=${WINDOW_SEC}s(from first observation) hang_cutoff=${HANG_CUTOFF_SEC}s(from first observation) stop_wait=${STOP_WAIT_SEC}s mem_trigger=${MEM_TRIGGER_MIB}MiB"
  snapshot arm
  # ---- phase 1: wait for the start (the unit leaving `inactive`)
  local n=0 apolls=0 started=0 pfails=0 seen_exit=0 deact_since=0 deact_n=0 flags="" trig="" hist="" f=0 ones=0 el rd first=1
  while [ "$started" -eq 0 ]; do
    [ "$INTERRUPTED" -eq 0 ] || { snapshot interrupted; RESULT_CODE="INTERRUPTED;ACTION=NONE;PHASE=arm;G1_NOT_SUPERVISED"; emit_result "$RESULT_CODE"; final_line 130; exit 130; }
    handle_intent
    if [ $(( $(now) - T_ARM )) -ge "$ARM_LIMIT" ] && [ "$apolls" -ge $(( ARM_LIMIT / SAMPLE_SEC )) ]; then RESULT_CODE="NO_START_OBSERVED;WAITED=${ARM_LIMIT}s;ACTION=NONE;G1_NOT_SUPERVISED"; snapshot no_start; finish 4; fi
    nap "$SAMPLE_SEC"; apolls=$((apolls+1))
    if read_unit "$G1_UNIT" G; then
      pfails=0
      case "$G_A" in inactive) ;; *) started=1; T_S=$(now) ;; esac
    else
      pfails=$((pfails+1)); out "S7B_ARM_POLL READ_FAILED consecutive=$pfails"
      if [ "$pfails" -ge "$OBS_LOST_SAMPLES" ]; then RESULT_CODE="ARM_OBSERVATION_LOST;ACTION=NONE;G1_NOT_SUPERVISED"; snapshot arm_lost; finish 4; fi
    fi
  done
  out "S7B_START_OBSERVED t=$(( T_S - T_ARM )) g1=${G_A}/${G_S}"
  # ---- phase 2: supervision window (the first sample is evaluated immediately)
  while :; do
    [ "$INTERRUPTED" -eq 0 ] || { snapshot interrupted; RESULT_CODE="INTERRUPTED;ACTION=NONE;G1=${G_A}/${G_S};UNSUPERVISED_STOP_JOB_MAY_BE_QUEUED=$G1_STOP_ISSUED"; emit_result "$RESULT_CODE"; final_line 130; exit 130; }
    if [ "$first" -eq 1 ]; then first=0; else nap "$SAMPLE_SEC"; fi
    n=$((n+1)); el=$(( $(now) - T_S )); rd=0
    if read_unit "$G1_UNIT" G && read_unit "$LEGACY_UNIT" LG && read_mem_mib && read_oomkill; then rd=1; f=0; else f=1; fi
    hist="${hist}${f}"; [ "${#hist}" -le 12 ] || hist="${hist: -12}"; ones="${hist//0/}"; ones="${#ones}"
    if [ "$rd" -eq 1 ]; then
      out "S7B_SAMPLE n=$n t=$(( $(now) - T_ARM )) obs=$el g1=${G_A}/${G_S} main=${G_PID} nr=${G_NR} mem=${MEM_MIB} oomk=${OOMK} legacy=${LG_A}:${LG_PID}"
    else
      out "S7B_SAMPLE n=$n t=$(( $(now) - T_ARM )) obs=$el READ_FAILED failures_in_last_12=$ones"
    fi
    trig=""
    if [ "$rd" -eq 1 ]; then
      flags=""
      [ "$LG_A" = "active" ] && [ "$LG_PID" = "$LEG_PID0" ] || flags="LEGACY_CHANGED"
      [ -n "$flags" ] || { [ "$OOMK" -le "$OOMK0" ] || flags="OOM_KILL"; }
      [ -n "$flags" ] || { [ "$MEM_MIB" -ge "$MEM_TRIGGER_MIB" ] || flags="MEMORY_LOW"; }
      case "$G_A" in
        activating|active|deactivating) ;;
        inactive|failed)
          if [ -n "$flags" ]; then out "S7B_TRIGGER code=$flags t=$(( $(now) - T_ARM )) obs=$el g1=${G_A}/${G_S} (unit already ended)"; fi
          case "$G_S" in *auto-restart*) trig="RESTART_LOOP" ;; esac
          if [ -z "$trig" ]; then
            snapshot "unit_${G_A}"
            if [ "$G_A" = "failed" ] || [ "$G_NR" -gt 0 ]; then RESULT_CODE="UNIT_FAILED;STATE=${G_A}/${G_S};NRESTARTS=${G_NR};RESULT=${G_R};ACTION=NONE;G1_NOT_RUNNING"; [ "$G_NR" -gt 0 ] && RESULT_CODE="RESTART_LOOP_ENDED_FAILED;STATE=${G_A}/${G_S};NRESTARTS=${G_NR};RESULT=${G_R};ACTION=NONE;G1_NOT_RUNNING"
            else RESULT_CODE="UNIT_INACTIVE_AFTER_START;STATE=${G_A}/${G_S};NRESTARTS=${G_NR};ACTION=NONE;G1_NOT_RUNNING"; fi
            [ -z "$flags" ] || RESULT_CODE="${RESULT_CODE};ALSO=${flags}"
            break
          fi ;;
        *) trig="UNKNOWN_STATE" ;;
      esac
      if [ -z "$trig" ]; then
        trig="$flags"
        if [ -z "$trig" ]; then
          case "$G_S" in *auto-restart*) trig="RESTART_LOOP" ;; esac
          [ -n "$trig" ] || { [ "$G_NR" -gt 0 ] && trig="RESTART_LOOP"; }
          [ -n "$trig" ] || { [ "$seen_exit" -eq 1 ] && [ "$G_A" = "activating" ] && trig="RESTART_LOOP"; }
        fi
        case "$G_A/$G_S" in deactivating/*|*auto-restart*) seen_exit=1 ;; esac
        if [ -z "$trig" ] && [ "$G_A" = "activating" ] && [ "$el" -ge "$HANG_CUTOFF_SEC" ] && [ "$n" -ge $(( HANG_CUTOFF_SEC / SAMPLE_SEC )) ]; then trig="START_HANG"; fi
        if [ -z "$trig" ]; then
          if [ "$G_A" = "deactivating" ]; then
            [ "$deact_since" -ne 0 ] || { deact_since=$(now); deact_n=0; }
            deact_n=$((deact_n+1))
            if [ $(( $(now) - deact_since )) -ge "$STOP_WAIT_SEC" ] && [ "$deact_n" -ge $(( STOP_WAIT_SEC / SAMPLE_SEC )) ]; then trig="STOP_HANG"; fi
          else deact_since=0; deact_n=0; fi
        fi
      fi
    fi
    if [ -z "$trig" ]; then
      if [ "$rd" -eq 0 ] && { [ "$f" -eq 1 ] && [ "$ones" -ge "$OBS_LOST_SAMPLES" ]; }; then trig="OBSERVATION_LOST"; fi
    fi
    if [ -n "$trig" ]; then containment "$trig"; break; fi
    if [ "$el" -ge "$WINDOW_SEC" ] && [ "$n" -ge $(( WINDOW_SEC / SAMPLE_SEC )) ]; then
      if [ "$rd" -ne 1 ]; then snapshot window_unverified; RESULT_CODE="WINDOW_END_UNVERIFIED;ACTION=NONE;G1_STATE_UNKNOWN"; break; fi
      if [ "$G_A" = "active" ] && [ "$G_S" = "running" ]; then RESULT_CODE="WINDOW_COMPLETE;G1=${G_A}/${G_S};NRESTARTS=${G_NR};ACTION=NONE"
      else snapshot window_end_state; RESULT_CODE="WINDOW_END_STATE_NOT_ACTIVE;G1=${G_A}/${G_S};NRESTARTS=${G_NR};ACTION=NONE"; fi
      break
    fi
  done
  case "$RESULT_CODE" in WINDOW_COMPLETE*) finish 0 ;; *) finish 1 ;; esac
}
main "$@"
exit 3
