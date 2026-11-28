#!/bin/bash
# GO-20.38 Pre-Start / G-R1 Script Package (v3c)
# Design Source: go20-38-prestart-poststart-DESIGN-v1.1.md Sections PRE-0..PRE-8, B-B12
# Style Baseline: go20-37-collector-v2.2.sh (read-only, zero host writes, strict token allow-list)
# Usage: env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C bash --noprofile --norc go20-38-pre-script-v3c.sh

set +x +v +e; umask 077
unset BASH_XTRACEFD PS4 BASH_ENV ENV
export LANG=C LC_ALL=C TZ=UTC PATH=/usr/sbin:/usr/bin:/sbin:/bin

# ---- 0. Root Guard (MANDATORY: must exit 3 with fixed line before any command) ----
if [ "$(id -u 2>/dev/null)" != "0" ]; then
  echo "PRE_SCRIPT=STOP;CODE=not_root"
  exit 3
fi

# ---- 0.1 Bash >= 5.1 Guard ----
BV="${BASH_VERSINFO[0]:-0}.${BASH_VERSINFO[1]:-0}"
if [ "${BASH_VERSINFO[0]:-0}" -lt 5 ] || { [ "${BASH_VERSINFO[0]:-0}" -eq 5 ] && [ "${BASH_VERSINFO[1]:-0}" -lt 1 ]; }; then
  echo "PRE_SCRIPT=STOP;CODE=bash_lt_5_1;BASH=$BV"
  exit 3
fi

# ---- Top-Level Paths & Immutable Configurations ----
SELF="$0"
HELPER_BIN="/usr/local/libexec/wpcaregrid-g1-mysql.py"
COLLECTOR_V22_PATH="/home/zsolt/Projects/project-wpcaregrid/wpcaregrid/storage/delivery/go20-37-v2.2/go20-37-collector-v2.2.sh"
COLLECTOR_V22_EXPECTED_HASH="25c985276f1016eabe8a0338caaecc1134c38999d84180170308a76ea9f54956"

# Pinned Profile & Unit Constants (Doc B v1.1 Table PRE-3)
PIN_PROF_P_NEW="64f1bd60ba0b8626978d79951179bb9c2484df1ce79981e567a7a41e057ea73a"
PIN_PROF_R_NEW="b612b2d913d4d8a178707516d8581724f2c003c5e8076f12e7b6930930c4fc32"
PIN_DROPIN_P="644e98059d68462b66a575034b5fdfbb4dbc037569f2970ab7dfa7bdccecc017"
PIN_DROPIN_R="d5df1ab7132248defe4c529afedb7517d002da73295b98ad87aa50a6ffc9e8ad"
PIN_FRAGMENT_VENDOR="2f27394c02e4b8935e31821e1732297b18d28f9a48a32a97231e3d571d50f207"
PIN_FP_META_PROD="fb02d2282ec45e826fb360da4cef5899280b24fbccac7ed8f77ed788ef33c18c"
PIN_FP_SHAPE_PROD="4711c4fa4ecec98ed4fe9825fe7243208cf6b310ab23b38cd1b7f89ee2283818"
PIN_FP_META_RESTORE="a55b3e9c079cbd129b16b589b8c87bda089cdb09d2ba3b48a98289c50183945a"
PIN_FP_SHAPE_RESTORE="47ea975e33e8103e479aff5c62b52842b67b2d6babc7766a8f08d4b06c481fd2"
BASELINE_BOOT_ID="cf8eed31d9b2464cb33be129ec40df1d"

# Frozen Threshold Set SHA-256 (Compiled into script, no overrides allowed)
THRESHOLD_SET_SHA256="d5a029bda6f51c97d73f99180a864307b8a95ddbd8a4ffd4780b10565bf57260"
THRESHOLD_PLACEHOLDERS=10

# ---- Timeout and In-Memory Buffer Plumbing ----
T() {
  local s="$1"
  shift
  timeout -k 2 "$s" "$@" </dev/null
}

F=0
STOP_REASONS=()
add_stop() {
  STOP_REASONS+=("$1")
}

fail() {
  local tag="$1"
  local rc="$2"
  out "READ_FAILED:$tag rc=$rc"
  F=$((F + 1))
}

BODY=""
out() {
  BODY+="$1"$'\n'
}

# Strict decimal integer validator
is_int() {
  case "$1" in
    ''|*[!0-9]*) return 1 ;;
    0) return 0 ;;
    0*) return 1 ;;
    *) [ "${#1}" -le 20 ] || return 1; return 0 ;;
  esac
}

# Strict non-negative decimal (integer or d.d) validator, for PSI averages and loadavg (v3c B-3)
is_dec() {
  case "$1" in
    ''|.*|*.|*[!0-9.]*|*.*.*) return 1 ;;
  esac
  [ "${#1}" -le 20 ] || return 1
  return 0
}

# systemd timespan ("1min 30s", "100ms", "6d 15min 40.9s", "infinity", "0") -> integer microseconds
# or the fixed word "infinity"; prints nothing and returns 1 when the value is not a plain timespan (v3c B-2).
span_usec() {
  local raw="$1" r
  case "$raw" in
    infinity) echo "infinity"; return 0 ;;
    ''|*[!A-Za-z0-9.\ ]*) return 1 ;;
  esac
  r=$(printf '%s\n' "$raw" | awk '
    { total = 0
      for (i = 1; i <= NF; i++) {
        tok = $i
        if (tok !~ /^[0-9]+(\.[0-9]+)?[a-z]*$/) { bad = 1; break }
        num = tok; sub(/[a-z]+$/, "", num); unit = substr(tok, length(num) + 1)
        if (unit == "" && NF == 1) m = 1
        else if (unit == "us" || unit == "usec") m = 1
        else if (unit == "ms" || unit == "msec") m = 1000
        else if (unit == "s" || unit == "sec" || unit == "second" || unit == "seconds") m = 1000000
        else if (unit == "min" || unit == "minute" || unit == "minutes") m = 60000000
        else if (unit == "h" || unit == "hr" || unit == "hour" || unit == "hours") m = 3600000000
        else if (unit == "d" || unit == "day" || unit == "days") m = 86400000000
        else if (unit == "w" || unit == "week" || unit == "weeks") m = 604800000000
        else if (unit == "month" || unit == "months") m = 2629800000000
        else if (unit == "y" || unit == "year" || unit == "years") m = 31557600000000
        else { bad = 1; break }
        total += num * m
      }
      if (NF == 0) bad = 1
      if (bad || total > 9000000000000000) print "BAD"; else printf "%.0f\n", total
    }')
  case "$r" in
    ''|BAD|*[!0-9]*) return 1 ;;
  esac
  echo "$r"
}

# Strict 64-hex hash sanitizer (B-B12 item iv)
sanitize_hash() {
  local h="$1"
  case "$h" in
    [0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f])
      echo "$h" ;;
    *) echo "other" ;;
  esac
}

# Sanitizer ensuring value conforms to ^[A-Za-z0-9_.:=/-]{1,40}$ without leak patterns
sanitize_val() {
  local v="$1"
  case "$v" in
    ''|*[!A-Za-z0-9_.:=/-]*) echo "other" ;;
    *)
      if [ "${#v}" -gt 40 ]; then
        echo "other"
        return
      fi
      # Filter leak patterns: passwords, tokens, IPv4, domains, paths, block markers
      case "$v" in
        *[Pp][Aa][Ss][Ss][Ww][Oo][Rr][Dd]*|*[Pp][Aa][Ss][Ss][Ww][Dd]*|*[Tt][Oo][Kk][Ee][Nn]*|*[Ss][Ee][Cc][Rr][Ee][Tt]*)
          echo "other" ;;
        *.*.*.*)
          echo "other" ;;
        *::*|*:*:*)
          echo "other" ;;
        *WPCG_BLOCK*|*CANARY*|*canary*)
          echo "other" ;;
        *.[Cc][Oo][Mm]|*.[Nn][Ee][Tt]|*.[Oo][Rr][Gg]|*.[Hh][Uu]|*.[Ii][Oo]|*.[Ee][Uu]|*.[Dd][Ee][Vv]|*.[Aa][Pp][Pp]|*.[Cc][Oo])
          echo "other" ;;
        /*)
          echo "other" ;;
        *)
          echo "$v" ;;
      esac
      ;;
  esac
}

map_comm() {
  case "$1" in
    mysqld) echo "mysqld" ;;
    php-fpm*|php_fpm*) echo "php_fpm" ;;
    nginx) echo "nginx" ;;
    systemd*) echo "systemd" ;;
    python3*) echo "python3" ;;
    sshd) echo "sshd" ;;
    *) echo "other" ;;
  esac
}

# Argument: the HOST part of an ss "addr:port" column (port already removed). Exact forms only (v3c B-1).
map_bind_class() {
  case "$1" in
    127.[0-9]*.[0-9]*.[0-9]*|\[::1\]|::1|localhost) echo "loopback" ;;
    0.0.0.0|\*|\[::\]|::) echo "any" ;;
    *) echo "other" ;;
  esac
}

# Map socket path in /run/mysqld/ to class token (NEVER print slash or path). Real G1 names: contract INSTANCES (v3c B-4).
map_sock_token() {
  case "$1" in
    */mysqld.sock|mysqld.sock) echo "legacy_sock" ;;
    */wpcaregrid-production.sock|wpcaregrid-production.sock) echo "g1_prod_sock" ;;
    */wpcaregrid-production-restore.sock|wpcaregrid-production-restore.sock) echo "g1_restore_sock" ;;
    *) echo "other_sock" ;;
  esac
}

# ---- Generate Per-Run 128-bit Nonce ----
NONCE=$(od -vN 16 -An -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')
if [ "${#NONCE}" -ne 32 ]; then
  NONCE="00000000000000000000000000000000"
  fail "urandom_nonce" 1
  add_stop "nonce_generation_failed"
fi

# Script self-hash
SCRIPT_SHA=$(sha256sum -- "$SELF" 2>/dev/null | cut -d' ' -f1)
if [ "${#SCRIPT_SHA}" -ne 64 ]; then
  SCRIPT_SHA="0000000000000000000000000000000000000000000000000000000000000000"
  fail "self_sha256" 1
  add_stop "self_hash_failed"
fi

# Output Header Lines into Body
out "THRESHOLD_SET_SHA256=$THRESHOLD_SET_SHA256"
out "THRESHOLD_PLACEHOLDERS=$THRESHOLD_PLACEHOLDERS"
out "THRESHOLD_STATUS=RATIFICATION_PENDING"

# =============================================================================
# SECTION PRE-0: Identity & Host Continuity
# =============================================================================
out "SEC 0 BEGIN identity"
T0_ISO=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
T0_EPOCH=$(date -u +%s)
out "IDENTITY_DATE_UTC=$T0_ISO"

BOOT_ID=""
if [ -r /proc/sys/kernel/random/boot_id ]; then
  BOOT_ID=$(tr -d -- '- \n' < /proc/sys/kernel/random/boot_id 2>/dev/null)
fi
if [ -z "$BOOT_ID" ]; then
  fail "boot_id_read" 1
  out "IDENTITY_BOOT_ID_PREFIX=UNKNOWN"
  out "IDENTITY_BOOT_MATCH=UNKNOWN"
  add_stop "boot_id_unreadable"
else
  BOOT_PREFIX=$(printf '%s' "$BOOT_ID" | cut -c1-12)
  out "IDENTITY_BOOT_ID_PREFIX=$BOOT_PREFIX"
  if [ "$BOOT_ID" = "$BASELINE_BOOT_ID" ]; then
    out "IDENTITY_BOOT_MATCH=yes"
  else
    out "IDENTITY_BOOT_MATCH=NO"
    add_stop "different_boot_id_rebaseline_required"
  fi
fi

# v3c NB-2: boot time as an epoch integer ("uptime -s" prints a space-separated local time that the closed
# sanitiser always turned into "other"); TZ is UTC (exported above). Unreadable => UNKNOWN + STOP.
UPTIME_EPOCH=""
if command -v uptime >/dev/null 2>&1; then
  UPTIME_RAW=$(T 5 uptime -s 2>/dev/null | tr -cd '0-9-: ')
  if [ -n "$UPTIME_RAW" ]; then
    UPTIME_EPOCH=$(T 5 date -u -d "$UPTIME_RAW" +%s 2>/dev/null)
  fi
fi
if ! is_int "$UPTIME_EPOCH" && [ -r /proc/uptime ]; then
  UPTIME_SEC=$(cut -d' ' -f1 /proc/uptime 2>/dev/null | cut -d. -f1)
  if is_int "$UPTIME_SEC"; then
    UPTIME_EPOCH=$((T0_EPOCH - UPTIME_SEC))
  fi
fi
if is_int "$UPTIME_EPOCH" && [ "$UPTIME_EPOCH" -gt 0 ]; then
  out "IDENTITY_UPTIME_SINCE=$UPTIME_EPOCH"
else
  out "IDENTITY_UPTIME_SINCE=UNKNOWN"
  fail "uptime_since" 1
  add_stop "uptime_since_unreadable"
fi

if [ "${#STOP_REASONS[@]}" -eq 0 ]; then
  out "PRE_0_STATUS=PASS"
else
  out "PRE_0_STATUS=STOP"
fi
out "SEC 0 END failed=$F"

# =============================================================================
# SECTION PRE-1: Helper Static + Config Verify
# =============================================================================
out "SEC 1 BEGIN helper_verify_install"
PRE1_RC=0
if [ -x "$HELPER_BIN" ]; then
  OUT_PRE1=$(T 240 python3 -I -B "$HELPER_BIN" verify-install 2>&1)
  PRE1_RC=$?
  if [ $PRE1_RC -eq 0 ] && printf '%s\n' "$OUT_PRE1" | grep -q "STATIC_INSTALL_VERIFY=PASS" && printf '%s\n' "$OUT_PRE1" | grep -q "^INSTALL_VERIFY=PASS"; then
    out "PRE_1_HELPER_VERIFY_INSTALL=PASS"
  else
    fail "helper_verify_install" $PRE1_RC
    CODE_PRE1=$(printf '%s\n' "$OUT_PRE1" | grep -m1 "G1_MYSQL=STOP;CODE=" | sed 's/.*CODE=//' | tr -cd 'A-Za-z0-9_' | cut -c1-40)
    out "PRE_1_HELPER_VERIFY_INSTALL=STOP;CODE=${CODE_PRE1:-command_failed};RC=$PRE1_RC"
    add_stop "helper_verify_install_failed"
  fi
else
  fail "helper_bin_missing" 127
  out "PRE_1_HELPER_VERIFY_INSTALL=STOP;CODE=helper_bin_missing;RC=127"
  add_stop "helper_bin_missing"
fi
out "SEC 1 END failed=$F"

# =============================================================================
# SECTION PRE-2: Helper Preflight (Production Only)
# =============================================================================
out "SEC 2 BEGIN helper_preflight"
PRE2_RC=0
if [ -x "$HELPER_BIN" ]; then
  OUT_PRE2=$(T 120 python3 -I -B "$HELPER_BIN" preflight --instance wpcaregrid-production 2>&1)
  PRE2_RC=$?
  if [ $PRE2_RC -eq 0 ] && printf '%s\n' "$OUT_PRE2" | grep -q "PREFLIGHT=PASS;INSTANCE=wpcaregrid-production"; then
    out "PRE_2_HELPER_PREFLIGHT=PASS"
  else
    fail "helper_preflight" $PRE2_RC
    CODE_PRE2=$(printf '%s\n' "$OUT_PRE2" | grep -m1 "G1_MYSQL=STOP;CODE=" | sed 's/.*CODE=//' | tr -cd 'A-Za-z0-9_' | cut -c1-40)
    out "PRE_2_HELPER_PREFLIGHT=STOP;CODE=${CODE_PRE2:-command_failed};RC=$PRE2_RC"
    add_stop "helper_preflight_failed"
  fi
else
  fail "helper_bin_missing" 127
  out "PRE_2_HELPER_PREFLIGHT=STOP;CODE=helper_bin_missing;RC=127"
  add_stop "helper_bin_missing"
fi
out "SEC 2 END failed=$F"

# =============================================================================
# SECTION PRE-3: State Baseline (Collector v2.2 Unchanged)
# =============================================================================
out "SEC 3 BEGIN state_baseline_v22"
if [ -f "$COLLECTOR_V22_PATH" ]; then
  ACTUAL_COLL_HASH=$(sha256sum -- "$COLLECTOR_V22_PATH" 2>/dev/null | cut -d' ' -f1)
  if [ "$ACTUAL_COLL_HASH" = "$COLLECTOR_V22_EXPECTED_HASH" ]; then
    out "PRE_3_COLLECTOR_HASH=MATCH"
    # Invoke collector v2.2 with timeout 300
    COLL_OUT=$(T 300 bash "$COLLECTOR_V22_PATH" 2>&1)
    COLL_RC=$?
    if [ $COLL_RC -ne 0 ]; then
      fail "collector_v22_exec" $COLL_RC
      out "PRE_3_BASELINE=STOP;REASON=collector_returned_nonzero;RC=$COLL_RC"
      add_stop "collector_v22_exec_failed"
    else
      # Compare 10 baseline items
      MISMATCHES=()

      # 1 & 2. Profile hashes
      printf '%s\n' "$COLL_OUT" | grep -q "sha256=$PIN_PROF_P_NEW" || MISMATCHES+=("profile_prod_hash")
      printf '%s\n' "$COLL_OUT" | grep -q "sha256=$PIN_PROF_R_NEW" || MISMATCHES+=("profile_restore_hash")

      # 3 & 4. Dropin hashes
      printf '%s\n' "$COLL_OUT" | grep -q "sha256=$PIN_DROPIN_P" || MISMATCHES+=("dropin_prod_hash")
      printf '%s\n' "$COLL_OUT" | grep -q "sha256=$PIN_DROPIN_R" || MISMATCHES+=("dropin_restore_hash")

      # 5. Vendor fragment hash
      printf '%s\n' "$COLL_OUT" | grep -q "sha256=$PIN_FRAGMENT_VENDOR" || MISMATCHES+=("vendor_fragment_hash")

      # 6. Both profiles (enforce)
      printf '%s\n' "$COLL_OUT" | grep -q "^wpcaregrid-mysql-production (enforce)" || MISMATCHES+=("prof_prod_not_enforce")
      printf '%s\n' "$COLL_OUT" | grep -q "^wpcaregrid-mysql-production-restore (enforce)" || MISMATCHES+=("prof_restore_not_enforce")

      # 7. Unit blocks inactive/dead/success/0
      UNIT_P_ACT=$(printf '%s\n' "$COLL_OUT" | awk '/^-- mysql@wpcaregrid-production.service/{flag=1;next}/^-- /{flag=0}flag && /^ActiveState=/{print $0}')
      [ "$UNIT_P_ACT" = "ActiveState=inactive" ] || MISMATCHES+=("prod_unit_active_state")
      UNIT_R_ACT=$(printf '%s\n' "$COLL_OUT" | awk '/^-- mysql@wpcaregrid-production-restore.service/{flag=1;next}/^-- /{flag=0}flag && /^ActiveState=/{print $0}')
      [ "$UNIT_R_ACT" = "ActiveState=inactive" ] || MISMATCHES+=("restore_unit_active_state")

      # 8. Datadir fingerprints (prod & restore)
      printf '%s\n' "$COLL_OUT" | grep -q "FINGERPRINT_META_SHA256=$PIN_FP_META_PROD" || MISMATCHES+=("prod_fp_meta")
      printf '%s\n' "$COLL_OUT" | grep -q "FINGERPRINT_SHAPE_SHA256=$PIN_FP_SHAPE_PROD" || MISMATCHES+=("prod_fp_shape")
      printf '%s\n' "$COLL_OUT" | grep -q "FINGERPRINT_META_SHA256=$PIN_FP_META_RESTORE" || MISMATCHES+=("restore_fp_meta")
      printf '%s\n' "$COLL_OUT" | grep -q "FINGERPRINT_SHAPE_SHA256=$PIN_FP_SHAPE_RESTORE" || MISMATCHES+=("restore_fp_shape")

      # 9. P1G rows exactly 5
      P1G_CNT=$(printf '%s\n' "$COLL_OUT" | grep -c "^P1G ")
      [ "$P1G_CNT" -eq 5 ] || MISMATCHES+=("p1g_row_count_$P1G_CNT")

      # 10. STATUS_DAY 11-18 n=2
      printf '%s\n' "$COLL_OUT" | grep -q "^STATUS_DAY day=2026-11-18 n=2" || MISMATCHES+=("status_day_1118")

      if [ "${#MISMATCHES[@]}" -eq 0 ]; then
        out "PRE_3_BASELINE=PASS"
      else
        M_STR=$(printf '%s,' "${MISMATCHES[@]}")
        out "PRE_3_BASELINE=STOP;MISMATCH=${M_STR%,}"
        add_stop "baseline_drift_detected"
      fi
    fi
  else
    fail "collector_sha_mismatch" 1
    out "PRE_3_COLLECTOR_HASH=MISMATCH"
    out "PRE_3_BASELINE=STOP;REASON=collector_sha_mismatch"
    add_stop "collector_sha_mismatch"
  fi
else
  fail "collector_v22_missing" 1
  out "PRE_3_COLLECTOR_HASH=MISSING"
  out "PRE_3_BASELINE=STOP;REASON=collector_v22_missing"
  add_stop "collector_v22_missing"
fi
out "SEC 3 END failed=$F"

# =============================================================================
# SECTION PRE-4: Unit Properties, H-RT (S8) and H-RS (S7)
# =============================================================================
out "SEC 4 BEGIN unit_properties_and_containment"
PROPS=(
  Restart RestartUSec RestartPreventExitStatus RestartForceExitStatus
  StartLimitIntervalUSec StartLimitBurst TimeoutStartUSec TimeoutStopUSec
  TimeoutAbortUSec WatchdogUSec RuntimeDirectory RuntimeDirectoryMode
  RuntimeDirectoryPreserve User Group KillMode OOMPolicy OOMScoreAdjust
  MemoryMax MemoryHigh TasksMax LimitNOFILE ControlGroup PIDFile NotifyAccess
  ActiveState NeedDaemonReload NRestarts ActiveEnterTimestamp LoadState StartLimitAction
)

# v3b D1: build argv as separate elements "-p" "<Name>". The v3 form "${PROPS[@]/#/-p }" produced ONE
# element "-p Name" (space inside a single argument), which systemctl takes as property " Name" and
# which matches nothing. v3b D2: the legacy read also requests MainPID and MemoryCurrent, which later
# sections consume but v3 never asked for (the test stub returned them regardless of -p).
G1_SHOW_ARGS=()
for p in "${PROPS[@]}" ExecStop ExecStopPost; do G1_SHOW_ARGS+=(-p "$p"); done
LEG_SHOW_ARGS=()
for p in "${PROPS[@]}" ExecStop ExecStopPost MainPID MemoryCurrent; do LEG_SHOW_ARGS+=(-p "$p"); done

# Read properties for G1 production
G1_PROPS=$(T 10 systemctl show mysql@wpcaregrid-production.service "${G1_SHOW_ARGS[@]}" 2>&1)
G1_RC=$?
if [ $G1_RC -ne 0 ]; then
  fail "systemctl_show_g1" $G1_RC
  add_stop "systemctl_show_g1_failed"
fi

# Read properties for legacy mysql.service
LEG_PROPS=$(T 10 systemctl show mysql.service "${LEG_SHOW_ARGS[@]}" 2>&1)
LEG_RC=$?
if [ $LEG_RC -ne 0 ]; then
  fail "systemctl_show_legacy" $LEG_RC
  add_stop "systemctl_show_legacy_failed"
fi

get_prop() {
  local src="$1"
  local key="$2"
  printf '%s\n' "$src" | grep -m1 "^$key=" | cut -d= -f2-
}

has_prop() {
  local src="$1"
  local key="$2"
  printf '%s\n' "$src" | grep -q "^$key="
}

# v3c B-2: map a raw systemd property value to a safe, informative token. Time spans (*USec) -> integer
# microseconds or "infinity"; timestamps -> epoch integer; empty -> EMPTY; "n/a" -> NONE; other values: spaces
# become "_" and the closed-charset sanitiser applies (no free text can pass).
prop_token() {
  local key="$1" raw="$2" t e
  if [ -z "$raw" ]; then
    echo "EMPTY"
    return
  fi
  case "$key" in
    RestartUSec|StartLimitIntervalUSec|TimeoutStartUSec|TimeoutStopUSec|TimeoutAbortUSec|WatchdogUSec)
      t=$(span_usec "$raw") || t=""
      if [ -n "$t" ]; then echo "$t"; else echo "other"; fi
      ;;
    ActiveEnterTimestamp)
      if [ "$raw" = "n/a" ]; then
        echo "NONE"
      else
        e=$(T 5 date -u -d "$raw" +%s 2>/dev/null)
        if is_int "$e" && [ "$e" -gt 0 ]; then echo "$e"; else echo "other"; fi
      fi
      ;;
    *)
      sanitize_val "${raw// /_}"
      ;;
  esac
}

# Output sanitized property lines
for p in "${PROPS[@]}"; do
  val_g1=$(get_prop "$G1_PROPS" "$p")
  val_leg=$(get_prop "$LEG_PROPS" "$p")
  tok_g1=$(prop_token "$p" "$val_g1")
  tok_leg=$(prop_token "$p" "$val_leg")
  # K2/C3 inputs: the stop-procedure decision needs the real values, "other"/empty is UNKNOWN (never silent).
  case "$p" in
    TimeoutStopUSec|TimeoutAbortUSec)
      if [ "$tok_g1" = "other" ] || [ "$tok_g1" = "EMPTY" ]; then
        tok_g1="UNKNOWN"; fail "timespan_g1_$p" 1; add_stop "timespan_unreadable_g1_$p"
      fi
      if [ "$tok_leg" = "other" ] || [ "$tok_leg" = "EMPTY" ]; then
        tok_leg="UNKNOWN"; fail "timespan_legacy_$p" 1; add_stop "timespan_unreadable_legacy_$p"
      fi
      ;;
  esac
  out "PRE_4_PROPERTY unit=g1_prod key=$p val=$tok_g1"
  out "PRE_4_PROPERTY unit=legacy key=$p val=$tok_leg"
done

# v3c NB-3: a unit that is not loaded reads as empty/default properties (and would look GREEN below).
if [ $G1_RC -eq 0 ] && [ "$(get_prop "$G1_PROPS" "LoadState")" != "loaded" ]; then
  fail "loadstate_g1" 1
  add_stop "g1_unit_not_loaded"
fi
if [ $LEG_RC -eq 0 ] && [ "$(get_prop "$LEG_PROPS" "LoadState")" != "loaded" ]; then
  fail "loadstate_legacy" 1
  add_stop "legacy_unit_not_loaded"
fi
# v3c NB-3 (addition to the frozen text, flagged for the Owner): a start-limit action other than "none"
# (reboot, poweroff, ...) on the G1 unit must not be started blindly.
if [ $G1_RC -eq 0 ] && [ "$(get_prop "$G1_PROPS" "StartLimitAction")" != "none" ]; then
  fail "startlimitaction_g1" 1
  add_stop "g1_startlimitaction_not_none"
fi

# NeedDaemonReload check
G1_NDR=$(get_prop "$G1_PROPS" "NeedDaemonReload")
LEG_NDR=$(get_prop "$LEG_PROPS" "NeedDaemonReload")
if [ "$G1_NDR" = "yes" ] || [ "$LEG_NDR" = "yes" ]; then
  add_stop "need_daemon_reload"
fi

# G1 production ActiveState check
G1_ACT=$(get_prop "$G1_PROPS" "ActiveState")
if [ -n "$G1_ACT" ] && [ "$G1_ACT" != "inactive" ]; then
  add_stop "g1_unit_not_inactive"
fi

# Check ExecStop and ExecStopPost (yes/no only, NEVER command text)
g1_es=$(get_prop "$G1_PROPS" "ExecStop")
g1_esp=$(get_prop "$G1_PROPS" "ExecStopPost")
leg_es=$(get_prop "$LEG_PROPS" "ExecStop")
leg_esp=$(get_prop "$LEG_PROPS" "ExecStopPost")
[ -n "$g1_es" ] && out "PRE_4_EXECSTOP_PRESENT unit=g1_prod present=yes" || out "PRE_4_EXECSTOP_PRESENT unit=g1_prod present=no"
[ -n "$g1_esp" ] && out "PRE_4_EXECSTOPPOST_PRESENT unit=g1_prod present=yes" || out "PRE_4_EXECSTOPPOST_PRESENT unit=g1_prod present=no"
[ -n "$leg_es" ] && out "PRE_4_EXECSTOP_PRESENT unit=legacy present=yes" || out "PRE_4_EXECSTOP_PRESENT unit=legacy present=no"
[ -n "$leg_esp" ] && out "PRE_4_EXECSTOPPOST_PRESENT unit=legacy present=yes" || out "PRE_4_EXECSTOPPOST_PRESENT unit=legacy present=no"

# Check cgroup filesystem type and cgroup.kill existence
CG_TYPE=$(T 5 stat -fc %T /sys/fs/cgroup 2>/dev/null)
if [ "$CG_TYPE" = "cgroup2fs" ]; then
  out "PRE_4_CGROUP2FS=yes"
else
  out "PRE_4_CGROUP2FS=no"
  fail "cgroup2fs_stat" 1
  add_stop "cgroup2fs_not_present"
fi

LEG_CG=$(get_prop "$LEG_PROPS" "ControlGroup")
if [ -n "$LEG_CG" ] && [ -f "/sys/fs/cgroup$LEG_CG/cgroup.kill" ]; then
  out "PRE_4_CGROUP_KILL_PRESENT=yes"
else
  out "PRE_4_CGROUP_KILL_PRESENT=no"
fi

# ---- H-RT (S8) Evaluation (Positive Establishment) ----
if [ -z "$G1_PROPS" ] || [ $G1_RC -ne 0 ]; then
  out "H_RT_S8=STOP;REASON=g1_properties_unreadable"
  add_stop "H_RT_g1_properties_unreadable"
elif [ -z "$LEG_PROPS" ] || [ $LEG_RC -ne 0 ]; then
  out "H_RT_S8=STOP;REASON=legacy_properties_unreadable"
  add_stop "H_RT_legacy_properties_unreadable"
elif ! has_prop "$G1_PROPS" "RuntimeDirectory" || ! has_prop "$G1_PROPS" "RuntimeDirectoryPreserve" || ! has_prop "$G1_PROPS" "PIDFile" || ! has_prop "$LEG_PROPS" "RuntimeDirectory" || ! has_prop "$LEG_PROPS" "PIDFile"; then
  out "H_RT_S8=STOP;REASON=required_properties_missing"
  add_stop "H_RT_required_properties_missing"
else
  G_RT=$(get_prop "$G1_PROPS" "RuntimeDirectory")
  L_RT=$(get_prop "$LEG_PROPS" "RuntimeDirectory")
  G_PRESERVE=$(get_prop "$G1_PROPS" "RuntimeDirectoryPreserve")
  G_PID=$(get_prop "$G1_PROPS" "PIDFile")
  L_PID=$(get_prop "$LEG_PROPS" "PIDFile")

  # Normalized check: does G share elements with L or contain mysqld?
  RT_SHARED=0
  if [ -n "$G_RT" ]; then
    for g in $G_RT; do
      g_clean=$(echo "$g" | tr -d '/')
      [ "$g_clean" = "runmysqld" ] || [ "$g_clean" = "mysqld" ] && RT_SHARED=1
      for l in $L_RT; do
        l_clean=$(echo "$l" | tr -d '/')
        [ "$g_clean" = "$l_clean" ] && RT_SHARED=1
      done
    done
  fi

  if [ "$RT_SHARED" -eq 1 ] && [ "$G_PRESERVE" != "yes" ]; then
    out "H_RT_S8=STOP;REASON=shared_runtimedirectory_without_preserve"
    add_stop "H_RT_shared_runtimedirectory_without_preserve"
  elif [ -n "$G_PID" ] && [ "$G_PID" = "$L_PID" ]; then
    out "H_RT_S8=STOP;REASON=g1_pidfile_equals_legacy_pidfile"
    add_stop "H_RT_pidfile_collision"
  else
    out "H_RT_S8=GREEN"
  fi
fi

# ---- H-RS (S7) Loop-Bound Rule Evaluation (Positive Establishment) ----
if [ -z "$G1_PROPS" ] || [ $G1_RC -ne 0 ]; then
  out "H_RS_S7=STOP;REASON=g1_properties_unreadable"
  add_stop "H_RS_g1_properties_unreadable"
elif ! has_prop "$G1_PROPS" "Restart" || ! has_prop "$G1_PROPS" "StartLimitBurst" || ! has_prop "$G1_PROPS" "StartLimitIntervalUSec"; then
  out "H_RS_S7=STOP;REASON=required_properties_missing"
  add_stop "H_RS_required_properties_missing"
else
  G_RESTART=$(get_prop "$G1_PROPS" "Restart")
  G_BURST=$(get_prop "$G1_PROPS" "StartLimitBurst")
  G_INTERVAL=$(get_prop "$G1_PROPS" "StartLimitIntervalUSec")
  G_PREVENT=$(get_prop "$G1_PROPS" "RestartPreventExitStatus")

  if [ "$G_RESTART" = "always" ]; then
    out "H_RS_S7=STOP;REASON=restart_always_prohibited"
    add_stop "H_RS_restart_always"
  elif [ -z "$G_BURST" ] || ! is_int "$G_BURST" || [ "$G_BURST" -le 0 ] || [ "$G_BURST" = "infinity" ]; then
    out "H_RS_S7=STOP;REASON=startlimitburst_zero_or_infinity"
    add_stop "H_RS_startlimitburst_unbounded"
  elif [ -z "$G_INTERVAL" ] || [ "$G_INTERVAL" = "0" ] || [ "$G_INTERVAL" = "0us" ] || [ "$G_INTERVAL" = "0s" ] || [ "$G_INTERVAL" = "infinity" ]; then
    out "H_RS_S7=STOP;REASON=startlimitinterval_zero"
    add_stop "H_RS_startlimitinterval_zero"
  elif [ "$G_RESTART" = "on-failure" ]; then
    out "H_RS_S7=GREEN"
  else
    out "H_RS_S7=GREEN;NOTE=restart_mode_bounded"
  fi
fi

if echo "$G_PREVENT" | grep -qw "1"; then
  out "H_RS_PREVENT_EXIT_STATUS_1=yes"
else
  out "H_RS_PREVENT_EXIT_STATUS_1=no"
fi
out "SEC 4 END failed=$F"

# =============================================================================
# SECTION PRE-5: Capacity Block (Memory, Pressure, AIO, Disk, Server Settings)
# =============================================================================
out "SEC 5 BEGIN capacity"
# 1. Memory from /proc/meminfo
MEM_TOTAL_KIB=""
MEM_AVAIL_KIB=""
SWAP_TOTAL_KIB=""
SWAP_FREE_KIB=""
COMMITTED_AS_KIB=""
COMMIT_LIMIT_KIB=""

# v3c: kB value as a bounded integer, otherwise 0 + non-zero status (the caller records UNKNOWN + STOP).
memnum() {
  if is_int "$1" && [ "${#1}" -le 15 ]; then
    echo "$1"
  else
    echo 0
    return 1
  fi
}

if [ -r /proc/meminfo ]; then
  while read -r key val rest; do
    case "$key" in
      MemTotal:) MEM_TOTAL_KIB="$val" ;;
      MemAvailable:) MEM_AVAIL_KIB="$val" ;;
      SwapTotal:) SWAP_TOTAL_KIB="$val" ;;
      SwapFree:) SWAP_FREE_KIB="$val" ;;
      Committed_AS:) COMMITTED_AS_KIB="$val" ;;
      CommitLimit:) COMMIT_LIMIT_KIB="$val" ;;
    esac
  done < /proc/meminfo
  # v3c: a readable /proc/meminfo with a missing or non-numeric key is UNKNOWN (v3b let a silent 0 pass).
  MEM_TOTAL_KIB=$(memnum "$MEM_TOTAL_KIB") || { fail "meminfo_key_memtotal" 1; add_stop "meminfo_key_unreadable_memtotal"; }
  MEM_AVAIL_KIB=$(memnum "$MEM_AVAIL_KIB") || { fail "meminfo_key_memavailable" 1; add_stop "meminfo_key_unreadable_memavailable"; }
  SWAP_TOTAL_KIB=$(memnum "$SWAP_TOTAL_KIB") || { fail "meminfo_key_swaptotal" 1; add_stop "meminfo_key_unreadable_swaptotal"; }
  SWAP_FREE_KIB=$(memnum "$SWAP_FREE_KIB") || { fail "meminfo_key_swapfree" 1; add_stop "meminfo_key_unreadable_swapfree"; }
  COMMITTED_AS_KIB=$(memnum "$COMMITTED_AS_KIB") || { fail "meminfo_key_committed_as" 1; add_stop "meminfo_key_unreadable_committed_as"; }
  COMMIT_LIMIT_KIB=$(memnum "$COMMIT_LIMIT_KIB") || { fail "meminfo_key_commitlimit" 1; add_stop "meminfo_key_unreadable_commitlimit"; }
else
  fail "meminfo_read" 1
  add_stop "meminfo_unreadable"
  MEM_TOTAL_KIB=0; MEM_AVAIL_KIB=0; SWAP_TOTAL_KIB=0; SWAP_FREE_KIB=0; COMMITTED_AS_KIB=0; COMMIT_LIMIT_KIB=0
fi

MEM_TOTAL_MIB=$((MEM_TOTAL_KIB / 1024))
MEM_AVAIL_MIB=$((MEM_AVAIL_KIB / 1024))
SWAP_TOTAL_MIB=$((SWAP_TOTAL_KIB / 1024))
SWAP_FREE_MIB=$((SWAP_FREE_KIB / 1024))
COMMITTED_AS_MIB=$((COMMITTED_AS_KIB / 1024))
COMMIT_LIMIT_MIB=$((COMMIT_LIMIT_KIB / 1024))

out "MEM_INFO MemTotal_MiB=$MEM_TOTAL_MIB"
out "MEM_INFO MemAvailable_MiB=$MEM_AVAIL_MIB"
out "MEM_INFO SwapTotal_MiB=$SWAP_TOTAL_MIB"
out "MEM_INFO SwapFree_MiB=$SWAP_FREE_MIB"
out "MEM_INFO Committed_AS_MiB=$COMMITTED_AS_MIB"
out "MEM_INFO CommitLimit_MiB=$COMMIT_LIMIT_MIB"

OVERCOMMIT=$(cat /proc/sys/vm/overcommit_memory 2>/dev/null || echo "UNKNOWN")
SWAPPINESS=$(cat /proc/sys/vm/swappiness 2>/dev/null || echo "UNKNOWN")
out "VM_PARAM overcommit_memory=$(sanitize_val "$OVERCOMMIT")"
out "VM_PARAM swappiness=$(sanitize_val "$SWAPPINESS")"

# Swap percentage
SWAP_USED_PCT=0
if [ "$SWAP_TOTAL_KIB" -gt 0 ] && [ "$SWAP_FREE_KIB" -le "$SWAP_TOTAL_KIB" ]; then
  SWAP_USED_KIB=$((SWAP_TOTAL_KIB - SWAP_FREE_KIB))
  SWAP_USED_PCT=$((SWAP_USED_KIB * 100 / SWAP_TOTAL_KIB))
fi
if [ "$SWAP_USED_PCT" -gt 50 ]; then
  add_stop "swap_usage_exceeds_50_pct"
fi

# 2. Pressure Stall Information
PSI_PRESENT=1
if [ -r /proc/pressure/memory ] && [ -r /proc/pressure/io ] && [ -r /proc/pressure/cpu ]; then
  M_SOME_60=$(awk '/^some/{for(i=1;i<=NF;i++)if($i~/^avg60=/){split($i,a,"=");print a[2]}}' /proc/pressure/memory 2>/dev/null)
  M_FULL_60=$(awk '/^full/{for(i=1;i<=NF;i++)if($i~/^avg60=/){split($i,a,"=");print a[2]}}' /proc/pressure/memory 2>/dev/null)
  IO_SOME_60=$(awk '/^some/{for(i=1;i<=NF;i++)if($i~/^avg60=/){split($i,a,"=");print a[2]}}' /proc/pressure/io 2>/dev/null)
  CPU_SOME_60=$(awk '/^some/{for(i=1;i<=NF;i++)if($i~/^avg60=/){split($i,a,"=");print a[2]}}' /proc/pressure/cpu 2>/dev/null)

  # v3c B-3: present-but-empty or non-decimal values are UNKNOWN, never "AVAILABLE".
  psi_tok() { if is_dec "$1"; then echo "$1"; else echo "UNKNOWN"; fi; }
  out "PRESSURE_MEMORY some_avg60=$(psi_tok "$M_SOME_60") full_avg60=$(psi_tok "$M_FULL_60")"
  out "PRESSURE_IO some_avg60=$(psi_tok "$IO_SOME_60")"
  out "PRESSURE_CPU some_avg60=$(psi_tok "$CPU_SOME_60")"
  if is_dec "$M_SOME_60" && is_dec "$M_FULL_60" && is_dec "$IO_SOME_60" && is_dec "$CPU_SOME_60"; then
    out "PRESSURE_STATUS=AVAILABLE"
  else
    out "PRESSURE_STATUS=UNKNOWN"
    fail "psi_value_unparsable" 1
    add_stop "psi_value_unparsable"
  fi

  # Enforce active PSI threshold ceilings using exact decimal comparison (Hermione Correction 2 & thresholds.v1 T3/T4)
  if is_dec "$M_SOME_60" && awk -v v="$M_SOME_60" 'BEGIN{exit !(v > 5.00)}'; then
    add_stop "psi_memory_some_avg60_exceeds_5"
  fi
  if is_dec "$M_FULL_60" && awk -v v="$M_FULL_60" 'BEGIN{exit !(v > 1.00)}'; then
    add_stop "psi_memory_full_avg60_exceeds_1"
  fi
  if is_dec "$IO_SOME_60" && awk -v v="$IO_SOME_60" 'BEGIN{exit !(v > 20.00)}'; then
    add_stop "psi_io_some_avg60_exceeds_20"
  fi
else
  PSI_PRESENT=0
  out "PRESSURE_MEMORY=UNKNOWN"
  out "PRESSURE_IO=UNKNOWN"
  out "PRESSURE_CPU=UNKNOWN"
  out "PRESSURE_STATUS=UNKNOWN"
  fail "psi_absent" 1
  add_stop "psi_absent_unknown_source"
fi

# Load Average and NProc
LOAD1=$(cut -d' ' -f1 /proc/loadavg 2>/dev/null)
NPROC_CNT=$(nproc 2>/dev/null)
LOAD1_INT=$(printf '%s' "$LOAD1" | cut -d. -f1)
if is_dec "$LOAD1"; then out "CPU_LOADAVG load1=$LOAD1"; else out "CPU_LOADAVG load1=UNKNOWN"; fi
if is_int "$NPROC_CNT" && [ "$NPROC_CNT" -gt 0 ]; then out "CPU_NPROC nproc=$NPROC_CNT"; else out "CPU_NPROC nproc=UNKNOWN"; fi
if is_int "$NPROC_CNT" && [ "$NPROC_CNT" -gt 0 ] && is_dec "$LOAD1" && is_int "$LOAD1_INT"; then
  if [ "$LOAD1_INT" -ge "$NPROC_CNT" ]; then
    add_stop "loadavg1_exceeds_nproc"
  fi
else
  fail "loadavg_or_nproc" 1
  add_stop "loadavg_or_nproc_unreadable"
fi

# 3. OOM History
OOM_KILL_CNT=$(awk '/^oom_kill /{print $2}' /proc/vmstat 2>/dev/null)
if ! is_int "$OOM_KILL_CNT"; then
  OOM_KILL_CNT="UNKNOWN"
  fail "oom_kill_read" 1
  add_stop "oom_kill_unreadable"
fi
out "OOM_VMSTAT_KILL count=$OOM_KILL_CNT"

# v3c B-3: the journalctl exit status was swallowed by "grep -c || true"; a failing journalctl is UNKNOWN.
OOM_JRNL_RAW=$(T 10 journalctl -k -b --no-pager 2>/dev/null)
OOM_JRNL_RC=$?
if [ $OOM_JRNL_RC -ne 0 ]; then
  OOM_JRNL_CNT="UNKNOWN"
  fail "journalctl_k_oom" $OOM_JRNL_RC
  add_stop "journalctl_k_oom_failed"
else
  OOM_JRNL_CNT=$(printf '%s\n' "$OOM_JRNL_RAW" | grep -cEi "Out of memory|oom-kill|Killed process" || true)
  OOM_JRNL_CNT=$(echo "$OOM_JRNL_CNT" | head -n1 | tr -cd '0-9')
  if ! is_int "$OOM_JRNL_CNT"; then
    OOM_JRNL_CNT="UNKNOWN"
    fail "journalctl_k_oom_count" 1
    add_stop "journalctl_k_oom_count_unparsable"
  fi
fi
out "OOM_JOURNAL_COUNT count=$OOM_JRNL_CNT"

if [ "$OOM_KILL_CNT" = "UNKNOWN" ] || [ "$OOM_JRNL_CNT" = "UNKNOWN" ]; then
  out "OOM_STATUS=STOP;REASON=oom_evidence_unreadable"
elif [ "$OOM_KILL_CNT" -gt 0 ]; then
  out "OOM_STATUS=STOP;REASON=oom_kill_counter_nonzero"
  add_stop "oom_kill_counter_nonzero"
elif [ "$OOM_JRNL_CNT" -gt 0 ]; then
  # v3c: conservative extension of the frozen rule (the frozen rule is the oom_kill counter): a kernel OOM
  # message in this boot's journal with a zero counter is contradictory evidence, not a green.
  out "OOM_STATUS=STOP;REASON=oom_journal_count_nonzero"
  add_stop "oom_journal_count_nonzero"
else
  out "OOM_STATUS=GREEN"
fi

# 4. Largest Consumers
PS_ROWS=$(T 10 ps -eo comm=,rss= --sort=-rss 2>/dev/null | head -n 8)
if [ -n "$PS_ROWS" ]; then
  while read -r pline; do
    [ -n "$pline" ] || continue
    # v3c NB-9: comm may contain spaces ("tmux: server"); rss is the last field.
    prss="${pline##* }"
    pcomm="${pline% *}"
    pcomm="${pcomm%"${pcomm##*[![:space:]]}"}"
    mcomm=$(map_comm "$pcomm")
    mrss_mib=0
    if is_int "$prss"; then
      mrss_mib=$((prss / 1024))
    fi
    out "PS_CONSUMER comm=$mcomm rss_mib=$mrss_mib"
  done <<< "$PS_ROWS"
else
  fail "ps_top_consumers" 1
fi

LEG_PID=$(get_prop "$LEG_PROPS" "MainPID")
LEG_VMRSS_MIB=0
LEG_VMSWAP_MIB=0
if is_int "$LEG_PID" && [ "$LEG_PID" -gt 0 ] && [ -r "/proc/$LEG_PID/status" ]; then
  LEG_VMRSS_KIB=$(awk '/^VmRSS:/{print $2}' "/proc/$LEG_PID/status" 2>/dev/null || echo "0")
  LEG_VMSWAP_KIB=$(awk '/^VmSwap:/{print $2}' "/proc/$LEG_PID/status" 2>/dev/null || echo "0")
  [ -n "$LEG_VMRSS_KIB" ] && is_int "$LEG_VMRSS_KIB" && LEG_VMRSS_MIB=$((LEG_VMRSS_KIB / 1024))
  [ -n "$LEG_VMSWAP_KIB" ] && is_int "$LEG_VMSWAP_KIB" && LEG_VMSWAP_MIB=$((LEG_VMSWAP_KIB / 1024))
fi
out "LEGACY_MYSQLD_VMRSS_MIB=$LEG_VMRSS_MIB"
out "LEGACY_MYSQLD_VMSWAP_MIB=$LEG_VMSWAP_MIB"
LEG_MEM_CURR=$(get_prop "$LEG_PROPS" "MemoryCurrent")
out "LEGACY_MYSQLD_MEMORY_CURRENT=$(sanitize_val "${LEG_MEM_CURR:-0}")"

# 5. AIO and file handles
AIO_NR=$(cat /proc/sys/fs/aio-nr 2>/dev/null)
AIO_MAX=$(cat /proc/sys/fs/aio-max-nr 2>/dev/null)
if is_int "$AIO_NR" && is_int "$AIO_MAX" && [ "$AIO_MAX" -gt 0 ] && [ "${#AIO_NR}" -le 15 ] && [ "${#AIO_MAX}" -le 15 ]; then
  out "AIO_HANDLES aio_nr=$AIO_NR aio_max_nr=$AIO_MAX"
  AIO_PCT=$((AIO_NR * 100 / AIO_MAX))
  if [ "$AIO_PCT" -ge 50 ]; then
    add_stop "aio_nr_exceeds_50_pct"
  fi
else
  # v3c B-3: unreadable / non-numeric / zero max is UNKNOWN (v3b printed 0 and silently skipped the rule).
  out "AIO_HANDLES aio_nr=UNKNOWN aio_max_nr=UNKNOWN"
  fail "aio_unreadable" 1
  add_stop "aio_unreadable"
fi

FILE_NR_ALLOC=$(awk '{print $1}' /proc/sys/fs/file-nr 2>/dev/null || echo "0")
FILE_NR_MAX=$(awk '{print $3}' /proc/sys/fs/file-nr 2>/dev/null || echo "0")
out "FILE_HANDLES allocated=$(sanitize_val "$FILE_NR_ALLOC") max=$(sanitize_val "$FILE_NR_MAX")"

# 6. Disk capacity & inodes
CHECK_MOUNTS=(
  "mount_var_lib /var/lib 2147483648"
  "mount_var_log /var/log 524288000"
  "mount_var_tmp /var/tmp 524288000"
  "mount_run     /run     10485760"
)

for mentry in "${CHECK_MOUNTS[@]}"; do
  m_tag=$(echo "$mentry" | awk '{print $1}')
  m_path=$(echo "$mentry" | awk '{print $2}')
  m_req=$(echo "$mentry" | awk '{print $3}')

  DF_BYTES=$(T 5 df -P -B1 "$m_path" 2>/dev/null | tail -n1)
  DF_INODES=$(T 5 df -Pi "$m_path" 2>/dev/null | tail -n1)

  b_avail=$(echo "$DF_BYTES" | awk '{print $4}')
  i_used_pct=$(echo "$DF_INODES" | awk '{print $5}' | tr -d '%')
  if is_int "$i_used_pct" && [ "$i_used_pct" -le 100 ]; then
    i_pfree=$((100 - i_used_pct))
  else
    # v3c B-3: filesystems without inode counts print "-" (btrfs/zfs); v3b read that as 100 % free.
    i_pfree="UNKNOWN"
    fail "df_inodes_$m_tag" 1
    add_stop "inodes_unreadable_$m_tag"
  fi

  out "DISK_CAPACITY mount=$m_tag avail_bytes=$(sanitize_val "$b_avail") inode_free_pct=$i_pfree"

  if is_int "$b_avail" && is_int "$m_req"; then
    if [ "$b_avail" -lt "$m_req" ]; then
      add_stop "disk_space_below_threshold_$m_tag"
    fi
  else
    fail "df_read_$m_tag" 1
    add_stop "disk_space_unreadable_$m_tag"
  fi

  if [ "$i_pfree" != "UNKNOWN" ] && [ "$i_pfree" -lt 10 ]; then
    add_stop "disk_inodes_below_10pct_$m_tag"
  fi
done

# Datadir sizes (du -sx --apparent-size -B1)
for dentry in "prod /var/lib/mysql-wpcaregrid-production" "restore /var/lib/mysql-wpcaregrid-production-restore" "legacy /var/lib/mysql"; do
  dtag=$(echo "$dentry" | awk '{print $1}')
  dpath=$(echo "$dentry" | awk '{print $2}')
  if [ -d "$dpath" ]; then
    d_size=$(T 30 ionice -c3 du -sx --apparent-size -B1 "$dpath" 2>/dev/null | awk '{print $1}')
    if is_int "$d_size"; then
      out "DATADIR_SIZE instance=$dtag bytes=$d_size"
    else
      out "DATADIR_SIZE instance=$dtag bytes=UNKNOWN"
      fail "du_datadir_$dtag" 1
      add_stop "datadir_size_unreadable_$dtag"
    fi
  else
    out "DATADIR_SIZE instance=$dtag bytes=UNKNOWN"
    fail "datadir_missing_$dtag" 1
    add_stop "datadir_missing_$dtag"
  fi
done

# 7. Resolved server settings & MemAvailable Formula
# Doc B PRE-5 item 7: executions of mysqld --defaults-file=/etc/mysql/my.cnf
# [--defaults-group-suffix=@wpcaregrid-production] --verbose --help
# under vendor profile as root; whitelisted keys only, values through sanitiser.
SETTINGS_KEYS=(
  "innodb-buffer-pool-size"
  "innodb-buffer-pool-instances"
  "innodb-log-buffer-size"
  "innodb-redo-log-capacity"
  "innodb-use-native-aio"
  "innodb-flush-method"
  "performance-schema"
  "max-connections"
  "table-open-cache"
  "binlog-expire-logs-seconds"
  "skip-name-resolve"
  "innodb-dedicated-server"
)

extract_mysql_setting() {
  local output="$1"
  local key="$2"
  printf '%s\n' "$output" | awk -v k="$key" '$1 == k {print $2; exit}'
}

# (a) G1 production server settings
G1_HELP_OUT=$(T 30 /usr/sbin/mysqld --defaults-file=/etc/mysql/my.cnf --defaults-group-suffix=@wpcaregrid-production --verbose --help 2>/dev/null)
G1_HELP_RC=$?

G1_SETTINGS_OK=1
if [ $G1_HELP_RC -ne 0 ] || [ -z "$G1_HELP_OUT" ]; then
  fail "mysqld_help_exec_g1" ${G1_HELP_RC:-1}
  add_stop "mysqld_verbose_help_failed_g1"
  G1_SETTINGS_OK=0
else
  for sk in "${SETTINGS_KEYS[@]}"; do
    sval=$(extract_mysql_setting "$G1_HELP_OUT" "$sk")
    if [ -n "$sval" ]; then
      out "RESOLVED_SETTING instance=g1 key=$sk val=$(sanitize_val "$sval")"
    else
      fail "mysqld_help_read_g1_$sk" 1
      add_stop "mysqld_help_missing_key_g1_$sk"
      G1_SETTINGS_OK=0
    fi
  done
fi

# (b) Legacy server settings (no suffix)
LEG_HELP_OUT=$(T 30 /usr/sbin/mysqld --defaults-file=/etc/mysql/my.cnf --verbose --help 2>/dev/null)
LEG_HELP_RC=$?

LEG_SETTINGS_OK=1
if [ $LEG_HELP_RC -ne 0 ] || [ -z "$LEG_HELP_OUT" ]; then
  fail "mysqld_help_exec_legacy" ${LEG_HELP_RC:-1}
  add_stop "mysqld_verbose_help_failed_legacy"
  LEG_SETTINGS_OK=0
else
  for sk in "${SETTINGS_KEYS[@]}"; do
    sval=$(extract_mysql_setting "$LEG_HELP_OUT" "$sk")
    if [ -n "$sval" ]; then
      out "RESOLVED_SETTING instance=legacy key=$sk val=$(sanitize_val "$sval")"
    else
      fail "mysqld_help_read_legacy_$sk" 1
      add_stop "mysqld_help_missing_key_legacy_$sk"
      LEG_SETTINGS_OK=0
    fi
  done
fi

# Formula evaluation (B-PRE-2: if help fails or unparsable, capacity status is UNKNOWN, never GREEN)
if [ "$G1_SETTINGS_OK" -ne 1 ] || [ "$LEG_SETTINGS_OK" -ne 1 ]; then
  out "G1_MEM_ESTIMATE_MIB=UNKNOWN"
  out "REQUIRED_MEMAVAILABLE_MIB=UNKNOWN"
  out "PRE_5_CAPACITY_STATUS=UNKNOWN"
else
  p_bp=$(extract_mysql_setting "$G1_HELP_OUT" "innodb-buffer-pool-size")
  p_lb=$(extract_mysql_setting "$G1_HELP_OUT" "innodb-log-buffer-size")
  p_ded=$(extract_mysql_setting "$G1_HELP_OUT" "innodb-dedicated-server")
  # v3c NB-4: sizes are limited to 15 digits (no 64-bit wrap in the estimate).
  if ! is_int "$p_bp" || ! is_int "$p_lb" || [ "${#p_bp}" -gt 15 ] || [ "${#p_lb}" -gt 15 ]; then
    fail "mysqld_help_parse_g1" 1
    add_stop "mysqld_verbose_help_parse_failed_g1"
    out "G1_MEM_ESTIMATE_MIB=UNKNOWN"
    out "REQUIRED_MEMAVAILABLE_MIB=UNKNOWN"
    out "PRE_5_CAPACITY_STATUS=UNKNOWN"
  elif [ "${p_ded^^}" != "FALSE" ] && [ "${p_ded^^}" != "OFF" ] && [ "${p_ded^^}" != "0" ]; then
    # innodb-dedicated-server=ON makes the server size the pool itself; the printed 128 MiB default is then wrong.
    fail "mysqld_help_dedicated_server_g1" 1
    add_stop "innodb_dedicated_server_enabled_or_unknown_g1"
    out "G1_MEM_ESTIMATE_MIB=UNKNOWN"
    out "REQUIRED_MEMAVAILABLE_MIB=UNKNOWN"
    out "PRE_5_CAPACITY_STATUS=UNKNOWN"
  else
    G1_BUF_POOL="$p_bp"
    G1_LOG_BUF="$p_lb"
    G1_EST_BYTES=$((G1_BUF_POOL + G1_LOG_BUF + 536870912)) # +512 MiB overhead constant
    G1_EST_MIB=$((G1_EST_BYTES / 1048576))
    out "G1_MEM_ESTIMATE_MIB=$G1_EST_MIB"

    PCT_15_MEM=$((MEM_TOTAL_MIB * 15 / 100))
    MARGIN_MIB=512
    [ "$PCT_15_MEM" -gt "$MARGIN_MIB" ] && MARGIN_MIB="$PCT_15_MEM"
    REQ_MEM_AVAIL_MIB=$((G1_EST_MIB + MARGIN_MIB))
    out "REQUIRED_MEMAVAILABLE_MIB=$REQ_MEM_AVAIL_MIB"

    if [ "$MEM_AVAIL_MIB" -lt "$REQ_MEM_AVAIL_MIB" ]; then
      out "PRE_5_CAPACITY_STATUS=STOP;REASON=memavailable_below_threshold"
      add_stop "memavailable_below_g1_est_threshold"
    elif [ "${#STOP_REASONS[@]}" -gt 0 ]; then
      out "PRE_5_CAPACITY_STATUS=STOP;REASON=earlier_stop_present"
    else
      out "PRE_5_CAPACITY_STATUS=GREEN"
    fi
  fi
fi

# 8. Time sync status
if [ -e /run/systemd/timesync/synchronized ]; then
  out "TIMESYNC_SYNCHRONIZED=yes"
else
  TS_STATE=$(T 5 systemctl show systemd-timesyncd -p ActiveState --value 2>/dev/null)
  if [ "$TS_STATE" = "active" ]; then
    out "TIMESYNC_SYNCHRONIZED=yes"
  else
    out "TIMESYNC_SYNCHRONIZED=no"
  fi
fi
out "SEC 5 END failed=$F"

# =============================================================================
# SECTION PRE-6: Ports, Sockets, and /run/mysqld
# =============================================================================
out "SEC 6 BEGIN ports_and_sockets"
# Local listening ports check: 3306, 3308, 3309, 33060, 33061
SS_PORTS=$(T 10 ss -H -ltn 2>/dev/null)
SS_PORTS_RC=$?
if [ $SS_PORTS_RC -ne 0 ]; then
  # v3c: a failed ss must not read as "no listener" (3308/3309 free).
  fail "ss_ltn" $SS_PORTS_RC
  add_stop "ss_ltn_failed"
fi
# v3c B-1: the port is compared EXACTLY (last ":"-field of the local-address column); v3b used the
# unanchored regex ":3306", which also matches :33060/:33061. The class is the WORST over all matching
# listeners (any > other > loopback), v3b classified by the first matching line only.
check_port() {
  local p="$1" matched col host cls worst
  matched=$(printf '%s\n' "$SS_PORTS" | awk -v want="$p" '{ n = split($4, a, ":"); if (n >= 2 && a[n] == want) print $4 }')
  if [ -z "$matched" ]; then
    echo "none"
    return
  fi
  worst="loopback"
  while read -r col; do
    [ -n "$col" ] || continue
    host="${col%:*}"
    cls=$(map_bind_class "$host")
    case "$cls" in
      any) worst="any" ;;
      other) [ "$worst" = "any" ] || worst="other" ;;
    esac
  done <<< "$matched"
  echo "$worst"
}

P3306=$(check_port 3306)
P3308=$(check_port 3308)
P3309=$(check_port 3309)
P33060=$(check_port 33060)
P33061=$(check_port 33061)

out "PORT_LISTENER port=3306 bind_class=$P3306"
out "PORT_LISTENER port=3308 bind_class=$P3308"
out "PORT_LISTENER port=3309 bind_class=$P3309"
out "PORT_LISTENER port=33060 bind_class=$P33060"
out "PORT_LISTENER port=33061 bind_class=$P33061"

if [ "$P3306" != "loopback" ]; then
  add_stop "port_3306_not_loopback_listener"
fi
if [ "$P3308" != "none" ]; then
  add_stop "port_3308_in_use"
fi
if [ "$P3309" != "none" ]; then
  add_stop "port_3309_in_use"
fi

# Unix sockets in ss
SS_UNIX_RAW=$(T 10 ss -H -xl 2>/dev/null)
SS_UNIX_RC=$?
if [ $SS_UNIX_RC -ne 0 ]; then
  fail "ss_xl" $SS_UNIX_RC
  add_stop "ss_xl_failed"
fi
SS_UNIX=$(printf '%s\n' "$SS_UNIX_RAW" | grep "/run/mysqld/" || true)
if [ -n "$SS_UNIX" ]; then
  while read -r line; do
    [ -n "$line" ] || continue
    # v3c B-4: real "ss -H -xl" line: "u_str LISTEN 0 4096 /run/mysqld/x.sock 9028 * 0" - the path is column 5
    # (v3b matched the case patterns against the WHOLE line, so nothing ever classified).
    stoken=$(map_sock_token "$(printf '%s\n' "$line" | awk '{print $5}')")
    out "SOCKET_ENTRY class=$stoken"
    if [ "$stoken" = "g1_prod_sock" ] || [ "$stoken" = "g1_restore_sock" ]; then
      add_stop "g1_socket_active_in_ss"
    fi
  done <<< "$SS_UNIX"
fi

# Stat of /run/mysqld
if [ -d /run/mysqld ]; then
  RMS_OWN=$(stat -c '%U:%G' /run/mysqld 2>/dev/null || echo "UNKNOWN")
  RMS_MODE=$(stat -c '%a' /run/mysqld 2>/dev/null || echo "UNKNOWN")
  out "RUN_MYSQLD_STAT owner=$(sanitize_val "$RMS_OWN") mode=$(sanitize_val "$RMS_MODE")"
  if [ "$RMS_OWN" != "mysql:mysql" ] || [ "$RMS_MODE" != "755" ]; then
    add_stop "run_mysqld_owner_or_mode_invalid"
  fi
else
  out "RUN_MYSQLD_STAT owner=MISSING mode=MISSING"
  fail "run_mysqld_missing" 1
  add_stop "run_mysqld_dir_missing"
fi

# Legacy unit status & PID check
LEG_ACT=$(get_prop "$LEG_PROPS" "ActiveState")
LEG_MAINPID=$(get_prop "$LEG_PROPS" "MainPID")
LEG_NRESTARTS=$(get_prop "$LEG_PROPS" "NRestarts")
LEG_ENTER_TS=$(get_prop "$LEG_PROPS" "ActiveEnterTimestamp")
out "LEGACY_ACTIVE_STATE state=$(sanitize_val "$LEG_ACT")"
out "LEGACY_MAINPID pid=$(sanitize_val "$LEG_MAINPID")"
if is_int "$LEG_NRESTARTS"; then
  out "LEGACY_NRESTARTS count=$LEG_NRESTARTS"
else
  out "LEGACY_NRESTARTS count=UNKNOWN"
  fail "legacy_nrestarts" 1
  add_stop "legacy_nrestarts_unreadable"
fi
# v3b D3: the raw value ("Thu 2026-11-05 21:52:49 UTC") contains spaces and would always be sanitised
# to "other", destroying the continuity baseline. Emit it as integer epoch seconds instead.
LEG_ENTER_EPOCH=""
if [ -n "$LEG_ENTER_TS" ]; then
  LEG_ENTER_EPOCH=$(T 5 date -u -d "$LEG_ENTER_TS" +%s 2>/dev/null)
fi
if is_int "$LEG_ENTER_EPOCH" && [ "$LEG_ENTER_EPOCH" -gt 0 ]; then
  out "LEGACY_ACTIVE_ENTER_TIMESTAMP ts=$LEG_ENTER_EPOCH"
else
  out "LEGACY_ACTIVE_ENTER_TIMESTAMP ts=UNKNOWN"
  fail "legacy_active_enter_timestamp" 1
  add_stop "legacy_active_enter_timestamp_unreadable"
fi

if [ "$LEG_ACT" != "active" ]; then
  add_stop "legacy_mysql_not_active"
fi

# Check legacy socket & pid in filesystem
if [ -S /run/mysqld/mysqld.sock ]; then
  out "SOCKET_ENTRY class=legacy_sock_present"
else
  out "SOCKET_ENTRY class=legacy_sock_MISSING"
  add_stop "legacy_socket_missing"
fi

if [ -f /run/mysqld/mysqld.pid ]; then
  PID_FILE_VAL=$(tr -d ' \n' < /run/mysqld/mysqld.pid 2>/dev/null)
  if [ "$PID_FILE_VAL" = "$LEG_MAINPID" ]; then
    out "LEGACY_PID_MATCH=yes"
  else
    out "LEGACY_PID_MATCH=no"
    add_stop "legacy_pid_file_mismatch"
  fi
else
  out "LEGACY_PID_MATCH=MISSING"
  add_stop "legacy_pid_file_missing"
fi

# Check absence of G1 markers in /run/mysqld
# v3c B-4: real names (contract INSTANCES; post-script v2 G1_SOCK / G1_PID_FILE / G1_LOCK_FILE):
# /run/mysqld/wpcaregrid-production{,-restore}.{sock,pid,sock.lock}. v3b looked for "mysql@..." names that
# never exist, so this STOP was dead code. A dangling symlink counts as a marker as well.
G1_MARKER_FOUND=0
for g1m in /run/mysqld/wpcaregrid-production.sock /run/mysqld/wpcaregrid-production.pid /run/mysqld/wpcaregrid-production.sock.lock \
           /run/mysqld/wpcaregrid-production-restore.sock /run/mysqld/wpcaregrid-production-restore.pid /run/mysqld/wpcaregrid-production-restore.sock.lock; do
  if [ -e "$g1m" ] || [ -L "$g1m" ]; then
    G1_MARKER_FOUND=1
  fi
done
if [ "$G1_MARKER_FOUND" -eq 1 ]; then
  add_stop "g1_markers_present_in_run_mysqld"
fi

if [ "${#STOP_REASONS[@]}" -eq 0 ]; then
  out "PRE_6_SOCKETS_STATUS=GREEN"
else
  out "PRE_6_SOCKETS_STATUS=STOP"
fi
out "SEC 6 END failed=$F"

# =============================================================================
# SECTION PRE-7: Host Collisions
# =============================================================================
out "SEC 7 BEGIN host_collisions"
NJOBS=$(T 10 systemctl show -p NJobs --value 2>/dev/null)
RC_NJOBS=$?
if [ $RC_NJOBS -ne 0 ] || [ -z "$NJOBS" ]; then
  fail "systemctl_show_njobs" ${RC_NJOBS:-1}
  add_stop "systemctl_show_njobs_failed"
  NJOBS="UNKNOWN"
fi
out "SYSTEMD_NJOBS count=$(sanitize_val "$NJOBS")"

NFAILED=$(T 10 systemctl show -p NFailedUnits --value 2>/dev/null)
RC_NFAILED=$?
if [ $RC_NFAILED -ne 0 ] || [ -z "$NFAILED" ]; then
  fail "systemctl_show_nfailed" ${RC_NFAILED:-1}
  add_stop "systemctl_show_nfailed_failed"
  NFAILED="UNKNOWN"
fi
out "SYSTEMD_NFAILEDUNITS count=$(sanitize_val "$NFAILED")"

if is_int "$NFAILED" && [ "$NFAILED" -gt 0 ]; then
  add_stop "failed_units_present"
fi

if is_int "$NJOBS" && [ "$NJOBS" -gt 0 ]; then
  add_stop "active_systemd_jobs_present"
  LIST_JOBS=$(T 10 systemctl list-jobs --no-legend 2>/dev/null)
  RC_LJOBS=$?
  if [ $RC_LJOBS -ne 0 ]; then
    fail "systemctl_list_jobs" $RC_LJOBS
    add_stop "systemctl_list_jobs_failed"
  fi
  while read -r jid junit jtype jstate rest; do
    [ -n "$jid" ] || continue
    uclass="other"
    case "$junit" in
      mysql@*) uclass="g1" ;;
      mysql.service) uclass="legacy" ;;
      *) uclass="other" ;;
    esac
    out "SYSTEMD_ACTIVE_JOB job=$(sanitize_val "$jtype") unit_class=$uclass"
    if [ "$uclass" = "g1" ] || [ "$uclass" = "legacy" ]; then
      add_stop "active_systemd_job_collision"
    fi
  done <<< "$LIST_JOBS"
fi

# Package manager processes
PKG_CNT=0
for pkg_proc in apt apt-get dpkg unattended-upgr; do
  p_cnt=$(pgrep -c -x "$pkg_proc" 2>/dev/null || echo "0")
  if is_int "$p_cnt" && [ "$p_cnt" -gt 0 ]; then
    PKG_CNT=$((PKG_CNT + p_cnt))
  fi
done
out "PACKAGE_PROCESS_COUNT count=$PKG_CNT"
if [ "$PKG_CNT" -gt 0 ]; then
  add_stop "package_manager_process_running"
fi

# Reboot required check
if [ -e /run/reboot-required ]; then
  out "REBOOT_REQUIRED=yes"
  add_stop "reboot_required_active"
else
  out "REBOOT_REQUIRED=no"
fi

# Systemd Timers Collision Window: [T0 - 300, T0 + 1800]
TIMERS_IN_WINDOW=0
ALL_TIMERS_RAW=$(T 10 systemctl list-units --type=timer --no-legend --plain 2>/dev/null)
RC_TIMERS=$?
if [ $RC_TIMERS -ne 0 ]; then
  fail "systemctl_list_timers" $RC_TIMERS
  add_stop "systemctl_list_timers_failed"
  ALL_TIMERS=""
else
  ALL_TIMERS=$(printf '%s\n' "$ALL_TIMERS_RAW" | awk '{print $1}')
fi

TM_IDX=0
for tm in $ALL_TIMERS; do
  [ -n "$tm" ] || continue
  TM_IDX=$((TM_IDX + 1))
  NEXT_USEC=$(T 10 systemctl show "$tm" -p NextElapseUSecRealtime --value 2>/dev/null)
  RC_TM=$?
  if [ $RC_TM -ne 0 ]; then
    # v3c NB-6: fixed tag + index only (the unit name is host text).
    fail "systemctl_show_timer_idx${TM_IDX}" $RC_TM
    add_stop "systemctl_show_timer_failed_idx${TM_IDX}"
    continue
  fi
  # v3b D4: systemctl may print NextElapseUSecRealtime as a formatted timestamp rather than integer
  # microseconds. Accept both; empty/n/a/0 = no calendar trigger; anything else unparsable = STOP.
  NEXT_SEC=""
  if is_int "$NEXT_USEC"; then
    [ "$NEXT_USEC" -gt 0 ] && NEXT_SEC=$((NEXT_USEC / 1000000))
  else
    case "$NEXT_USEC" in
      ''|n/a) ;;
      *)
        NEXT_SEC=$(T 5 date -u -d "$NEXT_USEC" +%s 2>/dev/null)
        if ! is_int "$NEXT_SEC"; then
          fail "timer_next_elapse_unparsable" 1
          add_stop "timer_next_elapse_unparsable"
          NEXT_SEC=""
        fi
        ;;
    esac
  fi
  if [ -n "$NEXT_SEC" ]; then
    if [ "$NEXT_SEC" -ge $((T0_EPOCH - 300)) ] && [ "$NEXT_SEC" -le $((T0_EPOCH + 1800)) ]; then
      tclass="other_timer"
      case "$tm" in
        apt-daily.timer) tclass="apt-daily" ;;
        apt-daily-upgrade.timer) tclass="apt-daily-upgrade" ;;
        logrotate.timer) tclass="logrotate" ;;
        systemd-tmpfiles-clean.timer) tclass="systemd-tmpfiles-clean" ;;
        fstrim.timer) tclass="fstrim" ;;
      esac
      out "TIMER_COLLISION timer_class=$tclass"
      TIMERS_IN_WINDOW=$((TIMERS_IN_WINDOW + 1))
      add_stop "timer_collision_within_window_$tclass"
    fi
  fi
done
out "TIMER_WINDOW_COUNT count=$TIMERS_IN_WINDOW"

# Logrotate matches for G1 log names
LOGR_ENTRIES=0
LOGR_MATCHES_G1=0
if ls /etc/logrotate.d/* >/dev/null 2>&1; then
  LOGR_ENTRIES=$(grep -l "/var/log/mysql" /etc/logrotate.d/* /etc/logrotate.conf 2>/dev/null | wc -l)
fi
out "LOGROTATE_MYSQL_ENTRIES count=$LOGR_ENTRIES"
if [ "$LOGR_ENTRIES" -gt 0 ]; then
  LOGR_DBG=$(T 10 logrotate -d /etc/logrotate.conf 2>&1)
  LOGR_RC=$?
  if [ $LOGR_RC -eq 124 ] || [ $LOGR_RC -eq 126 ] || [ $LOGR_RC -eq 127 ] || [ -z "$LOGR_DBG" ]; then
    # v3c NB-7: "logrotate -d" that did not run (timeout / not executable / no output) is UNKNOWN, not "0 matches".
    LOGR_MATCHES_G1="UNKNOWN"
    fail "logrotate_debug" $LOGR_RC
    add_stop "logrotate_debug_failed"
  elif printf '%s\n' "$LOGR_DBG" | grep -q "wpcaregrid-production"; then
    LOGR_MATCHES_G1=1
    add_stop "logrotate_matches_g1_logs"
  fi
fi
out "LOGROTATE_G1_MATCHES count=$LOGR_MATCHES_G1"

# Printk Rate Limits (Read-Only)
PK_RL=$(sysctl -n kernel.printk_ratelimit 2>/dev/null || echo "UNKNOWN")
PK_BURST=$(sysctl -n kernel.printk_ratelimit_burst 2>/dev/null || echo "UNKNOWN")
out "PRINTK_RATELIMIT val=$(sanitize_val "$PK_RL")"
out "PRINTK_RATELIMIT_BURST val=$(sanitize_val "$PK_BURST")"

if [ "${#STOP_REASONS[@]}" -eq 0 ]; then
  out "PRE_7_COLLISIONS_STATUS=GREEN"
else
  out "PRE_7_COLLISIONS_STATUS=STOP"
fi
out "SEC 7 END failed=$F"

# =============================================================================
# SECTION PRE-8: Baselines for Post Block
# =============================================================================
out "SEC 8 BEGIN post_baselines"
PROD_LOG="/var/log/mysql/wpcaregrid-production.log"
LOG_INODE=0
LOG_SIZE=0
if [ -f "$PROD_LOG" ]; then
  LOG_INODE=$(stat -c '%i' "$PROD_LOG" 2>/dev/null || echo "0")
  LOG_SIZE=$(stat -c '%s' "$PROD_LOG" 2>/dev/null || echo "0")
  LOG_MODE=$(stat -c '%a' "$PROD_LOG" 2>/dev/null || echo "0")
  LOG_OWN=$(stat -c '%U:%G' "$PROD_LOG" 2>/dev/null || echo "UNKNOWN")

  out "ERROR_LOG_INODE inode=$(sanitize_val "$LOG_INODE")"
  out "ERROR_LOG_SIZE bytes=$(sanitize_val "$LOG_SIZE")"
  out "ERROR_LOG_MODE mode=$(sanitize_val "$LOG_MODE")"
  out "ERROR_LOG_OWNER owner=$(sanitize_val "$LOG_OWN")"

  if [ "$LOG_MODE" != "600" ] || [ "$LOG_OWN" != "mysql:mysql" ]; then
    add_stop "error_log_mode_or_owner_invalid"
  fi
else
  out "ERROR_LOG_INODE=MISSING"
  out "ERROR_LOG_SIZE=MISSING"
  out "ERROR_LOG_MODE=MISSING"
  out "ERROR_LOG_OWNER=MISSING"
  fail "error_log_missing" 1
  add_stop "error_log_missing"
fi

# Tmpdir age check (/var/tmp/wpcaregrid-production)
TMPDIR_PATH="/var/tmp/wpcaregrid-production"
if [ -d "$TMPDIR_PATH" ]; then
  TMPDIR_MTIME=$(stat -c '%Y' "$TMPDIR_PATH" 2>/dev/null)
  # v3c B-3: an unreadable mtime (or one in the future) is UNKNOWN; v3b printed age 0 = fresh.
  if is_int "$TMPDIR_MTIME" && [ "$TMPDIR_MTIME" -gt 0 ] && [ "$TMPDIR_MTIME" -le "$T0_EPOCH" ]; then
    TMPDIR_AGE_DAYS=$(((T0_EPOCH - TMPDIR_MTIME) / 86400))
    out "TMPDIR_AGE_DAYS days=$TMPDIR_AGE_DAYS"
    if [ "$TMPDIR_AGE_DAYS" -ge 25 ]; then
      add_stop "tmpdir_age_exceeds_25_days"
    fi
  else
    out "TMPDIR_AGE_DAYS days=UNKNOWN"
    fail "tmpdir_mtime_unreadable" 1
    add_stop "tmpdir_mtime_unreadable"
  fi
else
  out "TMPDIR_AGE_DAYS=MISSING"
  fail "tmpdir_missing" 1
  add_stop "tmpdir_missing"
fi

# Vendor AppArmor Profile Hash
VENDOR_AA="/etc/apparmor.d/usr.sbin.mysqld"
VENDOR_AA_HASH="MISSING"
if [ -f "$VENDOR_AA" ]; then
  VENDOR_AA_HASH=$(sha256sum -- "$VENDOR_AA" 2>/dev/null | cut -d' ' -f1)
fi
out "VENDOR_APPARMOR_SHA256 hash=$(sanitize_hash "$VENDOR_AA_HASH")"

# State line for the post-start block (B-B7)
out "LOGSTATE inode=$LOG_INODE size=$LOG_SIZE t0=$T0_EPOCH"

if [ "${#STOP_REASONS[@]}" -eq 0 ]; then
  out "PRE_8_BASELINE_STATUS=GREEN"
else
  out "PRE_8_BASELINE_STATUS=STOP"
fi
out "SEC 8 END failed=$F"

# =============================================================================
# SUMMARY & FINAL OUTCOME
# =============================================================================
out "FAILED_READS n=$F"

if [ "${#STOP_REASONS[@]}" -gt 0 ]; then
  FIRST_STOP="${STOP_REASONS[0]}"
  out "PRE_OUTCOME=STOP;REASON=$FIRST_STOP"
elif [ "$F" -gt 0 ]; then
  out "PRE_OUTCOME=STOP;REASON=failed_reads_nonzero"
else
  out "PRE_OUTCOME=NO_BLOCKER_FOUND"
fi

# =============================================================================
# EMIT OUTPUT WITH CRYPTOGRAPHIC NONCE & SHA-256 INTEGRITY
# =============================================================================
# Count lines in body (wc -l)
BODY_LINES=$(printf '%s' "$BODY" | wc -l)
BODY_SHA=$(printf '%s' "$BODY" | sha256sum | cut -d' ' -f1)

# Stream first line, body, and last line
echo "WPCG_BLOCK_BEGIN nonce=$NONCE script_sha256=$SCRIPT_SHA threshold_set=$THRESHOLD_SET_SHA256"
printf '%s' "$BODY"
echo "WPCG_BLOCK_END nonce=$NONCE lines=$BODY_LINES body_sha256=$BODY_SHA"

# Exit code: 0 if no blocker, 1 if stop
if [ "${#STOP_REASONS[@]}" -gt 0 ] || [ "$F" -gt 0 ]; then
  exit 1
fi
exit 0
