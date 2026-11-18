#!/usr/bin/env bash
set -euo pipefail
# Fetches the exact three-file GO-5.4a payload from a reviewed commit. This only stages a
# root-owned package under /root; the separate go54a-upgrade-apparmor.py performs the transaction.
M=${1:-}
[[ $M =~ ^[0-9a-f]{40}$ ]] || { echo "G1_GO54A_DOWNLOAD=STOP;CODE=commit_argument_invalid"; exit 3; }
N=/root/wpcg-go54a-${M:0:7}
W="$N.incoming-UNVERIFIED"
BASE="https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/$M/scripts"
FILES=(production-g1-mysql.py production_g1_mysql_contract.py production-g1-mysql-manifest.json SHA256SUMS)
CODE=""
stop() { CODE=$1; exit 3; }
trap 'rc=$?; if [ "$rc" -ne 0 ]; then echo "G1_GO54A_DOWNLOAD=STOP;CODE=${CODE:-unexpected_command_failure};NOTE=incoming_package_preserved"; fi' EXIT

[ "$(id -u)" = 0 ] || stop not_root
for p in "$N" "$W"; do
  { [ ! -e "$p" ] && [ ! -L "$p" ]; } || stop target_path_exists
done
umask 077
install -d -o root -g root -m 0700 "$W"
cd "$W"
for f in "${FILES[@]}"; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
       --max-filesize 2000000 --max-time 60 --output "$f" "$BASE/$f" </dev/null || stop download_failed
done
chown root:root "${FILES[@]}"
chmod 0600 "${FILES[@]}"
[ "$(find . -mindepth 1 -maxdepth 1 -type f | wc -l)" = 4 ] || stop package_file_count_invalid
for f in "${FILES[@]}"; do
  [ "$(stat -c '%U:%G:%a:%F' -- "$f")" = "root:root:600:regular file" ] || stop package_file_metadata_invalid
done
printf '%s  %s\n' \
  c6d2783825f247afd0f7c8749d570646d65344a9fc99126f6d5627d1fc423f9e SHA256SUMS \
  | sha256sum -c --strict - >/dev/null || stop sums_file_hash_mismatch
printf '%s  %s\n' \
  9ff1b969fa2371d3967c15ff9f6fcf4553ecf5fcda2b039b46e924d885bbf274 production-g1-mysql.py \
  cdebda049cb1412e656882fe02d50f7bfaed1226e7439023d37a43beb8cbc2dc production_g1_mysql_contract.py \
  cfc07bfd6970f12deedc4dcee244b7fca9af9b3acb8def8e829aaac2c12aceec production-g1-mysql-manifest.json \
  | sha256sum -c --strict - >/dev/null || stop package_content_hash_mismatch
sha256sum -c --strict SHA256SUMS >/dev/null || stop package_sums_mismatch
[ "$(wc -l < SHA256SUMS)" = 3 ] || stop sums_entry_count_invalid
cd /
mv -T -- "$W" "$N" || stop final_directory_promotion_failed
trap - EXIT
echo "G1_GO54A_DOWNLOAD=PASS;PACKAGE=$N"
