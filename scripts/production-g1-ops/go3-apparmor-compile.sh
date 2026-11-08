set -euo pipefail
N=${1:?package directory argument required}
CODE=""; T=""
trap 'rc=$?; [ -z "$T" ] || rm -rf -- "$T"; if [ "$rc" -ne 0 ]; then echo "G1_GO3_APPARMOR_COMPILE=STOP;CODE=${CODE:-unexpected_command_failure}"; fi' EXIT
[ -x /usr/sbin/apparmor_parser ] || { CODE=apparmor_parser_missing; exit 3; }
T=$(mktemp -d); chmod 0700 "$T"
python3 -I -B - "$N" "$T" <<'PY' || { CODE=profile_generation_failed; exit 3; }
import importlib.util, sys
d, t = sys.argv[1:3]
spec = importlib.util.spec_from_file_location("g1c", f"{d}/production_g1_mysql_contract.py")
c = importlib.util.module_from_spec(spec); sys.modules["g1c"] = c; spec.loader.exec_module(c)
for path, content in c.artifact_files().items():
    if path.startswith("/etc/apparmor.d/"):
        open(f"{t}/{path.rsplit('/', 1)[1]}", "wb").write(content)
PY
for f in wpcaregrid-mysql-production wpcaregrid-mysql-production-restore; do
  [ -f "$T/$f" ] || { CODE=profile_missing; exit 3; }
  /usr/sbin/apparmor_parser -Q -T "$T/$f" </dev/null || { CODE="profile_compile_failed:$f"; exit 3; }
  echo "CHECK $f compiles OK"
done
trap - EXIT; rm -rf -- "$T"
echo "G1_GO3_APPARMOR_COMPILE=PASS"
