#!/bin/bash
# GO-20.38 Pre-Start Reviewer Gate v4
# Lineage: go20-37-gate-v2.2.sh -> go20-38-pre-gate.sh (v1) -> go20-38-pre-gate-v2.sh (rejected: see CHANGES-v3) -> gate v3 -> v4
# v4 = v3 (every deny rule kept verbatim) + a POSITIVE SCHEMA (sections 9a-9c): the body must be exactly the ordered line
#      set of a healthy run (each key the exact number of times, typed values, no extra / duplicated / missing /
#      re-ordered line, LOGSTATE and PRE_OUTCOME included) + cross-line range re-checks of the threshold decisions.
#      Diagnostics never echo host text beyond a <=16 char [A-Za-z0-9_] token. All-zero nonce rejected.
# v3 = the COMPLETE v1 rule set (nonce/hash/threshold, line allow-list, leak patterns, void markers,
#      no environment switch) + Moody B-PRE-1 rules + IPv6/token-grammar rejection + Hermione allow-list
#      additions + a runtime rule-registry self-check (section 9) so a future edit cannot silently drop one.
# Operating Discipline: Reviewer-side verification of pasted output; touches NO host, writes NO file.
# Exit codes: 0 = NO_BLOCKER_FOUND, 1 = STOP, 2 = ESCALATE, 64 = usage error,
#             65 = GATE_SELF_CHECK failure (a required rule was not executed: the gate itself is defective).
# There is NO environment switch that weakens it: the only inputs are the three positional arguments.

set +e; export LC_ALL=C

# ---- Rule registry (grep-able). Every id below MUST be executed (R <id>) on every run, see section 9. ----
GATE_RULE_SET="CHARSET ENVELOPE_NONCE BODY_LINES BODY_SHA SECTION_COUNT SCRIPT_SHA THRESHOLD_SHA THRESHOLD_FIRST_LINE ALLOWLIST_GRAMMAR TOKEN_GRAMMAR LEAK_PATTERNS IPV6_TOKEN VOID_MARKERS FAILED_READS_ZERO PRE_OUTCOME_NOT_STOP PRE_OUTCOME_ONCE PRE_OUTCOME_EQUALS_NO_BLOCKER_FOUND REQUIRED_LINES COUNT_LINES_CANONICAL BOOT_MATCH_ESCALATE HRT_STOP HRS_STOP OOM_STOP CAPACITY_STOP NJOBS_ESCALATE NFAILEDUNITS_STOP REBOOT_REQUIRED_STOP PACKAGE_PROCESS_STOP TIMER_WINDOW_STOP LOGROTATE_G1_STOP SECTION_STATUS_STOP PORT_TARGET_STOP PORT_3306_STOP RUN_MYSQLD_STOP LEGACY_PID_STOP LEGACY_SOCKET_STOP G1_SOCKET_STOP ALLOW_HAS_LEGACY_NRESTARTS ALLOW_HAS_LEGACY_ACTIVE_ENTER_TIMESTAMP ALLOW_HAS_RESOLVED_SETTING ALLOW_HAS_PRE_OUTCOME NONCE_NONZERO THRESHOLD_ARG SCHEMA_SLOTS SCHEMA_RANGES SCHEMA_SELF_CHECK"
RAN=" "
R() { RAN="$RAN$1 "; }

if [ $# -lt 2 ] || [ $# -gt 3 ]; then
  echo "usage: $0 out.txt <expected-script-sha256> [expected-thresholds-sha256]"
  exit 64
fi

f=$1
want_script=$2
# v4 NB-12(c): the threshold hash is a compiled-in constant of this gate; an optional 3rd argument is only accepted
# if it is identical (it can no longer re-point the gate at a different threshold set).
THRESHOLD_CONST="d5a029bda6f51c97d73f99180a864307b8a95ddbd8a4ffd4780b10565bf57260"
want_thresholds=$THRESHOLD_CONST

if [ ! -f "$f" ] || [ -L "$f" ]; then
  echo "GATE=STOP (not a regular file)"
  exit 1
fi

stop=0
stp() {
  echo "HARD_STOP: $1"
  stop=1
}

esc=0
E() {
  echo "ESCALATE: $1"
  esc=1
}

# Untrusted value sanitizer for gate diagnostic output (v4): [A-Za-z0-9_] only, max 16 chars - a short token, never host text.
S() {
  printf '%s' "$1" | tr -cd 'A-Za-z0-9_' | cut -c1-16
}

# Strict decimal non-negative integer validator
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

# ---- 0. Character Set: Printable ASCII and newlines only ----
R CHARSET
np=$(grep -n '[^[:print:]]' "$f" | cut -d: -f1 | head -n 10 | tr '\n' ' ')
[ -z "$np" ] || stp "non-printable characters (lines $np)"

# ---- 1. Structure & Nonce Envelope ----
first_line=$(head -n1 "$f")
last_line=$(tail -n1 "$f")

begin_nonce=$(printf '%s' "$first_line" | sed -n 's/^WPCG_BLOCK_BEGIN nonce=\([0-9a-f]\{32\}\) .*/\1/p')
end_nonce=$(printf '%s' "$last_line" | sed -n 's/^WPCG_BLOCK_END nonce=\([0-9a-f]\{32\}\) .*/\1/p')

R ENVELOPE_NONCE
[ -n "$begin_nonce" ] || stp "malformed WPCG_BLOCK_BEGIN marker: $(S "$first_line")"
[ -n "$end_nonce" ] || stp "malformed WPCG_BLOCK_END marker: $(S "$last_line")"

if [ -n "$begin_nonce" ] && [ -n "$end_nonce" ]; then
  [ "$begin_nonce" = "$end_nonce" ] || stp "nonce mismatch between BEGIN ($begin_nonce) and END ($end_nonce)"
fi

# v4 NB-12(b): a script whose urandom read failed prints an all-zero nonce; a nonce of zeros is no nonce.
R NONCE_NONZERO
case "$begin_nonce" in
  00000000000000000000000000000000) stp "all-zero nonce" ;;
esac

R THRESHOLD_ARG
if [ $# -eq 3 ] && [ "$3" != "$THRESHOLD_CONST" ]; then
  stp "threshold hash argument differs from the compiled-in threshold hash"
fi

# Exactly one BEGIN and exactly one END marker
[ "$(grep -c '^WPCG_BLOCK_BEGIN ' "$f")" -eq 1 ] || stp "WPCG_BLOCK_BEGIN count != 1"
[ "$(grep -c '^WPCG_BLOCK_END ' "$f")" -eq 1 ] || stp "WPCG_BLOCK_END count != 1"

# No other WPCG_BLOCK_ markers in body
body_wpcg=$(sed '1d;$d' "$f" | grep -c '^WPCG_BLOCK_')
[ "$body_wpcg" -eq 0 ] || stp "rogue WPCG_BLOCK_ marker in body count=$body_wpcg"

# Verify body line count
R BODY_LINES
body_lines_claimed=$(printf '%s' "$last_line" | sed -n 's/.* lines=\([0-9]*\) .*/\1/p')
isn "$body_lines_claimed" || stp "non-canonical body lines counter: $(S "$body_lines_claimed")"
actual_body_lines=$(sed '1d;$d' "$f" | wc -l)
[ "$body_lines_claimed" = "$actual_body_lines" ] || stp "body line count mismatch: claimed $body_lines_claimed vs actual $actual_body_lines"

# Verify body SHA-256
R BODY_SHA
body_sha_claimed=$(printf '%s' "$last_line" | sed -n 's/.* body_sha256=\([0-9a-f]\{64\}\)$/\1/p')
[ -n "$body_sha_claimed" ] || stp "malformed body_sha256 in END marker: $(S "$last_line")"
actual_body_sha=$(sed '1d;$d' "$f" | sha256sum | cut -d' ' -f1)
[ "$body_sha_claimed" = "$actual_body_sha" ] || stp "body SHA-256 mismatch: claimed $body_sha_claimed vs actual $actual_body_sha"

# Section count: 9 sections (SEC 0 to SEC 8), exactly 1 BEGIN and 1 END each (18 lines total)
R SECTION_COUNT
for n in 0 1 2 3 4 5 6 7 8; do
  [ "$(grep -c "^SEC $n BEGIN " "$f")" -eq 1 ] || stp "SEC $n BEGIN count != 1"
  [ "$(grep -c "^SEC $n END " "$f")" -eq 1 ] || stp "SEC $n END count != 1"
done
[ "$(grep -c '^SEC ' "$f")" -eq 18 ] || stp "total SEC lines != 18"

# ---- 2. Script & Threshold Set Identity ----
R SCRIPT_SHA
got_script_sha=$(printf '%s' "$first_line" | sed -n 's/.* script_sha256=\([0-9a-f]\{64\}\) .*/\1/p')
[ "$got_script_sha" = "$want_script" ] || stp "script SHA-256 $(S "$got_script_sha") != expected $want_script"

R THRESHOLD_SHA
got_thresh_sha=$(printf '%s' "$first_line" | sed -n 's/.* threshold_set=\([0-9a-f]\{64\}\)$/\1/p')
[ "$got_thresh_sha" = "$want_thresholds" ] || stp "threshold_set SHA-256 $(S "$got_thresh_sha") != expected $want_thresholds"

R THRESHOLD_FIRST_LINE
body_first_line=$(sed -n '2p' "$f")
case "$body_first_line" in
  THRESHOLD_SET_SHA256=*) ;;
  *) stp "first body line is not THRESHOLD_SET_SHA256: $(S "$body_first_line")" ;;
esac

body_thresh_sha=$(grep -m1 '^THRESHOLD_SET_SHA256=' "$f" | cut -d= -f2)
[ "$body_thresh_sha" = "$want_thresholds" ] || stp "body THRESHOLD_SET_SHA256 $(S "$body_thresh_sha") != expected $want_thresholds"

# ---- 3. Line Grammar Allow-List ----
ALLOW='WPCG_BLOCK_BEGIN WPCG_BLOCK_END THRESHOLD_SET_SHA256 THRESHOLD_PLACEHOLDERS THRESHOLD_STATUS SEC IDENTITY_DATE_UTC IDENTITY_HOST_ID IDENTITY_BOOT_ID_PREFIX IDENTITY_BOOT_MATCH IDENTITY_UPTIME_SINCE PRE_0_STATUS PRE_1_HELPER_VERIFY_INSTALL PRE_2_HELPER_PREFLIGHT PRE_3_COLLECTOR_HASH PRE_3_BASELINE PRE_4_PROPERTY PRE_4_EXECSTOP_PRESENT PRE_4_EXECSTOPPOST_PRESENT PRE_4_CGROUP2FS PRE_4_CGROUP_KILL_PRESENT H_RT_S8 H_RS_S7 H_RS_PREVENT_EXIT_STATUS_1 MEM_INFO VM_PARAM PRESSURE_MEMORY PRESSURE_IO PRESSURE_CPU PRESSURE_STATUS CPU_LOADAVG CPU_NPROC OOM_VMSTAT_KILL OOM_JOURNAL_COUNT OOM_STATUS PS_CONSUMER LEGACY_MYSQLD_VMRSS_MIB LEGACY_MYSQLD_VMSWAP_MIB LEGACY_MYSQLD_MEMORY_CURRENT AIO_HANDLES FILE_HANDLES DISK_CAPACITY DATADIR_SIZE RESOLVED_SETTING G1_MEM_ESTIMATE_MIB REQUIRED_MEMAVAILABLE_MIB TIMESYNC_SYNCHRONIZED PRE_5_CAPACITY_STATUS PORT_LISTENER SOCKET_ENTRY RUN_MYSQLD_STAT LEGACY_ACTIVE_STATE LEGACY_MAINPID LEGACY_NRESTARTS LEGACY_ACTIVE_ENTER_TIMESTAMP LEGACY_PID_MATCH PRE_6_SOCKETS_STATUS SYSTEMD_NJOBS SYSTEMD_NFAILEDUNITS SYSTEMD_ACTIVE_JOB PACKAGE_PROCESS_COUNT REBOOT_REQUIRED TIMER_WINDOW_COUNT TIMER_COLLISION LOGROTATE_MYSQL_ENTRIES LOGROTATE_G1_MATCHES PRINTK_RATELIMIT PRINTK_RATELIMIT_BURST PRE_7_COLLISIONS_STATUS ERROR_LOG_INODE ERROR_LOG_SIZE ERROR_LOG_MODE ERROR_LOG_OWNER TMPDIR_AGE_DAYS VENDOR_APPARMOR_SHA256 LOGSTATE PRE_8_BASELINE_STATUS READ_FAILED FAILED_READS PRE_OUTCOME'

R ALLOWLIST_GRAMMAR
unk=$(awk -v A="$ALLOW" 'BEGIN{n=split(A,a," "); for(i=1;i<=n;i++) ok[a[i]]=1}
{ t=$0; sub(/[ =].*$/,"",t)
  if (t=="") { print NR; next }
  if (!(t in ok)) print NR }' "$f" | head -n 10)

if [ -n "$unk" ]; then
  for n in $unk; do
    echo "UNKNOWN_LINE n=$n key=$(S "$(sed -n "${n}p" "$f" | sed 's/[ =].*$//')")"
  done
  stp "unknown line prefix (lines $(echo $unk | tr '\n' ' '))"
fi

# Allow-list must contain the tokens the script is required to emit (Hermione additions, B-B12 outcome).
R ALLOW_HAS_LEGACY_NRESTARTS
case " $ALLOW " in *" LEGACY_NRESTARTS "*) ;; *) stp "allow-list lacks LEGACY_NRESTARTS" ;; esac
R ALLOW_HAS_LEGACY_ACTIVE_ENTER_TIMESTAMP
case " $ALLOW " in *" LEGACY_ACTIVE_ENTER_TIMESTAMP "*) ;; *) stp "allow-list lacks LEGACY_ACTIVE_ENTER_TIMESTAMP" ;; esac
R ALLOW_HAS_RESOLVED_SETTING
case " $ALLOW " in *" RESOLVED_SETTING "*) ;; *) stp "allow-list lacks RESOLVED_SETTING" ;; esac
R ALLOW_HAS_PRE_OUTCOME
case " $ALLOW " in *" PRE_OUTCOME "*) ;; *) stp "allow-list lacks PRE_OUTCOME" ;; esac

# ---- 3b. Token Grammar: every token after the line prefix is key=value from a closed character set ----
# SEC lines have a fixed shape; all other lines: tokens 2..N must be KEY=VALUE, VALUE in [A-Za-z0-9_.:,;+/-].
# Whole-line character set (no quotes, no $, %, *, (), [], {}, |, \, ~, #, !, ?, &, ^) is enforced as well.
R TOKEN_GRAMMAR
badc=$(grep -nvE '^[A-Za-z0-9_.:=;,/ +-]+$' "$f" | cut -d: -f1 | head -n 10 | tr '\n' ' ')
[ -z "$badc" ] || stp "character outside the allowed grammar (lines $badc)"
badsec=$(grep -E '^SEC ' "$f" | grep -cvE '^SEC [0-8] (BEGIN [a-z0-9_]+|END failed=(0|[1-9][0-9]{0,14}))$')
[ "$badsec" -eq 0 ] || stp "malformed SEC line(s) count=$badsec"
badtok=$(awk '$1=="SEC" { next }
  { for (i=2; i<=NF; i++) if ($i !~ /^[A-Za-z0-9_]+=[A-Za-z0-9_.:,;+\/-]*$/) { print NR; next } }' "$f" | head -n 10 | tr '\n' ' ')
[ -z "$badtok" ] || stp "token outside the allowed key=value grammar (lines $badtok)"

# ---- 4. Leak Detection ----
R LEAK_PATTERNS
LK='password|passwd|token|secret|PWD=|_KEY=|_PASS=|BEGIN (RSA|OPENSSH|PRIVATE)|-----|COMMAND=|[0-9]{1,3}(\.[0-9]{1,3}){3}|<path>|<relpath>|<ip>|<ip6>|<host>|\.(com|net|org|hu|io|eu|dev|app|co)([^a-z]|$)|(^|[^A-Za-z0-9])/[A-Za-z]|`|@|<|>'
lk=$(grep -nE "$LK" "$f" | cut -d: -f1 | head -n 20 | tr '\n' ' ')
[ -z "$lk" ] || stp "leak pattern hit (lines $lk)"

# ---- 4b. IPv6-like tokens (compressed '::' form, or >= 4 colon-separated hex groups) ----
R IPV6_TOKEN
V6='::|([0-9a-fA-F]{1,4}:){3,}[0-9a-fA-F]{1,4}'
v6=$(grep -nE "$V6" "$f" | cut -d: -f1 | head -n 20 | tr '\n' ' ')
[ -z "$v6" ] || stp "leak pattern hit: IPv6-like token (lines $v6)"

# ---- 5. Void Markers ----
R VOID_MARKERS
VD='READ_FAILED|VOID|UNREADABLE|=MISSING|=UNKNOWN|FAILED_READS n=[1-9]'
vd=$(grep -nE "$VD" "$f" | cut -d: -f1 | head -n 20 | tr '\n' ' ')
[ -z "$vd" ] || stp "void marker present (lines $vd)"

# ---- 6. Mandatory Status Verifications ----
R FAILED_READS_ZERO
grep -q '^FAILED_READS n=0$' "$f" || stp "FAILED_READS is not 0"
R PRE_OUTCOME_NOT_STOP
grep -q '^PRE_OUTCOME=STOP' "$f" && stp "script reported PRE_OUTCOME=STOP"
# B-PRE-1: the outcome must be present exactly once and be exactly NO_BLOCKER_FOUND (never inferred from absence).
R PRE_OUTCOME_ONCE
[ "$(grep -c '^PRE_OUTCOME=' "$f")" -eq 1 ] || stp "PRE_OUTCOME count != 1"
R PRE_OUTCOME_EQUALS_NO_BLOCKER_FOUND
grep -q '^PRE_OUTCOME=NO_BLOCKER_FOUND$' "$f" || stp "PRE_OUTCOME is not NO_BLOCKER_FOUND"

# 6b. Positive establishment: each GREEN/PASS state must be affirmatively present exactly once.
R REQUIRED_LINES
req_once() {
  local c
  c=$(grep -cE "$2" "$f")
  [ "$c" -eq 1 ] || stp "required line missing or not unique ($1): count=$c"
}
req_once H_RT_S8 '^H_RT_S8=GREEN$'
req_once H_RS_S7 '^H_RS_S7=GREEN(;NOTE=restart_mode_bounded)?$'
req_once OOM_STATUS '^OOM_STATUS=GREEN$'
req_once PRE_0_STATUS '^PRE_0_STATUS=PASS$'
req_once PRE_1 '^PRE_1_HELPER_VERIFY_INSTALL=PASS$'
req_once PRE_2 '^PRE_2_HELPER_PREFLIGHT=PASS$'
req_once PRE_3_HASH '^PRE_3_COLLECTOR_HASH=MATCH$'
req_once PRE_3_BASELINE '^PRE_3_BASELINE=PASS$'
req_once PRE_5 '^PRE_5_CAPACITY_STATUS=GREEN$'
req_once PRE_6 '^PRE_6_SOCKETS_STATUS=GREEN$'
req_once PRE_7 '^PRE_7_COLLISIONS_STATUS=GREEN$'
req_once PRE_8 '^PRE_8_BASELINE_STATUS=GREEN$'
req_once PRESSURE_STATUS '^PRESSURE_STATUS=AVAILABLE$'
req_once IDENTITY_BOOT_MATCH '^IDENTITY_BOOT_MATCH=(yes|NO)$'
req_once TIMESYNC '^TIMESYNC_SYNCHRONIZED=(yes|no)$'

# 6c. Decision counters must be canonical integers, present exactly once (a deleted or "02"/" 2" line must not bypass [1-9]).
R COUNT_LINES_CANONICAL
for ctr in SYSTEMD_NJOBS SYSTEMD_NFAILEDUNITS PACKAGE_PROCESS_COUNT TIMER_WINDOW_COUNT LOGROTATE_G1_MATCHES LOGROTATE_MYSQL_ENTRIES OOM_VMSTAT_KILL OOM_JOURNAL_COUNT; do
  c_all=$(grep -c "^$ctr count=" "$f")
  c_ok=$(grep -cE "^$ctr count=(0|[1-9][0-9]{0,14})$" "$f")
  { [ "$c_all" -eq 1 ] && [ "$c_ok" -eq 1 ]; } || stp "decision counter missing, duplicated or non-canonical ($ctr): lines=$c_all canonical=$c_ok"
done
r_all=$(grep -c '^REBOOT_REQUIRED=' "$f")
r_ok=$(grep -cE '^REBOOT_REQUIRED=(yes|no)$' "$f")
{ [ "$r_all" -eq 1 ] && [ "$r_ok" -eq 1 ]; } || stp "REBOOT_REQUIRED missing, duplicated or not yes/no: lines=$r_all valid=$r_ok"

# ---- 7. Escalation & Anomaly Checks ----
R BOOT_MATCH_ESCALATE
grep -q '^IDENTITY_BOOT_MATCH=NO' "$f" && E "boot ID differs from baseline (reboot occurred)"
R HRT_STOP
grep -q '^H_RT_S8=STOP' "$f" && stp "H-RT (S8) rule violation"
R HRS_STOP
grep -q '^H_RS_S7=STOP' "$f" && stp "H-RS (S7) rule violation"
R OOM_STOP
grep -q '^OOM_STATUS=STOP' "$f" && stp "OOM kill detected"
R CAPACITY_STOP
grep -q '^PRE_5_CAPACITY_STATUS=STOP' "$f" && stp "capacity below threshold"
R NJOBS_ESCALATE
grep -q '^SYSTEMD_NJOBS count=[1-9]' "$f" && E "systemd has active jobs running"
# B-PRE-1 (Moody) independent checks: the gate does not rely on the script's own PRE_OUTCOME.
R NFAILEDUNITS_STOP
grep -q '^SYSTEMD_NFAILEDUNITS count=[1-9]' "$f" && stp "systemd has failed units"
R REBOOT_REQUIRED_STOP
grep -q '^REBOOT_REQUIRED=yes' "$f" && stp "host reboot is required"
R PACKAGE_PROCESS_STOP
grep -q '^PACKAGE_PROCESS_COUNT count=[1-9]' "$f" && stp "package manager process running"
R TIMER_WINDOW_STOP
grep -q '^TIMER_WINDOW_COUNT count=[1-9]' "$f" && stp "timer collision in window"
R LOGROTATE_G1_STOP
grep -q '^LOGROTATE_G1_MATCHES count=[1-9]' "$f" && stp "logrotate matches G1 logs"
R SECTION_STATUS_STOP
grep -qE '^PRE_[0-9].*=STOP' "$f" && stp "section reported STOP status"
R PORT_TARGET_STOP
grep -qE '^PORT_LISTENER port=330[89] bind_class=(loopback|any|other)' "$f" && stp "target port occupied"
R PORT_3306_STOP
grep -qE '^PORT_LISTENER port=3306 bind_class=(none|any|other)' "$f" && stp "port 3306 not loopback listener"
R RUN_MYSQLD_STOP
grep -q '^RUN_MYSQLD_STAT owner=MISSING' "$f" && stp "/run/mysqld directory missing"
R LEGACY_PID_STOP
grep -qE '^LEGACY_PID_MATCH=(no|MISSING)' "$f" && stp "legacy PID file mismatch or missing"
R LEGACY_SOCKET_STOP
grep -q '^SOCKET_ENTRY class=legacy_sock_MISSING' "$f" && stp "legacy socket missing"
R G1_SOCKET_STOP
grep -qE '^SOCKET_ENTRY class=g1_.*_sock' "$f" && stp "g1 socket active in ss"

# ---- 9a. POSITIVE SCHEMA (v4): the body must be exactly the ordered line set of a healthy run ----
# Every slot is "ID|min|max|ERE". Body lines are consumed in order: a line must fill the current slot (or a
# following optional one). Missing, duplicated, extra, rewritten (grammar) and re-ordered lines are SCHEMA_STOP.
# Values are typed (enums / integers / epoch / hash grammar); decision-relevant fields carry the healthy value.
# Diagnostics contain only gate-owned slot ids, line numbers and a <=16 char token - never host text.
SLOT_ID=()
SLOT_MIN=()
SLOT_MAX=()
SLOT_RE=()
RX_N='[0-9]{1,15}'
RX_EPOCH='[1-9][0-9]{8,10}'
RX_TOK='[A-Za-z0-9_.:+/-]{1,40}'
RX_TOKE='([A-Za-z0-9_.:+/-]{1,40}|EMPTY)'
RX_D='[0-9]{1,6}(\.[0-9]{1,4})?'
RX_SPAN='([0-9]{1,16}|infinity)'
RX_SPANX='([0-9]{1,16}|infinity|other|EMPTY)'
slot() {
  SLOT_ID+=("$1")
  SLOT_MIN+=("$2")
  SLOT_MAX+=("$3")
  SLOT_RE+=("^$4\$")
}
s1() {
  slot "$1" 1 1 "$2"
}
sec() {
  s1 "SEC${1}_BEGIN" "SEC $1 BEGIN $2"
}
sece() {
  s1 "SEC${1}_END" "SEC $1 END failed=0"
}
prop() {
  slot "P_g1_$1" 1 1 "PRE_4_PROPERTY unit=g1_prod key=$1 val=$2"
  slot "P_leg_$1" 1 1 "PRE_4_PROPERTY unit=legacy key=$1 val=$3"
}

s1 HDR_SHA 'THRESHOLD_SET_SHA256=[0-9a-f]{64}'
s1 HDR_PLACEHOLDERS 'THRESHOLD_PLACEHOLDERS=10'
s1 HDR_STATUS 'THRESHOLD_STATUS=(RATIFICATION_PENDING|RATIFIED)'

sec 0 identity
s1 ID_DATE 'IDENTITY_DATE_UTC=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z'
s1 ID_BOOT_PREFIX 'IDENTITY_BOOT_ID_PREFIX=[0-9a-f]{12}'
s1 ID_BOOT_MATCH 'IDENTITY_BOOT_MATCH=(yes|NO)'
s1 ID_UPTIME "IDENTITY_UPTIME_SINCE=$RX_EPOCH"
s1 PRE0 'PRE_0_STATUS=PASS'
sece 0

sec 1 helper_verify_install
s1 PRE1 'PRE_1_HELPER_VERIFY_INSTALL=PASS'
sece 1

sec 2 helper_preflight
s1 PRE2 'PRE_2_HELPER_PREFLIGHT=PASS'
sece 2

sec 3 state_baseline_v22
s1 PRE3_HASH 'PRE_3_COLLECTOR_HASH=MATCH'
s1 PRE3_BASELINE 'PRE_3_BASELINE=PASS'
sece 3

sec 4 unit_properties_and_containment
prop Restart '(no|on-success|on-failure|on-abnormal|on-watchdog|on-abort|other|EMPTY)' '(no|on-success|on-failure|on-abnormal|on-watchdog|on-abort|always|other|EMPTY)'
prop RestartUSec "$RX_SPANX" "$RX_SPANX"
prop RestartPreventExitStatus "$RX_TOKE" "$RX_TOKE"
prop RestartForceExitStatus "$RX_TOKE" "$RX_TOKE"
prop StartLimitIntervalUSec '[1-9][0-9]{0,15}' "$RX_SPANX"
prop StartLimitBurst '[1-9][0-9]{0,14}' "$RX_TOKE"
prop TimeoutStartUSec "$RX_SPANX" "$RX_SPANX"
prop TimeoutStopUSec "$RX_SPAN" "$RX_SPAN"
prop TimeoutAbortUSec "$RX_SPAN" "$RX_SPAN"
prop WatchdogUSec "$RX_SPANX" "$RX_SPANX"
prop RuntimeDirectory "$RX_TOKE" "$RX_TOKE"
prop RuntimeDirectoryMode "$RX_TOKE" "$RX_TOKE"
prop RuntimeDirectoryPreserve '(yes|no|restart|other|EMPTY)' '(yes|no|restart|other|EMPTY)'
prop User "$RX_TOKE" "$RX_TOKE"
prop Group "$RX_TOKE" "$RX_TOKE"
prop KillMode '(control-group|mixed|process|none|other|EMPTY)' '(control-group|mixed|process|none|other|EMPTY)'
prop OOMPolicy '(continue|stop|kill|other|EMPTY)' '(continue|stop|kill|other|EMPTY)'
prop OOMScoreAdjust '(-?[0-9]{1,5}|other|EMPTY)' '(-?[0-9]{1,5}|other|EMPTY)'
prop MemoryMax "$RX_SPANX" "$RX_SPANX"
prop MemoryHigh "$RX_SPANX" "$RX_SPANX"
prop TasksMax "$RX_SPANX" "$RX_SPANX"
prop LimitNOFILE "$RX_SPANX" "$RX_SPANX"
prop ControlGroup "$RX_TOKE" "$RX_TOKE"
prop PIDFile "$RX_TOKE" "$RX_TOKE"
prop NotifyAccess '(none|main|exec|all|other|EMPTY)' '(none|main|exec|all|other|EMPTY)'
prop ActiveState 'inactive' 'active'
prop NeedDaemonReload 'no' 'no'
prop NRestarts "$RX_N" "$RX_N"
prop ActiveEnterTimestamp "($RX_EPOCH|EMPTY|NONE)" "$RX_EPOCH"
prop LoadState 'loaded' 'loaded'
prop StartLimitAction 'none' "$RX_TOKE"
s1 EXECSTOP_G1 'PRE_4_EXECSTOP_PRESENT unit=g1_prod present=(yes|no)'
s1 EXECSTOPPOST_G1 'PRE_4_EXECSTOPPOST_PRESENT unit=g1_prod present=(yes|no)'
s1 EXECSTOP_LEG 'PRE_4_EXECSTOP_PRESENT unit=legacy present=(yes|no)'
s1 EXECSTOPPOST_LEG 'PRE_4_EXECSTOPPOST_PRESENT unit=legacy present=(yes|no)'
s1 CGROUP2FS 'PRE_4_CGROUP2FS=yes'
s1 CGROUP_KILL 'PRE_4_CGROUP_KILL_PRESENT=(yes|no)'
s1 HRT 'H_RT_S8=GREEN'
s1 HRS 'H_RS_S7=GREEN(;NOTE=restart_mode_bounded)?'
s1 HRS_PREVENT 'H_RS_PREVENT_EXIT_STATUS_1=(yes|no)'
sece 4

sec 5 capacity
s1 MEM_TOTAL "MEM_INFO MemTotal_MiB=$RX_N"
s1 MEM_AVAIL "MEM_INFO MemAvailable_MiB=$RX_N"
s1 SWAP_TOTAL "MEM_INFO SwapTotal_MiB=$RX_N"
s1 SWAP_FREE "MEM_INFO SwapFree_MiB=$RX_N"
s1 COMMITTED_AS "MEM_INFO Committed_AS_MiB=$RX_N"
s1 COMMIT_LIMIT "MEM_INFO CommitLimit_MiB=$RX_N"
s1 VM_OVERCOMMIT 'VM_PARAM overcommit_memory=[0-2]'
s1 VM_SWAPPINESS 'VM_PARAM swappiness=[0-9]{1,3}'
s1 PSI_MEM "PRESSURE_MEMORY some_avg60=$RX_D full_avg60=$RX_D"
s1 PSI_IO "PRESSURE_IO some_avg60=$RX_D"
s1 PSI_CPU "PRESSURE_CPU some_avg60=$RX_D"
s1 PSI_STATUS 'PRESSURE_STATUS=AVAILABLE'
s1 LOADAVG "CPU_LOADAVG load1=$RX_D"
s1 NPROC 'CPU_NPROC nproc=[1-9][0-9]{0,5}'
s1 OOM_VMSTAT 'OOM_VMSTAT_KILL count=0'
s1 OOM_JOURNAL 'OOM_JOURNAL_COUNT count=0'
s1 OOM_STATUS 'OOM_STATUS=GREEN'
slot PS_CONSUMER 1 8 'PS_CONSUMER comm=(mysqld|php_fpm|nginx|systemd|python3|sshd|other) rss_mib=[0-9]{1,12}'
s1 LEG_VMRSS "LEGACY_MYSQLD_VMRSS_MIB=$RX_N"
s1 LEG_VMSWAP "LEGACY_MYSQLD_VMSWAP_MIB=$RX_N"
s1 LEG_MEMCUR "LEGACY_MYSQLD_MEMORY_CURRENT=$RX_TOK"
s1 AIO "AIO_HANDLES aio_nr=$RX_N aio_max_nr=$RX_N"
s1 FILE_HANDLES "FILE_HANDLES allocated=$RX_N max=[0-9]{1,20}"
s1 DISK_VAR_LIB "DISK_CAPACITY mount=mount_var_lib avail_bytes=$RX_N inode_free_pct=([0-9]|[1-9][0-9]|100)"
s1 DISK_VAR_LOG "DISK_CAPACITY mount=mount_var_log avail_bytes=$RX_N inode_free_pct=([0-9]|[1-9][0-9]|100)"
s1 DISK_VAR_TMP "DISK_CAPACITY mount=mount_var_tmp avail_bytes=$RX_N inode_free_pct=([0-9]|[1-9][0-9]|100)"
s1 DISK_RUN "DISK_CAPACITY mount=mount_run avail_bytes=$RX_N inode_free_pct=([0-9]|[1-9][0-9]|100)"
s1 DATADIR_PROD "DATADIR_SIZE instance=prod bytes=$RX_N"
s1 DATADIR_RESTORE "DATADIR_SIZE instance=restore bytes=$RX_N"
s1 DATADIR_LEGACY "DATADIR_SIZE instance=legacy bytes=$RX_N"
for inst in g1 legacy; do
  slot "RS_${inst}_bps" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-buffer-pool-size val=$RX_N"
  slot "RS_${inst}_bpi" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-buffer-pool-instances val=$RX_TOK"
  slot "RS_${inst}_lbs" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-log-buffer-size val=$RX_N"
  slot "RS_${inst}_rlc" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-redo-log-capacity val=$RX_TOK"
  slot "RS_${inst}_aio" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-use-native-aio val=$RX_TOK"
  slot "RS_${inst}_fm" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-flush-method val=$RX_TOK"
  slot "RS_${inst}_ps" 1 1 "RESOLVED_SETTING instance=$inst key=performance-schema val=$RX_TOK"
  slot "RS_${inst}_mc" 1 1 "RESOLVED_SETTING instance=$inst key=max-connections val=$RX_TOK"
  slot "RS_${inst}_toc" 1 1 "RESOLVED_SETTING instance=$inst key=table-open-cache val=$RX_TOK"
  slot "RS_${inst}_bel" 1 1 "RESOLVED_SETTING instance=$inst key=binlog-expire-logs-seconds val=$RX_TOK"
  slot "RS_${inst}_snr" 1 1 "RESOLVED_SETTING instance=$inst key=skip-name-resolve val=$RX_TOK"
  if [ "$inst" = "g1" ]; then
    slot "RS_${inst}_ded" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-dedicated-server val=(FALSE|false|OFF|off|0)"
  else
    slot "RS_${inst}_ded" 1 1 "RESOLVED_SETTING instance=$inst key=innodb-dedicated-server val=$RX_TOK"
  fi
done
s1 G1_EST "G1_MEM_ESTIMATE_MIB=$RX_N"
s1 REQ_MEMAVAIL "REQUIRED_MEMAVAILABLE_MIB=$RX_N"
s1 PRE5 'PRE_5_CAPACITY_STATUS=GREEN'
s1 TIMESYNC 'TIMESYNC_SYNCHRONIZED=(yes|no)'
sece 5

sec 6 ports_and_sockets
s1 PORT_3306 'PORT_LISTENER port=3306 bind_class=loopback'
s1 PORT_3308 'PORT_LISTENER port=3308 bind_class=none'
s1 PORT_3309 'PORT_LISTENER port=3309 bind_class=none'
s1 PORT_33060 'PORT_LISTENER port=33060 bind_class=(none|loopback|any|other)'
s1 PORT_33061 'PORT_LISTENER port=33061 bind_class=(none|loopback|any|other)'
slot SOCK_SS 1 16 'SOCKET_ENTRY class=(legacy_sock|other_sock)'
s1 RUN_MYSQLD_STAT 'RUN_MYSQLD_STAT owner=mysql:mysql mode=755'
s1 LEG_STATE 'LEGACY_ACTIVE_STATE state=active'
s1 LEG_MAINPID 'LEGACY_MAINPID pid=[1-9][0-9]{0,9}'
s1 LEG_NRESTARTS "LEGACY_NRESTARTS count=$RX_N"
s1 LEG_ENTER_TS "LEGACY_ACTIVE_ENTER_TIMESTAMP ts=$RX_EPOCH"
s1 SOCK_FS 'SOCKET_ENTRY class=legacy_sock_present'
s1 LEG_PID_MATCH 'LEGACY_PID_MATCH=yes'
s1 PRE6 'PRE_6_SOCKETS_STATUS=GREEN'
sece 6

sec 7 host_collisions
s1 NJOBS 'SYSTEMD_NJOBS count=0'
s1 NFAILED 'SYSTEMD_NFAILEDUNITS count=0'
s1 PKG 'PACKAGE_PROCESS_COUNT count=0'
s1 REBOOT 'REBOOT_REQUIRED=no'
s1 TIMER_WINDOW 'TIMER_WINDOW_COUNT count=0'
s1 LOGR_ENTRIES 'LOGROTATE_MYSQL_ENTRIES count=[0-9]{1,6}'
s1 LOGR_G1 'LOGROTATE_G1_MATCHES count=0'
s1 PRINTK 'PRINTK_RATELIMIT val=[0-9]{1,6}'
s1 PRINTK_BURST 'PRINTK_RATELIMIT_BURST val=[0-9]{1,6}'
s1 PRE7 'PRE_7_COLLISIONS_STATUS=GREEN'
sece 7

sec 8 post_baselines
s1 LOG_INODE 'ERROR_LOG_INODE inode=[1-9][0-9]{0,19}'
s1 LOG_SIZE "ERROR_LOG_SIZE bytes=$RX_N"
s1 LOG_MODE 'ERROR_LOG_MODE mode=600'
s1 LOG_OWNER 'ERROR_LOG_OWNER owner=mysql:mysql'
s1 TMPDIR_AGE 'TMPDIR_AGE_DAYS days=([0-9]|1[0-9]|2[0-4])'
s1 VENDOR_AA 'VENDOR_APPARMOR_SHA256 hash=[0-9a-f]{64}'
s1 LOGSTATE 'LOGSTATE inode=[1-9][0-9]{0,19} size=[0-9]{1,15} t0=[1-9][0-9]{9}'
s1 PRE8 'PRE_8_BASELINE_STATUS=GREEN'
sece 8
s1 FAILED_READS 'FAILED_READS n=0'
s1 PRE_OUTCOME 'PRE_OUTCOME=NO_BLOCKER_FOUND'

# Number of slots the table above must define (self-check: a future edit cannot silently drop a slot).
SCHEMA_SLOT_COUNT=193

R SCHEMA_SELF_CHECK
if [ "${#SLOT_ID[@]}" -ne "$SCHEMA_SLOT_COUNT" ]; then
  echo "GATE_SELF_CHECK=FAIL schema_slot_count=${#SLOT_ID[@]}"
  echo "GATE=STOP"
  exit 65
fi

R SCHEMA_SLOTS
schema_viol=0
schema_msgs=0
sv() {
  schema_viol=$((schema_viol + 1))
  if [ "$schema_msgs" -lt 12 ]; then
    echo "SCHEMA_STOP: $1"
    schema_msgs=$((schema_msgs + 1))
  fi
  stop=1
}
nslots=${#SLOT_ID[@]}
sc_cnt=()
sc_i=0
while [ "$sc_i" -lt "$nslots" ]; do
  sc_cnt[sc_i]=0
  sc_i=$((sc_i + 1))
done
sc_cur=0
sc_n=0
sc_body=$(sed '1d;$d' "$f")
while IFS= read -r sc_line; do
  sc_n=$((sc_n + 1))
  sc_done=0
  while [ "$sc_cur" -lt "$nslots" ]; do
    if [ "${sc_cnt[$sc_cur]}" -lt "${SLOT_MAX[$sc_cur]}" ] && [[ $sc_line =~ ${SLOT_RE[$sc_cur]} ]]; then
      sc_cnt[$sc_cur]=$((sc_cnt[$sc_cur] + 1))
      sc_done=1
      break
    fi
    if [ "${sc_cnt[$sc_cur]}" -ge "${SLOT_MIN[$sc_cur]}" ]; then
      sc_cur=$((sc_cur + 1))
    else
      break
    fi
  done
  if [ "$sc_done" -eq 0 ]; then
    sc_found=-1
    sc_j=$((sc_cur + 1))
    while [ "$sc_j" -lt "$nslots" ]; do
      if [[ $sc_line =~ ${SLOT_RE[$sc_j]} ]]; then
        sc_found=$sc_j
        break
      fi
      sc_j=$((sc_j + 1))
    done
    if [ "$sc_found" -ge 0 ] && [ "$sc_cur" -lt "$nslots" ]; then
      sc_k=$sc_cur
      while [ "$sc_k" -lt "$sc_found" ]; do
        if [ "${sc_cnt[$sc_k]}" -lt "${SLOT_MIN[$sc_k]}" ]; then
          sv "missing slot=${SLOT_ID[$sc_k]} (before body line $sc_n)"
        fi
        sc_k=$((sc_k + 1))
      done
      sc_cur=$sc_found
      sc_cnt[$sc_cur]=$((sc_cnt[$sc_cur] + 1))
    else
      sv "unexpected or invalid body line $sc_n key=$(S "${sc_line%%[ =]*}") expected slot=${SLOT_ID[$sc_cur]:-END}"
    fi
  fi
done <<< "$sc_body"
while [ "$sc_cur" -lt "$nslots" ]; do
  if [ "${sc_cnt[$sc_cur]}" -lt "${SLOT_MIN[$sc_cur]}" ]; then
    sv "missing slot=${SLOT_ID[$sc_cur]} (end of body)"
  fi
  sc_cur=$((sc_cur + 1))
done
echo "SCHEMA slots=$nslots body_lines=$sc_n violations=$schema_viol"

# ---- 9b. Cross-line range re-checks (the gate recomputes the script's threshold decisions from the printed evidence) ----
# Constants below are the frozen threshold set (thresholds.v1) values; a harness test asserts they equal the script's.
R SCHEMA_RANGES
rs() {
  echo "RANGE_STOP: $1"
  stop=1
}
gv() {
  sed -n "s/$1/\1/p" "$f" | head -n1
}
if [ "$schema_viol" -ne 0 ]; then
  echo "SCHEMA_RANGES=SKIPPED (schema violations)"
else
  r_mt=$(gv '^MEM_INFO MemTotal_MiB=\([0-9]*\)$')
  r_ma=$(gv '^MEM_INFO MemAvailable_MiB=\([0-9]*\)$')
  r_st=$(gv '^MEM_INFO SwapTotal_MiB=\([0-9]*\)$')
  r_sf=$(gv '^MEM_INFO SwapFree_MiB=\([0-9]*\)$')
  r_bp=$(gv '^RESOLVED_SETTING instance=g1 key=innodb-buffer-pool-size val=\([0-9]*\)$')
  r_lb=$(gv '^RESOLVED_SETTING instance=g1 key=innodb-log-buffer-size val=\([0-9]*\)$')
  r_est=$(gv '^G1_MEM_ESTIMATE_MIB=\([0-9]*\)$')
  r_req=$(gv '^REQUIRED_MEMAVAILABLE_MIB=\([0-9]*\)$')
  r_est_calc=$(((r_bp + r_lb + 536870912) / 1048576))
  r_margin=$((r_mt * 15 / 100))
  [ "$r_margin" -gt 512 ] || r_margin=512
  [ "$r_est" -eq "$r_est_calc" ] || rs "G1_MEM_ESTIMATE_MIB does not match the printed pool and log buffer sizes"
  [ "$r_req" -eq $((r_est_calc + r_margin)) ] || rs "REQUIRED_MEMAVAILABLE_MIB does not match estimate plus margin"
  [ "$r_ma" -ge "$r_req" ] || rs "MemAvailable below required"
  if [ "$r_sf" -gt "$r_st" ]; then
    rs "SwapFree above SwapTotal"
  elif [ "$r_st" -gt 0 ] && [ $(((r_st - r_sf) * 100 / r_st)) -gt 50 ]; then
    rs "swap usage above 50 percent"
  fi
  r_pm=$(gv '^PRESSURE_MEMORY some_avg60=\([0-9.]*\) full_avg60=[0-9.]*$')
  r_pf=$(gv '^PRESSURE_MEMORY some_avg60=[0-9.]* full_avg60=\([0-9.]*\)$')
  r_pio=$(gv '^PRESSURE_IO some_avg60=\([0-9.]*\)$')
  awk -v v="$r_pm" 'BEGIN{exit !(v > 5.00)}' && rs "PSI memory some above 5.00"
  awk -v v="$r_pf" 'BEGIN{exit !(v > 1.00)}' && rs "PSI memory full above 1.00"
  awk -v v="$r_pio" 'BEGIN{exit !(v > 20.00)}' && rs "PSI io some above 20.00"
  r_ld=$(gv '^CPU_LOADAVG load1=\([0-9.]*\)$')
  r_np=$(gv '^CPU_NPROC nproc=\([0-9]*\)$')
  [ "${r_ld%%.*}" -lt "$r_np" ] || rs "load average not below nproc"
  r_an=$(gv '^AIO_HANDLES aio_nr=\([0-9]*\) aio_max_nr=[0-9]*$')
  r_am=$(gv '^AIO_HANDLES aio_nr=[0-9]* aio_max_nr=\([0-9]*\)$')
  if [ "$r_am" -le 0 ] || [ $((r_an * 100 / r_am)) -ge 50 ]; then
    rs "aio-nr at or above 50 percent of aio-max-nr"
  fi
  for r_m in "mount_var_lib 2147483648" "mount_var_log 524288000" "mount_var_tmp 524288000" "mount_run 10485760"; do
    r_tag=${r_m% *}
    r_min=${r_m#* }
    r_av=$(gv "^DISK_CAPACITY mount=$r_tag avail_bytes=\\([0-9]*\\) inode_free_pct=[0-9]*\$")
    r_if=$(gv "^DISK_CAPACITY mount=$r_tag avail_bytes=[0-9]* inode_free_pct=\\([0-9]*\\)\$")
    [ "$r_av" -ge "$r_min" ] || rs "free bytes below threshold ($r_tag)"
    [ "$r_if" -ge 10 ] || rs "free inodes below 10 percent ($r_tag)"
  done
  r_li=$(gv '^ERROR_LOG_INODE inode=\([0-9]*\)$')
  r_ls=$(gv '^ERROR_LOG_SIZE bytes=\([0-9]*\)$')
  r_si=$(gv '^LOGSTATE inode=\([0-9]*\) size=[0-9]* t0=[0-9]*$')
  r_ss=$(gv '^LOGSTATE inode=[0-9]* size=\([0-9]*\) t0=[0-9]*$')
  r_t0=$(gv '^LOGSTATE inode=[0-9]* size=[0-9]* t0=\([0-9]*\)$')
  [ "$r_li" = "$r_si" ] || rs "LOGSTATE inode differs from ERROR_LOG_INODE"
  [ "$r_ls" = "$r_ss" ] || rs "LOGSTATE size differs from ERROR_LOG_SIZE"
  r_iso=$(gv '^IDENTITY_DATE_UTC=\(.*\)$')
  r_isoe=$(date -u -d "$r_iso" +%s 2>/dev/null)
  [ "$r_isoe" = "$r_t0" ] || rs "LOGSTATE t0 differs from IDENTITY_DATE_UTC"
  r_up=$(gv '^IDENTITY_UPTIME_SINCE=\([0-9]*\)$')
  [ "$r_up" -le "$r_t0" ] || rs "IDENTITY_UPTIME_SINCE is in the future"
  [ "$(grep -c '^SOCKET_ENTRY class=legacy_sock$' "$f")" -eq 1 ] || rs "legacy socket not listed in ss exactly once"
fi

# ---- 9. Rule-Set Self-Check: every rule id in GATE_RULE_SET must have been executed on this run ----
missing=""
nrules=0
for r in $GATE_RULE_SET; do
  nrules=$((nrules + 1))
  case "$RAN" in
    *" $r "*) ;;
    *) missing="$missing $r" ;;
  esac
done
if [ -n "$missing" ]; then
  echo "GATE_SELF_CHECK=FAIL missing_rules:$missing"
  echo "GATE=STOP"
  exit 65
fi
echo "GATE_SELF_CHECK=PASS rules=$nrules slots=${#SLOT_ID[@]}"

# ---- 8. Final Verdict ----
if [ $stop -ne 0 ]; then
  echo "GATE=STOP"
  exit 1
elif [ $esc -ne 0 ]; then
  echo "GATE=ESCALATE"
  exit 2
else
  echo "GATE=NO_BLOCKER_FOUND"
  exit 0
fi
