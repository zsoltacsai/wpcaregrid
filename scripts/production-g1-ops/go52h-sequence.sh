set -uo pipefail
# GO-5.2 hotfix sequence for a host that is ALREADY in the post-GO-5.1 state (provision-paths done, nothing
# initialized or started). Safe to start from any point of the sequence: it detects the installed helper (GO-4b
# hotfix 1 or the base-config hotfix), verifies the matching post-GO-5.1 state first, reuses an existing verified
# package directory, and only then replaces the two installed files (idempotent tool, rolls back on failure).
# It never runs provision-paths, initialize or start, never touches /var/lib/mysql-wpcaregrid-*, /var/tmp/wpcaregrid-*,
# the error logs, mysql.service (3306) or /etc/mysql/my.cnf. Usage: go52h-sequence.sh <40-hex merge commit>
M=${1:-}
OPS=$(cd -- "$(dirname -- "$0")" && pwd -P)
HELPER=/usr/local/libexec/wpcaregrid-g1-mysql.py
HELPER_OLD=457ddb292f2ddf1f094375254a031de3bb6a450838cb201429363cb2397f02da   # GO-4b hotfix 1 (installed)
HELPER_NEW=32da9353289e97f1c9974d544185f51b2fea45e0c926efaaf523753bfbfb528e   # base-config hotfix
PACKAGE_SUMS=2e67841f723ed2f82049645d842a45e1c9c6b581c84d79ea4abd4ae0094a2f38  # SHA256SUMS of the hotfix package
STOPPED() { echo "G1_GO52H_SEQUENCE=STOP;STEP=$1"; exit 1; }
step() { local name=$1; shift; "$@" || STOPPED "$name"; }
[[ $M =~ ^[0-9a-f]{40}$ ]] || STOPPED commit_argument_invalid
[ "$(id -u)" = 0 ] || STOPPED not_root
[ "$(stat -c '%U:%G:%a:%F' -- "$OPS")" = "root:root:700:directory" ] || STOPPED toolkit_directory_invalid
(cd -- "$OPS" && sha256sum -c --strict SHA256SUMS >/dev/null 2>&1   && [ "$(ls -A | wc -l)" = "$(( $(wc -l < SHA256SUMS) + 1 ))" ]) || STOPPED toolkit_hash_check
[ -f "$HELPER" ] && [ ! -L "$HELPER" ] || STOPPED installed_helper_missing
CUR=$(sha256sum -- "$HELPER" | cut -d' ' -f1)
case "$CUR" in
  "$HELPER_OLD") PRE=328hp;;
  "$HELPER_NEW") PRE=328h2p;;
  *) STOPPED installed_helper_unrecognized;;
esac
echo "G1_GO52H_DETECTED=$PRE"
step state_before python3 -I -B "$OPS/g1-state-check.py" "$PRE"
HF2=/root/wpcg-production-g1-mysql-hf2-${M:0:7}
if [ -e "$HF2" ] || [ -L "$HF2" ]; then
  # An existing package directory is trusted only after the same checks the fetch script applies before its rename.
  [ "$(stat -c '%U:%G:%a:%F' -- "$HF2")" = "root:root:700:directory" ] || STOPPED package_directory_invalid
  [ "$(ls -A -- "$HF2" | wc -l)" = 4 ] || STOPPED package_file_count
  for f in production-g1-mysql.py production_g1_mysql_contract.py production-g1-mysql-manifest.json SHA256SUMS; do
    [ "$(stat -c '%U:%G:%a:%F' -- "$HF2/$f")" = "root:root:600:regular file" ] || STOPPED package_file_invalid
  done
  [ "$(sha256sum -- "$HF2/SHA256SUMS" | cut -d' ' -f1)" = "$PACKAGE_SUMS" ] || STOPPED package_sums_mismatch
  (cd -- "$HF2" && sha256sum -c --strict SHA256SUMS >/dev/null 2>&1 && [ "$(wc -l < SHA256SUMS)" = 3 ]) || STOPPED package_hash_check
  echo "G1_GO52H_PACKAGE=REUSING_VERIFIED $HF2"
else
  step fetch_hotfix_package bash "$OPS/go5h-fetch-hotfix-package.sh" "$M"
fi
step manifest_check   python3 -I -B "$OPS/go3-manifest-check.py" "$HF2"
step helper_upgrade   python3 -I -B "$OPS/go5h-upgrade-installed-helper.py" "$HF2"
step state_after      python3 -I -B "$OPS/g1-state-check.py" 328h2p
step effective_config python3 -I -B "$OPS/g1-effective-config-check.py"
echo "G1_GO52H_SEQUENCE=PASS"
