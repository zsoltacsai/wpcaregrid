#!/usr/bin/env bash
# WP CareGrid GO-20.39: G1 cold archive (gate G-A1). Root console, Owner GO required. Read-only on the sources; writes only a new root-only directory.
#   g1-cold-archive.sh create          archive both G1 datadirs + the G1 configuration, error logs and helper files
#   g1-cold-archive.sh verify <dir>    re-hash and re-list an archive (add --deep to extract into <dir>/deep-verify and compare every file)
# Evidence and a before-copy, NOT a proven restore. It never starts or stops anything, never deletes, never reads database content (file copy only).
# Output is fixed vocabulary: G1_ARCHIVE=PASS|STOP;CODE=...
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C LC_ALL=C
unset BASH_ENV ENV CDPATH IFS 2>/dev/null
set -u; set -o noclobber

ROOT_UID_EXPECT="0"
OUT_PARENT="/root"
UNIT_PROD="mysql@wpcaregrid-production.service"
UNIT_REST="mysql@wpcaregrid-production-restore.service"
DATADIRS="/var/lib/mysql-wpcaregrid-production /var/lib/mysql-wpcaregrid-production-restore"
CONFIG_FILES="/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf /etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf /etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf /etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf /var/log/mysql/wpcaregrid-production.log /var/log/mysql/wpcaregrid-production-restore.log /usr/local/libexec/wpcaregrid-g1-mysql.py /usr/local/libexec/production_g1_mysql_contract.py /usr/local/libexec/production_g1_mysql_manifest.json"
CONFIG_GLOBS="/etc/apparmor.d/wpcaregrid-mysql-production*"
MARKER_DIR="/run/mysqld"
MIN_FREE_FACTOR=2

stop() { echo "G1_ARCHIVE=STOP;CODE=$1"; exit 1; }
sha_of() { sha256sum -- "$1" 2>/dev/null | cut -d' ' -f1; }
unit_inactive() { local v; v=$(timeout 5 systemctl show -p ActiveState --value -- "$1" 2>/dev/null) || return 1; [ "$v" = "inactive" ]; }
rel() { printf '%s' "${1#/}"; }

# listing fingerprint of a tree: path, type, mode, owner, size, mtime (detects any change while copying)
listing_hash() { find $DATADIRS -xdev -printf '%p\t%y\t%m\t%U:%G\t%s\t%T@\n' 2>/dev/null | LC_ALL=C sort | sha256sum | cut -d' ' -f1; }

create() {
  [ "$(id -u)" = "$ROOT_UID_EXPECT" ] || stop not_root
  for t in tar sha256sum find sort stat df timeout systemctl od tr cut date wc; do command -v "$t" >/dev/null 2>&1 || stop missing_tool; done
  unit_inactive "$UNIT_PROD" || stop prod_unit_not_inactive
  unit_inactive "$UNIT_REST" || stop restore_unit_not_inactive
  local m; for m in wpcaregrid-production.sock wpcaregrid-production.pid wpcaregrid-production.sock.lock wpcaregrid-production-restore.sock wpcaregrid-production-restore.pid wpcaregrid-production-restore.sock.lock; do
    [ ! -e "$MARKER_DIR/$m" ] && [ ! -L "$MARKER_DIR/$m" ] || stop runtime_marker_present
  done
  local d bytes=0 b
  for d in $DATADIRS; do
    [ -d "$d" ] && [ ! -L "$d" ] || stop datadir_invalid
    b=$(du -sb -- "$d" 2>/dev/null | cut -f1); case "$b" in ''|*[!0-9]*) stop size_unreadable ;; esac
    bytes=$(( bytes + b ))
  done
  [ -d "$OUT_PARENT" ] && [ ! -L "$OUT_PARENT" ] || stop out_parent_invalid
  local free; free=$(df -P -B1 -- "$OUT_PARENT" 2>/dev/null | awk 'NR==2{print $4}'); case "$free" in ''|*[!0-9]*) stop free_unreadable ;; esac
  [ "$free" -ge $(( bytes * MIN_FREE_FACTOR + 536870912 )) ] || stop not_enough_space
  local f
  for f in $CONFIG_FILES; do [ -f "$f" ] && [ ! -L "$f" ] || stop config_file_missing_or_link; done
  local globs=""; for f in $CONFIG_GLOBS; do [ -f "$f" ] && [ ! -L "$f" ] && globs="$globs $f"; done
  [ -n "$globs" ] || stop apparmor_profiles_missing
  local id; id="$(date -u +%Y%m%dT%H%M%SZ)-$(od -An -N6 -tx1 /dev/urandom | tr -d ' \n')"
  local dir="$OUT_PARENT/wpcg-g1-archive-$id"
  mkdir -m 0700 -- "$dir" || stop out_dir_failed
  local before after; before=$(listing_hash)
  # per-file hashes (relative paths) of everything that goes into the archive
  { cd / && find $(for d in $DATADIRS; do rel "$d"; printf ' '; done) -type f -print0 2>/dev/null | LC_ALL=C sort -z | xargs -0 sha256sum; } > "$dir/FILES.sha256" || stop hash_failed
  { cd / && for f in $CONFIG_FILES $globs; do sha256sum -- "$(rel "$f")"; done; } >> "$dir/FILES.sha256" || stop hash_failed
  tar -C / -cpf "$dir/datadirs.tar" --numeric-owner --sparse $(for d in $DATADIRS; do rel "$d"; printf ' '; done) 2>/dev/null || stop tar_datadirs_failed
  tar -C / -cpf "$dir/config.tar" --numeric-owner $(for f in $CONFIG_FILES $globs; do rel "$f"; printf ' '; done) 2>/dev/null || stop tar_config_failed
  after=$(listing_hash)
  [ "$before" = "$after" ] || stop source_changed_during_copy
  { sha256sum "$dir/datadirs.tar" "$dir/config.tar" "$dir/FILES.sha256" | sed "s#$dir/##"; } > "$dir/TARBALLS.sha256"
  {
    echo "ARCHIVE_ID=$id"
    echo "CREATED_UTC=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "BOOT_ID_PREFIX=$(tr -d '-' < /proc/sys/kernel/random/boot_id 2>/dev/null | cut -c1-12)"
    echo "DATADIR_BYTES=$bytes"
    echo "FILES_IN_MANIFEST=$(wc -l < "$dir/FILES.sha256")"
    echo "SOURCE_LISTING_SHA256=$before"
    echo "NOTE=cold archive: evidence and before-copy, not a proven restore"
  } > "$dir/META.txt"
  chmod 0400 "$dir"/*
  verify_dir "$dir" 0 || exit 1
  echo "G1_ARCHIVE=PASS;DIR=$dir;ID=$id;FILES=$(wc -l < "$dir/FILES.sha256");DATADIR_BYTES=$bytes;TARBALLS_SHA256=$(sha_of "$dir/TARBALLS.sha256")"
}

verify_dir() {  # verify_dir DIR DEEP
  local dir="$1" deep="$2" want got n
  [ -d "$dir" ] && [ ! -L "$dir" ] || stop verify_dir_invalid
  [ "$(stat -c '%a' -- "$dir")" = "700" ] || stop verify_dir_mode
  ( cd "$dir" && sha256sum -c --quiet --strict TARBALLS.sha256 >/dev/null 2>&1 ) || stop tarball_hash_mismatch
  tar -tf "$dir/datadirs.tar" >/dev/null 2>&1 || stop datadirs_tar_unreadable
  tar -tf "$dir/config.tar" >/dev/null 2>&1 || stop config_tar_unreadable
  n=$(tar -tf "$dir/datadirs.tar" 2>/dev/null | grep -vc '/$'); want=$(grep -c "var/lib/mysql-wpcaregrid" "$dir/FILES.sha256")
  [ "$n" = "$want" ] || stop datadirs_member_count_mismatch
  if [ "$deep" = "1" ]; then
    local x="$dir/deep-verify"
    [ ! -e "$x" ] || stop deep_dir_exists
    mkdir -m 0700 -- "$x" || stop deep_dir_failed
    tar -C "$x" -xpf "$dir/datadirs.tar" --numeric-owner 2>/dev/null || stop deep_extract_failed
    tar -C "$x" -xpf "$dir/config.tar" --numeric-owner 2>/dev/null || stop deep_extract_failed
    ( cd "$x" && sha256sum -c --quiet --strict "$dir/FILES.sha256" >/dev/null 2>&1 ) || stop deep_file_hash_mismatch
    echo "G1_ARCHIVE_DEEP=PASS;DIR=$x"
  fi
  return 0
}

case "${1:-}" in
  create) [ "$#" -eq 1 ] || stop usage; create ;;
  verify) if [ "$#" -eq 2 ]; then verify_dir "$2" 0 && echo "G1_ARCHIVE_VERIFY=PASS;DIR=$2"; elif [ "$#" -eq 3 ] && [ "$3" = "--deep" ]; then verify_dir "$2" 1 && echo "G1_ARCHIVE_VERIFY=PASS;DIR=$2;DEEP=yes"; else stop usage; fi ;;
  *) stop usage ;;
esac
