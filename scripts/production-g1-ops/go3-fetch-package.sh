set -euo pipefail
M=bb7e20e8c62d7977b7f2650b39d76d9b203198c4
N=/root/wpcg-production-g1-mysql-bb7e20e
W="$N.incoming-UNVERIFIED"
BASE="https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/$M/scripts"
FILES=(production-g1-mysql.py production_g1_mysql_contract.py production-g1-mysql-manifest.json SHA256SUMS)
CODE=""
stop() { CODE=$1; echo "FAIL  $1 ${2:-}"; exit 3; }
trap 'rc=$?; if [ "$rc" -ne 0 ]; then echo "G1_GO3_DOWNLOAD=STOP;CODE=${CODE:-unexpected_command_failure};NOTE=incoming_directory_is_unverified_nothing_was_installed"; fi' EXIT

[ "$(id -u)" = 0 ] || stop not_root
if [ -e "$N" ] || [ -L "$N" ]; then stop target_directory_exists "$N"; fi
if [ -e "$W" ] || [ -L "$W" ]; then stop staging_directory_exists "$W"; fi
umask 077
install -d -o root -g root -m 0700 "$W"
cd "$W"

for f in "${FILES[@]}"; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --tlsv1.2 \
       --max-filesize 2000000 --max-time 60 --output "$f" "$BASE/$f" </dev/null || stop download_failed "$f"
done
chown root:root "${FILES[@]}"
chmod 0600 "${FILES[@]}"
[ "$(ls -A | wc -l)" = 4 ] || stop file_count_not_four
for f in "${FILES[@]}"; do
  [ "$(stat -c '%U:%G:%a:%F' -- "$f")" = "root:root:600:regular file" ] || stop owner_mode_or_type_invalid "$f"
done

# The hashes below come from the #328 merge commit bb7e20e; SHA256SUMS is checked on its own and as a list.
printf '%s  %s\n' d5f82bddd2d908b84062bfb8c115c92478df2e26a14b424f4006d913dd219a92 SHA256SUMS | sha256sum -c --strict - >/dev/null || stop sha256sums_file_hash_mismatch
printf '%s  %s\n' \
  5bddfcc886c8f94653f1aa9c55c63da7dca259042156db3e7e8a5378639e215a production-g1-mysql.py \
  45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3 production_g1_mysql_contract.py \
  87f7aa3a8acee74fbf0bb697ec5e93b540fe9484927bab4212b24df71e2d22e9 production-g1-mysql-manifest.json \
  | sha256sum -c --strict - >/dev/null || stop source_file_hash_mismatch
sha256sum -c --strict SHA256SUMS >/dev/null || stop sha256sums_list_mismatch
[ "$(wc -l < SHA256SUMS)" = 3 ] || stop sha256sums_line_count

cd /
mv -T -- "$W" "$N" || stop rename_failed
cd "$N"
[ "$(ls -A | wc -l)" = 4 ] || stop file_count_not_four_after_rename
trap - EXIT
echo "G1_GO3_DOWNLOAD=PASS"
