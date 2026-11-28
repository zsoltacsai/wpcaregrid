#!/usr/bin/env bash
# WP CareGrid GO-20.39: restore ONE G1 instance's datadir (and its error log) from a cold archive made by g1-cold-archive.sh. Root console, Owner GO required.
#   g1-restore-datadir.sh --instance production|restore --archive <dir> --confirm "RESTORE <instance> <archive-id>"
# Reversible by construction: the current datadir and error log are RENAMED aside (never deleted), then the archived copies are extracted and
# every restored file is re-hashed against the archive manifest. It never starts, stops or reset-fails a unit, and never touches the other instance.
# Output: G1_RESTORE=PASS|STOP;CODE=...
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C
unset BASH_ENV ENV CDPATH IFS 2>/dev/null
set -u; set -o noclobber

ROOT_UID_EXPECT="0"
MARKER_DIR="/run/mysqld"
LOG_DIR="/var/log/mysql"
stop() { echo "G1_RESTORE=STOP;CODE=$1"; exit 1; }
unit_inactive() { local v; v=$(timeout 5 systemctl show -p ActiveState --value -- "$1" 2>/dev/null) || return 1; [ "$v" = "inactive" ]; }

main() {
  [ "$(id -u)" = "$ROOT_UID_EXPECT" ] || stop not_root
  [ "$#" -eq 6 ] || stop usage
  local inst="" arch="" conf=""
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --instance) [ -z "$inst" ] || stop usage; inst="${2:-}"; shift 2 ;;
      --archive)  [ -z "$arch" ] || stop usage; arch="${2:-}"; shift 2 ;;
      --confirm)  [ -z "$conf" ] || stop usage; conf="${2:-}"; shift 2 ;;
      *) stop usage ;;
    esac
  done
  local unit name datadir log
  case "$inst" in
    production) unit="mysql@wpcaregrid-production.service"; name="wpcaregrid-production" ;;
    restore)    unit="mysql@wpcaregrid-production-restore.service"; name="wpcaregrid-production-restore" ;;
    *) stop usage ;;
  esac
  datadir="/var/lib/mysql-$name"; log="$LOG_DIR/$name.log"
  for t in tar sha256sum find timeout systemctl date mv cut sed grep; do command -v "$t" >/dev/null 2>&1 || stop missing_tool; done
  [ -d "$arch" ] && [ ! -L "$arch" ] || stop archive_invalid
  case "$arch" in /*) ;; *) stop archive_not_absolute ;; esac
  local id; id=$(sed -n 's/^ARCHIVE_ID=//p' "$arch/META.txt" 2>/dev/null)
  [[ "$id" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}$ ]] || stop archive_meta_invalid
  [ "$conf" = "RESTORE $inst $id" ] || stop confirmation_mismatch
  unit_inactive "$unit" || stop unit_not_inactive
  local m; for m in "$name.sock" "$name.pid" "$name.sock.lock"; do [ ! -e "$MARKER_DIR/$m" ] && [ ! -L "$MARKER_DIR/$m" ] || stop runtime_marker_present; done
  ( cd "$arch" && sha256sum -c --quiet --strict TARBALLS.sha256 >/dev/null 2>&1 ) || stop archive_hash_mismatch
  [ -d "$datadir" ] && [ ! -L "$datadir" ] || stop datadir_invalid
  [ -f "$log" ] && [ ! -L "$log" ] || stop log_invalid
  local ts; ts=$(date -u +%Y%m%dT%H%M%SZ)
  local aside_d="$datadir.pre-restore-$ts" aside_l="$log.pre-restore-$ts"
  [ ! -e "$aside_d" ] && [ ! -L "$aside_d" ] && [ ! -e "$aside_l" ] && [ ! -L "$aside_l" ] || stop aside_exists
  local rel_d="${datadir#/}" rel_l="${log#/}"
  tar -tf "$arch/datadirs.tar" 2>/dev/null | grep -q "^$rel_d/" || stop instance_not_in_archive
  tar -tf "$arch/config.tar" 2>/dev/null | grep -qx "$rel_l" || stop log_not_in_archive
  # the only writes: two renames (reversible) and the extraction of the archived copies
  mv -- "$datadir" "$aside_d" || stop rename_datadir_failed
  mv -- "$log" "$aside_l" || stop rename_log_failed
  tar -C / -xpf "$arch/datadirs.tar" --numeric-owner "$rel_d" 2>/dev/null || stop extract_datadir_failed
  tar -C / -xpf "$arch/config.tar" --numeric-owner "$rel_l" 2>/dev/null || stop extract_log_failed
  # every restored file must equal the archive manifest
  local bad
  bad=$(cd / && grep -E "  ($rel_d/|$rel_l\$)" "$arch/FILES.sha256" | sha256sum -c --quiet --strict 2>&1 | head -n 1)
  [ -z "$bad" ] || stop restored_file_hash_mismatch
  echo "G1_RESTORE=PASS;INSTANCE=$inst;ARCHIVE_ID=$id;ASIDE_DATADIR=$aside_d;ASIDE_LOG=$aside_l;UNIT_STATE=untouched"
}
main "$@"
