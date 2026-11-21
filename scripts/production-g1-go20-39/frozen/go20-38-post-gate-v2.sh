#!/bin/bash
# GO-20.38 POST-START reviewer-side gate (v2.3-lite).
# Runs on the REVIEWER side on the pasted text; touches NO host.
# Usage: go20-38-post-gate.sh out.txt <expected-script-sha256> [expected-threshold-set]
# Checks: structure, nonce equality, line grammar allowlist, leak patterns, void markers,
#         arithmetic self-consistency, unknown line prefix, raw error-log text.
# NO environment switches exist that weaken it.
# Outcomes: STOP (exit 1), ESCALATE (exit 2), NO_BLOCKER_FOUND (exit 0).
# NO_BLOCKER_FOUND is explicitly NOT a pass.

set +e
export LC_ALL=C

f=${1:?out.txt path}
want=${2:?expected script sha256}
want_thresh=${3:-thresholds.v1}

if [ $# -lt 2 ] || [ $# -gt 3 ]; then
  echo "usage: $0 out.txt <expected-script-sha256> [expected-threshold-set]"
  exit 64
fi

if [ ! -f "$f" ] || [ -L "$f" ]; then
  echo "GATE=STOP (not a regular file)"
  exit 1
fi

stop=0
stp() { echo "HARD_STOP: $1"; stop=1; }

esc=0
E() { echo "ESCALATE: $1"; esc=1; }

# Sanitise printed output from untrusted file
S() { printf '%s' "$1" | tr -cd '[:print:]' | cut -c1-80; }

# Canonical decimal non-negative integer validation
isn() {
  case "$1" in
    ''|*[!0-9]*) return 1 ;;
  esac
  case "$1" in
    0) ;;
    0*) return 1 ;;
    *) ;;
  esac
  [ "${#1}" -le 15 ] || return 1
  return 0
}

echo "lines: $(wc -l < "$f")  sha256(out): $(sha256sum "$f" | cut -c1-16)"

# ---- 0 Character Set: printable ASCII and newlines only ----
np=$(grep -n '[^[:print:]]' "$f" | cut -d: -f1 | head -n 10 | tr '\n' ' ')
[ -z "$np" ] || stp "non-printable characters (lines $np)"

# Backtick leak check (breaks markdown fences)
bt=$(grep -n '`' "$f" | cut -d: -f1 | head -n 10 | tr '\n' ' ')
[ -z "$bt" ] || stp "backtick characters present (lines $bt)"

# ---- 1 Structure & Nonce Equality ----
hdr=$(head -n1 "$f")
tail_line=$(tail -n1 "$f")

if [[ ! "$hdr" =~ ^WPCG_BLOCK_BEGIN\ nonce=([0-9a-f]{32})\ script_sha256=([0-9a-f]{64})\ threshold_set=([A-Za-z0-9_.-]+)$ ]]; then
  stp "first line must match WPCG_BLOCK_BEGIN format: $(S "$hdr")"
fi
nonce_begin="${BASH_REMATCH[1]}"
script_hash_begin="${BASH_REMATCH[2]}"
threshold_set_begin="${BASH_REMATCH[3]}"

if [[ ! "$tail_line" =~ ^WPCG_BLOCK_END\ nonce=([0-9a-f]{32})\ lines=([0-9]+)\ body_sha256=([0-9a-f]{64})$ ]]; then
  stp "last line must match WPCG_BLOCK_END format: $(S "$tail_line")"
fi
nonce_end="${BASH_REMATCH[1]}"
lines_end="${BASH_REMATCH[2]}"
body_hash_end="${BASH_REMATCH[3]}"

if [ -n "$nonce_begin" ] && [ "$nonce_begin" != "$nonce_end" ]; then
  stp "nonce mismatch: begin=$nonce_begin vs end=$nonce_end"
fi

if [ "$(grep -c '^WPCG_BLOCK_BEGIN' "$f")" -ne 1 ]; then
  stp "WPCG_BLOCK_BEGIN count != 1"
fi

if [ "$(grep -c '^WPCG_BLOCK_END' "$f")" -ne 1 ]; then
  stp "WPCG_BLOCK_END count != 1"
fi

# Verify no forged WPCG_BLOCK_ marker in body
body_markers=$(sed '1d;$d' "$f" | grep -c '^WPCG_BLOCK_')
if [ "$body_markers" -ne 0 ]; then
  stp "spoofed WPCG_BLOCK_ marker in body"
fi

# Verify body line count
body_lines_actual=$(sed '1d;$d' "$f" | wc -l)
if [ "$body_lines_actual" -ne "${lines_end:-0}" ]; then
  stp "body line count mismatch: actual=$body_lines_actual vs end=$lines_end"
fi

# Verify body SHA-256
body_hash_actual=$(sed '1d;$d' "$f" | sha256sum | cut -d' ' -f1)
if [ "$body_hash_actual" != "$body_hash_end" ]; then
  stp "body sha256 mismatch: actual=$body_hash_actual vs end=$body_hash_end"
fi

# Section markers verification
for s in 0 1 2 3 4 5 6 7; do
  [ "$(grep -c "^SEC $s BEGIN " "$f")" -eq 1 ] || stp "SEC $s BEGIN count != 1"
  [ "$(grep -c "^SEC $s END failed=0$" "$f")" -eq 1 ] || stp "SEC $s END missing or failed!=0"
done

# ---- 2 Script Identity & Threshold Set ----
if [ "$script_hash_begin" = "$want" ]; then
  echo "SCRIPT_HASH=MATCH"
else
  stp "script hash $(S "$script_hash_begin") != expected $(S "$want")"
fi

# Verify threshold_set matches expected
if [ "$threshold_set_begin" = "$want_thresh" ]; then
  echo "THRESHOLD_SET=MATCH"
else
  stp "threshold_set mismatch: actual=$(S "$threshold_set_begin") vs expected=$(S "$want_thresh")"
fi

# ---- 3 Line Grammar (Allowlist) ----
ALLOW='WPCG_BLOCK_BEGIN WPCG_BLOCK_END SEC POST0_HELPER_RC POST0_HELPER_STATUS POST0_T1 POST0_UNIT_ActiveState POST0_UNIT_SubState POST0_UNIT_Result POST0_UNIT_MainPID POST0_UNIT_NRestarts POST0_UNIT_ExecMainCode POST0_UNIT_ExecMainStatus POST0_UNIT_ActiveEnterTimestamp POST0_UNIT_InactiveEnterTimestamp POST0_UNIT_StateChangeTimestamp POST0_UNIT_MemoryCurrent POST0_UNIT_TasksCurrent POST0_SYSTEMD_NJobs POST0_SYSTEMD_NFailedUnits LIFECYCLE LIFECYCLE_TOTAL JOURNAL_UNTRUSTED POST1_RC PROC_COMM PROC_ATTR PROC_CMDLINE_GROUP_SUFFIX LISTENER RUN_MYSQLD_STAT G1_SOCK G1_PID_FILE G1_PID_MATCH G1_LOCK_FILE LEGACY_ACTIVE_STATE LEGACY_MAIN_PID LEGACY_NRESTARTS LEGACY_SOCK LEGACY_PID_FILE LEGACY_PID_MATCH VENDOR_PROFILE_SHA256 POST2_RC LOGEV LOGEV_TOTAL LOGEV_SENSITIVE_MSG LOGEV_DROPPED_SENSITIVE LOGEV_CODE READY_EVENT_COUNT READY_CODE_UNCONFIRMED LOGEV_UNPARSED LOGEV_ERROR_COUNT LOGEV_ERRNO LOGEV=UNAVAILABLE DENIAL DENIAL_TOTAL DENIAL_CHANNEL DENIAL_EVIDENCE STATUS STATUS_TOTAL POST4_RC DATADIR_ENTRIES_TOTAL CTRL_CHAR_NAMES NEW_ENTRIES UNKNOWN_NEW_ENTRIES AUTO_CNF_MTIME_CHANGED PEM_MTIME_CHANGED MYSQLD_AUTO_CNF_PRESENT SYMLINKS_PRESENT OWNMODE_DRIFT FINGERPRINT_META_SHA256 FINGERPRINT_SHAPE_SHA256 BASELINE_FINGERPRINT_META FINGERPRINT_META_EQUAL LOG_FILE_STAT POST5_RC MEM_TOTAL_MIB MEM_AVAILABLE_MIB SWAP_TOTAL_MIB SWAP_FREE_MIB COMMITTED_AS_MIB COMMIT_LIMIT_MIB VM_OVERCOMMIT_MEMORY VM_SWAPPINESS PSI_MEMORY_SOME_AVG10 PSI_MEMORY_SOME_AVG60 PSI_MEMORY_FULL_AVG10 PSI_MEMORY_FULL_AVG60 PSI_IO_SOME_AVG10 PSI_IO_SOME_AVG60 PSI_IO_FULL_AVG10 PSI_IO_FULL_AVG60 PSI_CPU_SOME_AVG10 PSI_CPU_SOME_AVG60 PSI_CPU_FULL_AVG10 PSI_CPU_FULL_AVG60 PSI_MEMORY PSI_IO PSI_CPU LOADAVG_1 LOADAVG_5 LOADAVG_15 NPROC VMSTAT_OOM_KILL JOURNAL_OOM_COUNT TOP_CONSUMER LEGACY_VMRSS_MIB LEGACY_VMSWAP_MIB LEGACY_MEMORY_CURRENT AIO_NR AIO_MAX_NR FILE_NR FILE_MAX RESOLVED_SETTING G1_VMRSS_MIB G1_MEMORY_CURRENT POST6_RC ARTEFACT_HASH PROFILE_MODE DAEMON_RELOAD_NEEDED POST7_RC FAILED_READS'

unk=$(awk -v A="$ALLOW" 'BEGIN{n=split(A,a," "); for(i=1;i<=n;i++) ok[a[i]]=1}
{ t=$0; sub(/[ =].*$/,"",t)
  if (t=="") { print NR; next }
  if (!(t in ok)) print NR }' "$f" | head -n 10)

if [ -n "$unk" ]; then
  for n in $unk; do
    echo "UNKNOWN_LINE n=$n text=$(sed -n "${n}p" "$f" | S "$(cat)")"
  done
  stp "unknown line prefix (lines $(echo $unk | tr '\n' ' '))"
fi

# ---- 4 Leaks & Raw Error-Log Text Checks ----
# Leaks check
LK='password|passwd|token|secret|PWD=|_KEY=|_PASS=|BEGIN (RSA|OPENSSH|PRIVATE)|-----|COMMAND=|[0-9]{1,3}(\.[0-9]{1,3}){3}|CANARY|<path>|<relpath>|<ip>|<ip6>|<host>|\.(com|net|org|hu|io|eu|dev|app|co)([^a-z]|$)|(^|[^A-Za-z0-9_])/[A-Za-z]'
lk=$(grep -nE "$LK" "$f" | cut -d: -f1 | head -n 20 | tr '\n' ' ')
if [ -n "$lk" ]; then
  stp "leak pattern hit (lines $lk)"
fi

# Raw error-log text check:
# 1. Any bracketed log components like [Server], [InnoDB], [Note], [Warning], [Error] outside approved keys
raw_bracket=$(grep -nE '\[(Server|InnoDB|Note|Warning|Error|MY-[0-9]{6})\]' "$f" | cut -d: -f1 | head -n 10 | tr '\n' ' ')
if [ -n "$raw_bracket" ]; then
  stp "raw error log bracket leaked into output (lines $raw_bracket)"
fi

# 2. LOGEV lines must match strict grammar:
# LOGEV t=<ts> lvl=<lvl> code=MY-xxxxxx sub=<subid>
bad_logev=$(grep '^LOGEV ' "$f" | grep -nvE '^LOGEV t=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}(Z|[+-][0-9]{2}:[0-9]{2}) lvl=(System|Note|Warning|Error) code=MY-[0-9]{6} sub=[A-Za-z0-9_]{1,24}$')
if [ -n "$bad_logev" ]; then
  stp "LOGEV line does not conform to strict prefix grammar: $(S "$bad_logev")"
fi

# ---- 5 Void Markers (Evidence Incomplete or Corrupt) ----
VD='READ_FAILED|VOID|UNREADABLE|=MISSING|=MISMATCH|DATADIR_[A-Z]*=ABSENT|THRESHOLD_EPOCH|MYSQL_USER_UNRESOLVED|NOT_READ|LOGEV=UNAVAILABLE'
vd=$(grep -nE "$VD" "$f" | cut -d: -f1 | head -n 20 | tr '\n' ' ')
if [ -n "$vd" ]; then
  stp "void marker present (lines $vd)"
fi

# FAILED_READS must be 0
fr_line=$(grep '^FAILED_READS ' "$f" | head -n1)
fr_val=$(echo "$fr_line" | sed 's/.*n=//')
if [ "$fr_val" != "0" ]; then
  stp "FAILED_READS n=$fr_val != 0"
fi

# CTRL_CHAR_NAMES must be 0
cc_line=$(grep '^CTRL_CHAR_NAMES ' "$f" | head -n1)
cc_val=$(echo "$cc_line" | sed 's/.*n=//')
if [ -n "$cc_val" ] && [ "$cc_val" != "0" ]; then
  stp "CTRL_CHAR_NAMES n=$cc_val != 0 (control character detected in datadir filenames)"
fi

# ---- 6 Arithmetic Self-Consistency ----
SUM() { awk '{s+=$1} END{printf "%d\n", s}'; }

# LOGEV parsed sum == LOGEV_TOTAL parsed
logev_parsed_line=$(grep '^LOGEV_TOTAL ' "$f" | head -n1)
tot_parsed=$(echo "$logev_parsed_line" | grep -oE 'parsed=[0-9]+' | cut -d= -f2)
tot_unparsed=$(echo "$logev_parsed_line" | grep -oE 'unparsed=[0-9]+' | cut -d= -f2)

if isn "$tot_parsed"; then
  code_sum=$(grep '^LOGEV_CODE ' "$f" | sed 's/.* n=//' | SUM)
  if [ "$code_sum" -ne "$tot_parsed" ]; then
    stp "arithmetic mismatch: LOGEV_CODE sum ($code_sum) != LOGEV_TOTAL parsed ($tot_parsed)"
  fi
fi

if isn "$tot_unparsed"; then
  unparsed_sum=$(grep '^LOGEV_UNPARSED ' "$f" | sed 's/.* n=//' | SUM)
  if [ "$unparsed_sum" -ne "$tot_unparsed" ]; then
    stp "arithmetic mismatch: LOGEV_UNPARSED sum ($unparsed_sum) != LOGEV_TOTAL unparsed ($tot_unparsed)"
  fi
fi

# READY_EVENT_COUNT == MY-010931 code count
ready_cnt=$(grep '^READY_EVENT_COUNT ' "$f" | sed 's/.*n=//' | head -n1)
my_ready_cnt=$(grep '^LOGEV_CODE code=MY-010931 ' "$f" | sed 's/.*n=//' | head -n1)
my_ready_cnt=${my_ready_cnt:-0}
if isn "$ready_cnt"; then
  if [ "$ready_cnt" -ne "$my_ready_cnt" ]; then
    stp "READY_EVENT_COUNT ($ready_cnt) != LOGEV_CODE MY-010931 ($my_ready_cnt)"
  fi
fi

# Cross-check error lines arithmetic
err_lines=$(grep '^LOGEV ' "$f" | grep -c ' lvl=Error ')
err_cnt=$(grep '^LOGEV_ERROR_COUNT ' "$f" | sed 's/.*n=//' | head -n1)
if isn "${err_cnt:-0}"; then
  if [ "${err_cnt:-0}" -ne "$err_lines" ]; then
    stp "arithmetic mismatch: LOGEV_ERROR_COUNT (${err_cnt:-0}) != lvl=Error lines ($err_lines)"
  fi
fi

# ---- 7 Escalation Triggers ----
# 1. Error events in error log
err_cnt=$(grep '^LOGEV_ERROR_COUNT ' "$f" | sed 's/.*n=//' | head -n1)
if [ -n "$err_cnt" ] && [ "$err_cnt" -gt 0 ]; then
  E "LOGEV contains $err_cnt Error events"
fi

# 2. Ready event unconfirmed
if grep -q '^READY_CODE_UNCONFIRMED' "$f" || [ "${ready_cnt:-0}" -ne 1 ]; then
  E "READY_EVENT_COUNT is not 1 (count=${ready_cnt:-0})"
fi

# 3. Datadir unknown new entries
unk_dd=$(grep '^UNKNOWN_NEW_ENTRIES ' "$f" | sed 's/.*n=//' | head -n1)
if [ -n "$unk_dd" ] && [ "$unk_dd" -gt 0 ]; then
  E "UNKNOWN_NEW_ENTRIES in datadir: $unk_dd"
fi

# 4. Datadir auto.cnf or pem mtime changed
if grep -qE '^AUTO_CNF_MTIME_CHANGED=(yes|UNKNOWN)' "$f"; then
  E "auto.cnf mtime changed since start or unknown (UUID drift check invalid)"
fi
if grep -qE '^PEM_MTIME_CHANGED=(yes|UNKNOWN)' "$f"; then
  E "TLS pem file mtime changed since start or unknown"
fi
if grep -q '^MYSQLD_AUTO_CNF_PRESENT=yes' "$f"; then
  E "mysqld-auto.cnf created in datadir"
fi
sym_cnt=$(grep '^SYMLINKS_PRESENT ' "$f" | sed 's/.*n=//' | head -n1)
if [ -n "$sym_cnt" ] && [ "$sym_cnt" -gt 0 ]; then
  E "symlinks present in datadir: $sym_cnt"
fi
drift_cnt=$(grep '^OWNMODE_DRIFT ' "$f" | sed 's/.*n=//' | head -n1)
if [ -n "$drift_cnt" ] && [ "$drift_cnt" -gt 0 ]; then
  E "owner/mode drift in datadir: $drift_cnt"
fi

# 5. Process & unit state checks
if grep -qE '^PROC_COMM=(other|none)' "$f"; then
  E "process comm is not mysqld"
fi
if grep -qE '^PROC_ATTR=(other|none)' "$f"; then
  E "process AppArmor profile is not wpcg_prod_enforce"
fi
if grep -q '^G1_PID_MATCH=no' "$f"; then
  E "G1 pid file does not match MainPID"
fi
if grep -q '^LEGACY_PID_MATCH=no' "$f"; then
  E "legacy pid file does not match legacy MainPID"
fi

# 6. Profile & daemon reload checks
if grep -q '^PROFILE_MODE profile=wpcg_prod mode=other' "$f"; then
  E "wpcaregrid-mysql-production profile not in enforce mode"
fi
if grep -q '^PROFILE_MODE profile=wpcg_restore mode=other' "$f"; then
  E "wpcaregrid-mysql-production-restore profile not in enforce mode"
fi
if grep -q '^DAEMON_RELOAD_NEEDED unit=g1_prod need_reload=yes' "$f"; then
  E "NeedDaemonReload=yes on g1_prod"
fi
if grep -q '^DAEMON_RELOAD_NEEDED unit=g1_restore need_reload=yes' "$f"; then
  E "NeedDaemonReload=yes on g1_restore"
fi

# 7. Artefact hash mismatch
if grep -q '^ARTEFACT_HASH .* pin=MISMATCH' "$f"; then
  E "pinned artefact hash mismatch detected"
fi

# ---- 8 Final Decision ----
if [ $stop -ne 0 ]; then
  echo "GATE=STOP"
  exit 1
elif [ $esc -ne 0 ]; then
  echo "GATE=ESCALATE (content is valid but indicates blockers or anomalies; reviewer must read ESCALATE lines; evidence is NOT interpreted or passed)"
  exit 2
else
  echo "GATE=NO_BLOCKER_FOUND (structure, grammar, hash, leak, void and consistency checks only; evidence is NOT interpreted or passed; NO_BLOCKER_FOUND is explicitly not a pass)"
  exit 0
fi
