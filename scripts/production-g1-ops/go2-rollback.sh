set -euo pipefail
AA_PROFILES=/sys/kernel/security/apparmor/profiles
CODE=""
unloaded=""
removed=""
stop() { CODE=$1; echo "FAIL  $1 ${2:-}"; exit 3; }
summary() {
  local rc=$?
  if [ "$rc" -ne 0 ]; then
    echo "G1_GO2_ROLLBACK=STOP;CODE=${CODE:-unexpected_command_failure};PROFILES_UNLOADED=${unloaded:-none};FILES_REMOVED=${removed:-none};AUTOMATIC_NEXT_STEP=NONE"
  fi
}
trap summary EXIT

# Every target is named explicitly: path|sha256|mode (the #325 package, nothing else).
TARGETS=(
"/etc/apparmor.d/wpcaregrid-mysql-production-restore|8210c0193f6487cf3f90d969560020b4e611d6e986341d28397510839872a72e|644"
"/etc/apparmor.d/wpcaregrid-mysql-production|5ad9b53b5a0001d32bffbd3e56d15d92158b026e9cf645411dda95d73f6478cd|644"
"/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf|d5df1ab7132248defe4c529afedb7517d002da73295b98ad87aa50a6ffc9e8ad|644"
"/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf|644e98059d68462b66a575034b5fdfbb4dbc037569f2970ab7dfa7bdccecc017|644"
"/etc/mysql/mysql.conf.d/90-wpcaregrid-production-restore.cnf|14fb59069eb42c7d0a938799330595f195bc43265df60b385e073ba58bf80a7b|644"
"/etc/mysql/mysql.conf.d/90-wpcaregrid-production.cnf|7de16da204b888e634ea46a61e00b592bcfa69b5426a02228534b627e9567ad9|644"
"/usr/local/libexec/production_g1_mysql_manifest.json|3aa181c1fde33f04a8ddd5a16dd313675225a6032d87c3becd943da1527e0e49|644"
"/usr/local/libexec/production_g1_mysql_contract.py|669014a01366cfa5ec3f2d0e80d3eec3a44a57955a1ba8a3bd91c6772fc60029|644"
"/usr/local/libexec/wpcaregrid-g1-mysql.py|fe2f712a62d556ff25f07e53c39137639225a582afdeee1fe8ed8e16aef4c077|755"
)
DROPIN_DIRS=(
"/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d"
"/etc/systemd/system/mysql@wpcaregrid-production.service.d"
)
PROFILE_FILES=(
"/etc/apparmor.d/wpcaregrid-mysql-production"
"/etc/apparmor.d/wpcaregrid-mysql-production-restore"
)

verify_file() {  # path sha256 mode: type, owner, mode and content, immediately before it is used
  local p=$1 want=$2 mode=$3
  if [ -L "$p" ]; then stop target_is_symlink "$p"; fi
  if [ ! -e "$p" ]; then stop target_missing "$p"; fi
  if [ ! -f "$p" ]; then stop target_not_regular_file "$p"; fi
  [ "$(stat -c '%U:%G:%a' -- "$p")" = "root:root:$mode" ] || stop target_owner_or_mode_invalid "$p"
  [ "$(sha256sum -- "$p" | cut -d' ' -f1)" = "$want" ] || stop target_hash_mismatch "$p"
}
verify_dropin_dir() {
  local d=$1
  if [ -L "$d" ]; then stop dropin_dir_is_symlink "$d"; fi
  [ -d "$d" ] || stop dropin_dir_missing "$d"
  [ "$(stat -c '%U:%G:%a' -- "$d")" = "root:root:755" ] || stop dropin_dir_owner_or_mode_invalid "$d"
  [ "$(ls -A -- "$d")" = "90-wpcaregrid-g1.conf" ] || stop dropin_dir_content_unexpected "$d"
}
profile_loaded() { grep -c "^$1 (" "$AA_PROFILES" || true; }

[ "$(id -u)" = 0 ] || stop not_root
# --- pass 1: nothing is changed unless every precondition holds --------------------------
for t in "${TARGETS[@]}"; do IFS='|' read -r p h m <<<"$t"; verify_file "$p" "$h" "$m"; done
for d in "${DROPIN_DIRS[@]}"; do verify_dropin_dir "$d"; done
for u in mysql@wpcaregrid-production.service mysql@wpcaregrid-production-restore.service; do
  set +e; out=$(/usr/bin/systemctl is-active "$u" </dev/null); rc=$?; set -e
  [ "$rc" = 3 ] && [ "$out" = inactive ] || stop g1_unit_not_inactive "$u"
done
[ "$(profile_loaded wpcaregrid-mysql-production)" = 1 ] || stop apparmor_profile_state_unexpected wpcaregrid-mysql-production
[ "$(profile_loaded wpcaregrid-mysql-production-restore)" = 1 ] || stop apparmor_profile_state_unexpected wpcaregrid-mysql-production-restore
echo "PRECONDITIONS=PASS"

# --- step 1: unload the two named profiles, strictly one after the other ---------------------
if ! /usr/sbin/apparmor_parser -R "${PROFILE_FILES[0]}" </dev/null; then stop apparmor_unload_failed "${PROFILE_FILES[0]}"; fi
[ "$(profile_loaded wpcaregrid-mysql-production)" = 0 ] || stop apparmor_profile_still_loaded wpcaregrid-mysql-production
unloaded="production"
if ! /usr/sbin/apparmor_parser -R "${PROFILE_FILES[1]}" </dev/null; then stop apparmor_unload_failed "${PROFILE_FILES[1]}"; fi
[ "$(profile_loaded wpcaregrid-mysql-production-restore)" = 0 ] || stop apparmor_profile_still_loaded wpcaregrid-mysql-production-restore
unloaded="production,restore"

# --- step 2: remove the nine files, each one re-verified right before it is removed ----------
for t in "${TARGETS[@]}"; do
  IFS='|' read -r p h m <<<"$t"
  verify_file "$p" "$h" "$m"
  rm -- "$p" || stop remove_failed "$p"
  removed="$removed $p"
done

# --- step 3: remove the two (now empty) drop-in directories, then reload systemd -------------
for d in "${DROPIN_DIRS[@]}"; do rmdir -- "$d" || stop rmdir_failed "$d"; done
/usr/bin/systemctl daemon-reload </dev/null || stop daemon_reload_failed
trap - EXIT
echo "G1_GO2_ROLLBACK=PASS"
