set -euo pipefail
# Fetches the four files of the G1 log-directory hotfix package from the given merge commit into a NEW
# directory /root/wpcg-production-g1-mysql-hf-<sha7>. Every file is verified against the pins below
# before the directory is renamed into place; nothing is installed. Usage: go4b-fetch-hotfix-package.sh <40-hex commit>
M=${1:-}
[[ $M =~ ^[0-9a-f]{40}$ ]] || { echo "G1_GO4B_DOWNLOAD=STOP;CODE=commit_argument_invalid"; exit 3; }
N=/root/wpcg-production-g1-mysql-hf-${M:0:7}
W="$N.incoming-UNVERIFIED"
BASE="https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/$M/scripts"
FILES=(production-g1-mysql.py production_g1_mysql_contract.py production-g1-mysql-manifest.json SHA256SUMS)
CODE=""
stop() { CODE=$1; echo "FAIL  $1 ${2:-}"; exit 3; }
trap 'rc=$?; if [ "$rc" -ne 0 ]; then echo "G1_GO4B_DOWNLOAD=STOP;CODE=${CODE:-unexpected_command_failure};NOTE=incoming_directory_is_unverified_nothing_was_installed"; fi' EXIT

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

printf '%s  %s\n' 01f803d3628b47f11719751e365ab95bc664189d8dfcb457bf0fec8eb460e8e2 SHA256SUMS | sha256sum -c --strict - >/dev/null || stop sha256sums_file_hash_mismatch
printf '%s  %s\n' \
  457ddb292f2ddf1f094375254a031de3bb6a450838cb201429363cb2397f02da production-g1-mysql.py \
  45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3 production_g1_mysql_contract.py \
  290ed20acfdac72f4150f572521b8f0e6564de33f7ff2979fc5b5a2381410732 production-g1-mysql-manifest.json \
  | sha256sum -c --strict - >/dev/null || stop source_file_hash_mismatch
sha256sum -c --strict SHA256SUMS >/dev/null || stop sha256sums_list_mismatch
[ "$(wc -l < SHA256SUMS)" = 3 ] || stop sha256sums_line_count

cd /
mv -T -- "$W" "$N" || stop rename_failed
cd "$N"
[ "$(ls -A | wc -l)" = 4 ] || stop file_count_not_four_after_rename
trap - EXIT
echo "G1_GO4B_DOWNLOAD=PASS $N"
