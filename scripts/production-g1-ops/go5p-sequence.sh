set -uo pipefail
# GO-5.2c AppArmor hotfix sequence (post-GO-5.1, post-GO-5.2 host). Detects the installed contract by hash
# (hotfix-2 contract -> state 328h2p, already upgraded -> 328ap, anything else STOP), verifies the matching state first,
# fetches or re-verifies the package directory, replaces exactly contract, manifest and the two G1 AppArmor profiles
# (idempotent tool, rolls back on failure), verifies 328ap and the effective configuration, proves that mysql.service
# (MainPID, distribution profile file/local override/loaded state) and /etc/apparmor.d/usr.sbin.mysqld are unchanged and runs the confined read-only probe.
# Never runs provision-paths, initialize or start; never touches the MySQL directories, my.cnf or mysql.service.
# Usage: go5p-sequence.sh <40-hex merge commit>
M=${1:-}
OPS=$(cd -- "$(dirname -- "$0")" && pwd -P)
HELPER=/usr/local/libexec/wpcaregrid-g1-mysql.py
CONTRACT=/usr/local/libexec/production_g1_mysql_contract.py
HELPER_PIN=32da9353289e97f1c9974d544185f51b2fea45e0c926efaaf523753bfbfb528e
CONTRACT_OLD=45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3
KERNEL_PROFILES=/sys/kernel/security/apparmor/profiles
CONTRACT_NEW=270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f
PACKAGE_SUMS=135430167c9ff42949efd2c1a123dabb3239003ecee328ac40763e170fc020cd  # SHA256SUMS of the hotfix package
STOPPED() { echo "G1_GO52C_SEQUENCE=STOP;STEP=$1"; exit 1; }
step() { local name=$1; shift; "$@" || STOPPED "$name"; }
[[ $M =~ ^[0-9a-f]{40}$ ]] || STOPPED commit_argument_invalid
[ "$(id -u)" = 0 ] || STOPPED not_root
[ "$(stat -c '%U:%G:%a:%F' -- "$OPS")" = "root:root:700:directory" ] || STOPPED toolkit_directory_invalid
(cd -- "$OPS" && sha256sum -c --strict SHA256SUMS >/dev/null 2>&1 \
  && [ "$(ls -A | wc -l)" = "$(( $(wc -l < SHA256SUMS) + 1 ))" ]) || STOPPED toolkit_hash_check
for f in "$HELPER" "$CONTRACT"; do [ -f "$f" ] && [ ! -L "$f" ] || STOPPED installed_file_missing; done
[ "$(sha256sum -- "$HELPER" | cut -d' ' -f1)" = "$HELPER_PIN" ] || STOPPED installed_helper_unrecognized
case "$(sha256sum -- "$CONTRACT" | cut -d' ' -f1)" in
  "$CONTRACT_OLD") PRE=328h2p;;
  "$CONTRACT_NEW") PRE=328ap;;
  *) STOPPED installed_contract_unrecognized;;
esac
echo "G1_GO52C_DETECTED=$PRE"
baseline() {  # unchanged-ness proof: mysql.service main PID and the distribution mysqld profile
  local pid prof
  pid=$(/usr/bin/systemctl show mysql.service -P MainPID) || return 1
  [[ $pid =~ ^[1-9][0-9]*$ ]] || return 1
  if [ -e /etc/apparmor.d/usr.sbin.mysqld ]; then prof=$(sha256sum -- /etc/apparmor.d/usr.sbin.mysqld | cut -d' ' -f1); else prof=ABSENT; fi
  kern=$(grep -E '^/usr/sbin/mysqld \(' "$KERNEL_PROFILES" 2>/dev/null | head -1)
  local_prof=ABSENT; [ -e /etc/apparmor.d/local/usr.sbin.mysqld ] && local_prof=$(sha256sum -- /etc/apparmor.d/local/usr.sbin.mysqld | cut -d' ' -f1)
  echo "MAINPID=$pid;USR_SBIN_MYSQLD_PROFILE=$prof;LOCAL_OVERRIDE=$local_prof;KERNEL_PROFILE=${kern:-NONE}"
}
BEFORE=$(baseline) || STOPPED baseline_before
echo "G1_GO52C_BASELINE_BEFORE $BEFORE"
step state_before python3 -I -B "$OPS/g1-state-check.py" "$PRE"
AP=/root/wpcg-production-g1-mysql-ap-${M:0:7}
if [ -e "$AP" ] || [ -L "$AP" ]; then
  [ "$(stat -c '%U:%G:%a:%F' -- "$AP")" = "root:root:700:directory" ] || STOPPED package_directory_invalid
  [ "$(ls -A -- "$AP" | wc -l)" = 4 ] || STOPPED package_file_count
  for f in production-g1-mysql.py production_g1_mysql_contract.py production-g1-mysql-manifest.json SHA256SUMS; do
    [ "$(stat -c '%U:%G:%a:%F' -- "$AP/$f")" = "root:root:600:regular file" ] || STOPPED package_file_invalid
  done
  [ "$(sha256sum -- "$AP/SHA256SUMS" | cut -d' ' -f1)" = "$PACKAGE_SUMS" ] || STOPPED package_sums_mismatch
  (cd -- "$AP" && sha256sum -c --strict SHA256SUMS >/dev/null 2>&1 && [ "$(wc -l < SHA256SUMS)" = 3 ]) || STOPPED package_hash_check
  echo "G1_GO52C_PACKAGE=REUSING_VERIFIED $AP"
else
  step fetch_package bash "$OPS/go5p-fetch-package.sh" "$M"
fi
step manifest_check   python3 -I -B "$OPS/go5p-manifest-check.py" "$AP"
python3 -I -B "$OPS/go5p-upgrade-apparmor.py" "$AP" || {
  # The tool has already rolled back (it reports ROLLED_BACK=yes|NO). Prove the service and the distribution profile untouched.
  AFTER_FAIL=$(baseline) || AFTER_FAIL=UNAVAILABLE
  echo "G1_GO52C_BASELINE_AFTER_FAILURE $AFTER_FAIL"
  if [ "$AFTER_FAIL" = "$BEFORE" ]; then echo "G1_GO52C_BASELINE_UNCHANGED=yes"; else echo "G1_GO52C_BASELINE_UNCHANGED=NO"; fi
  STOPPED profile_upgrade
}
step state_after      python3 -I -B "$OPS/g1-state-check.py" 328ap
step effective_config python3 -I -B "$OPS/g1-effective-config-check.py"
AFTER=$(baseline) || STOPPED baseline_after
echo "G1_GO52C_BASELINE_AFTER $AFTER"
[ "$BEFORE" = "$AFTER" ] || STOPPED mysql_service_or_distribution_profile_changed
step confined_probe   bash "$OPS/go5p-confined-probe.sh"
echo "G1_GO52C_SEQUENCE=PASS"
