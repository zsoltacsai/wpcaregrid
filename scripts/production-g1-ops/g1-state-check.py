import errno
import hashlib
import os
import re
import stat
import subprocess
import sys

# Read-only state checker for the Production G1 MySQL package (#325 / #327).
# Writes nothing, starts and stops nothing, creates no files (run with python3 -I -B).
#   325   installed #325 package (GO-1 host preflight, 90- option-file names)
#   clean state after the documented rollback (GO-2 verification, also the GO-4 precondition)
#   328   installed #328 package (GO-4 post-install check, zz- option-file names)
#   328h  #328 artifacts with the log-directory hotfix helper/manifest (after go4b-upgrade-installed-helper.py)
#   328h2 #328 artifacts with the base-config symlink hotfix helper/manifest (after go5h-upgrade-installed-helper.py)

PROFILES_AND_DROPINS = {
    "/etc/apparmor.d/wpcaregrid-mysql-production": "5ad9b53b5a0001d32bffbd3e56d15d92158b026e9cf645411dda95d73f6478cd",
    "/etc/apparmor.d/wpcaregrid-mysql-production-restore": "8210c0193f6487cf3f90d969560020b4e611d6e986341d28397510839872a72e",
    "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf": "d5df1ab7132248defe4c529afedb7517d002da73295b98ad87aa50a6ffc9e8ad",
    "/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf": "644e98059d68462b66a575034b5fdfbb4dbc037569f2970ab7dfa7bdccecc017",
}
CNF = {  # identical bytes in both packages; only the file name differs
    "wpcaregrid-production-restore": "14fb59069eb42c7d0a938799330595f195bc43265df60b385e073ba58bf80a7b",
    "wpcaregrid-production": "7de16da204b888e634ea46a61e00b592bcfa69b5426a02228534b627e9567ad9",
}
CNF_90 = {f"/etc/mysql/mysql.conf.d/90-{n}.cnf": h for n, h in CNF.items()}   # #325 / #327 names
CNF_ZZ = {f"/etc/mysql/mysql.conf.d/zz-{n}.cnf": h for n, h in CNF.items()}   # #328 names
LIBEXEC = "/usr/local/libexec/"
EXPECTED = {
    "325": {**PROFILES_AND_DROPINS, **CNF_90,
            LIBEXEC + "wpcaregrid-g1-mysql.py": "fe2f712a62d556ff25f07e53c39137639225a582afdeee1fe8ed8e16aef4c077",
            LIBEXEC + "production_g1_mysql_contract.py": "669014a01366cfa5ec3f2d0e80d3eec3a44a57955a1ba8a3bd91c6772fc60029",
            LIBEXEC + "production_g1_mysql_manifest.json": "3aa181c1fde33f04a8ddd5a16dd313675225a6032d87c3becd943da1527e0e49"},
    "328": {**PROFILES_AND_DROPINS, **CNF_ZZ,
            LIBEXEC + "wpcaregrid-g1-mysql.py": "5bddfcc886c8f94653f1aa9c55c63da7dca259042156db3e7e8a5378639e215a",
            LIBEXEC + "production_g1_mysql_contract.py": "45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3",
            LIBEXEC + "production_g1_mysql_manifest.json": "87f7aa3a8acee74fbf0bb697ec5e93b540fe9484927bab4212b24df71e2d22e9"},
    "328h": {**PROFILES_AND_DROPINS, **CNF_ZZ,
             LIBEXEC + "wpcaregrid-g1-mysql.py": "457ddb292f2ddf1f094375254a031de3bb6a450838cb201429363cb2397f02da",
             LIBEXEC + "production_g1_mysql_contract.py": "45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3",
             LIBEXEC + "production_g1_mysql_manifest.json": "290ed20acfdac72f4150f572521b8f0e6564de33f7ff2979fc5b5a2381410732"},
    "328h2": {**PROFILES_AND_DROPINS, **CNF_ZZ,
              LIBEXEC + "wpcaregrid-g1-mysql.py": "32da9353289e97f1c9974d544185f51b2fea45e0c926efaaf523753bfbfb528e",
              LIBEXEC + "production_g1_mysql_contract.py": "45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3",
              LIBEXEC + "production_g1_mysql_manifest.json": "c2b693195f542ab88d261bd65dc586c56880526a65707c135cee2d29a61dea73"},
}
INSTANCES = {
    "wpcaregrid-production": {"port": 3308, "runtime": [
        "/var/lib/mysql-wpcaregrid-production", "/var/tmp/wpcaregrid-production",
        "/var/log/mysql/wpcaregrid-production.log", "/run/mysqld/wpcaregrid-production.sock",
        "/run/mysqld/wpcaregrid-production.pid"]},
    "wpcaregrid-production-restore": {"port": 3309, "runtime": [
        "/var/lib/mysql-wpcaregrid-production-restore", "/var/tmp/wpcaregrid-production-restore",
        "/var/log/mysql/wpcaregrid-production-restore.log", "/run/mysqld/wpcaregrid-production-restore.sock",
        "/run/mysqld/wpcaregrid-production-restore.pid"]},
}
TAGS = {"325": "G1_GO1_PREFLIGHT", "clean": "G1_GO2_ROLLBACK_VERIFY", "328": "G1_GO4_POSTINSTALL",
        "328h": "G1_GO4B_POSTUPGRADE", "328h2": "G1_GO5H_POSTUPGRADE"}
VENDOR_TEMPLATE = "/usr/lib/systemd/system/mysql@.service"
VENDOR_ALIASES = ("/usr/lib/systemd/system/mysql@.service", "/lib/systemd/system/mysql@.service")
VENDOR_EXECSTART = "ExecStart=/usr/sbin/mysqld --defaults-group-suffix=@%I"
VENDOR_EXECSTARTPRE = "ExecStartPre=+/usr/share/mysql-8.4/mysql-systemd-start pre @%I"
DROPIN_DIRS = {
    "wpcaregrid-production": "/etc/systemd/system/mysql@wpcaregrid-production.service.d",
    "wpcaregrid-production-restore": "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d",
}


class Fail(Exception):
    def __init__(self, code, detail=""):
        super().__init__(code)
        self.code, self.detail = code, detail


def default_run(args):
    try:
        done = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True,
                              timeout=60, check=False, close_fds=True,
                              env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C", "LC_ALL": "C"})
    except (OSError, subprocess.TimeoutExpired):
        raise Fail("command_failed", args[0])
    return done.returncode, done.stdout


def sha256_of(path):
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def main(state, root="/", run=default_run, euid=None):
    tag = TAGS[state]
    results, infos = [], []
    R = lambda p: root.rstrip("/") + p

    def systemctl(*args):
        return run(["/usr/bin/systemctl", *args])

    def show(unit, prop):
        rc, out = systemctl("show", unit, "-P", prop)
        if rc != 0:
            raise Fail("command_failed", f"systemctl show {unit} {prop}")
        return out.strip()

    def blocks(output, code):
        found = re.findall(r"\{ ([^{}]*) \}", output)
        if len(found) != 1:
            raise Fail(code, "expected exactly one command")
        values = dict(part.split("=", 1) for part in found[0].split(" ; ") if "=" in part)
        return values

    def check(name, fn):
        try:
            fn()
            results.append((name, None))
            print(f"CHECK {name} OK")
        except Fail as exc:
            results.append((name, exc))
            print(f"FAIL  {name} {exc.code} {exc.detail}".rstrip())
        except OSError as exc:
            results.append((name, Fail("filesystem_error", f"{name}:{errno.errorcode.get(exc.errno, exc.errno)}")))
            print(f"FAIL  {name} filesystem_error {errno.errorcode.get(exc.errno, exc.errno)}")
        except Exception as exc:  # never let an unexpected error look like success or crash silently
            results.append((name, Fail("internal_error", f"{name}:{exc.__class__.__name__}")))
            print(f"FAIL  {name} internal_error {exc.__class__.__name__}")

    def lexists(path):
        return os.path.lexists(R(path))

    # ---- checks -----------------------------------------------------------------
    def c_root():
        if (os.geteuid() if euid is None else euid) != 0:
            raise Fail("not_root")

    def c_files():
        if state == "clean":
            for path in sorted({**EXPECTED["325"], **EXPECTED["328"]}):
                if lexists(path):
                    raise Fail("g1_file_still_present", path)
            for directory in DROPIN_DIRS.values():
                if lexists(directory):
                    raise Fail("g1_dropin_directory_still_present", directory)
            listing = os.listdir(R("/etc/mysql/mysql.conf.d")) if lexists("/etc/mysql/mysql.conf.d") else []
            if any(name.startswith(("90-wpcaregrid-production", "zz-wpcaregrid-production")) for name in listing):
                raise Fail("g1_config_still_present", "/etc/mysql/mysql.conf.d")
            return
        if state in ("328", "328h", "328h2"):
            for path in CNF_90:
                if lexists(path):
                    raise Fail("legacy_config_name_present", path)
        for path, digest in sorted(EXPECTED[state].items()):
            current = ""
            for part in path.split("/")[1:-1]:
                current += "/" + part
                if os.path.islink(R(current)):
                    raise Fail("installed_path_symlink_component", current)
            if not lexists(path):
                raise Fail("installed_file_missing", path)
            st = os.lstat(R(path))
            if not stat.S_ISREG(st.st_mode):
                raise Fail("installed_file_not_regular", path)
            if st.st_uid != 0 or st.st_gid != 0:
                raise Fail("installed_file_owner_invalid", path)
            mode = 0o755 if path.endswith("wpcaregrid-g1-mysql.py") else 0o644
            if stat.S_IMODE(st.st_mode) != mode:
                raise Fail("installed_file_mode_invalid", path)
            if sha256_of(R(path)) != digest:
                raise Fail("installed_file_hash_mismatch", path)
        for directory in DROPIN_DIRS.values():
            if not lexists(directory):
                raise Fail("dropin_directory_missing", directory)
            st = os.lstat(R(directory))
            if (not stat.S_ISDIR(st.st_mode) or st.st_uid != 0 or st.st_gid != 0
                    or stat.S_IMODE(st.st_mode) != 0o755):
                raise Fail("dropin_directory_invalid", directory)
            if os.listdir(R(directory)) != ["90-wpcaregrid-g1.conf"]:
                raise Fail("dropin_directory_content_unexpected", directory)

    def c_units_inactive():
        for name in INSTANCES:
            rc, out = systemctl("is-active", f"mysql@{name}.service")
            if rc != 3 or out.strip() != "inactive":
                raise Fail("g1_unit_not_inactive", name)

    def c_apparmor():
        try:
            with open(R("/sys/kernel/security/apparmor/profiles"), encoding="utf-8") as stream:
                lines = stream.read().splitlines()
        except OSError:
            raise Fail("apparmor_status_unavailable")
        for profile in ("wpcaregrid-mysql-production", "wpcaregrid-mysql-production-restore"):
            matches = [line for line in lines if line.startswith(profile + " (")]
            if state == "clean":
                if matches:
                    raise Fail("apparmor_profile_still_loaded", profile)
            elif len(matches) != 1 or not matches[0].endswith(" (enforce)"):
                raise Fail("apparmor_profile_not_enforced", profile)
        proc = R("/proc")
        for entry in os.listdir(proc):
            if not entry.isdigit():
                continue
            try:
                with open(f"{proc}/{entry}/attr/current", "rb") as stream:
                    if b"wpcaregrid-mysql" in stream.read():
                        raise Fail("apparmor_profile_attached", f"pid {entry}")
            except OSError as exc:
                if exc.errno not in (errno.ENOENT, errno.ESRCH):
                    raise Fail("apparmor_attach_scan_failed", f"pid {entry}:{errno.errorcode.get(exc.errno, exc.errno)}")

    def c_systemd():
        if lexists("/etc/systemd/system/mysql@.service.d") or lexists("/etc/systemd/system/mysql@.service"):
            raise Fail("template_override_present")
        for name in INSTANCES:
            unit = f"mysql@{name}.service"
            if lexists(f"/etc/systemd/system/{unit}"):
                raise Fail("instance_unit_override_present", unit)
            if show(unit, "FragmentPath") != VENDOR_TEMPLATE:
                raise Fail("systemd_fragment_unexpected", unit)
            dropins = show(unit, "DropInPaths").split()
            execstart = blocks(show(unit, "ExecStart"), "systemd_execstart_mismatch")
            pre = blocks(show(unit, "ExecStartPre"), "systemd_execstartpre_mismatch")
            suffix = name if state != "clean" else name.replace("-", "/")
            expected_argv = f"/usr/sbin/mysqld --defaults-group-suffix=@{suffix}"
            if execstart.get("path") != "/usr/sbin/mysqld" or execstart.get("argv[]") != expected_argv \
                    or execstart.get("ignore_errors", "no") != "no":
                raise Fail("systemd_execstart_mismatch", unit)
            if state == "clean":
                if dropins:
                    raise Fail("systemd_dropin_unexpected", unit)
                want_pre = f"/usr/share/mysql-8.4/mysql-systemd-start pre @{suffix}"
            else:
                if dropins != [f"/etc/systemd/system/{unit}.d/90-wpcaregrid-g1.conf"]:
                    raise Fail("systemd_dropin_unexpected", unit)
                want_pre = f"/usr/bin/python3 /usr/local/libexec/wpcaregrid-g1-mysql.py preflight --instance {name}"
            if pre.get("argv[]") != want_pre:
                raise Fail("systemd_execstartpre_mismatch", unit)

    def c_runtime_paths():
        for name, spec in INSTANCES.items():
            for path in spec["runtime"]:
                if lexists(path):
                    raise Fail("g1_runtime_path_present", path)

    def listening_ports():
        ports = set()
        for table in ("/proc/net/tcp", "/proc/net/tcp6"):
            if not lexists(table):
                continue
            with open(R(table), encoding="ascii") as stream:
                next(stream, None)
                for line in stream:
                    fields = line.split()
                    if len(fields) > 3 and fields[3] == "0A":
                        ports.add(int(fields[1].rsplit(":", 1)[1], 16))
        return ports

    def c_mysql_and_ports():
        rc, out = systemctl("is-active", "mysql.service")
        if rc != 0 or out.strip() != "active":
            raise Fail("mysql_service_not_active")
        pid = show("mysql.service", "MainPID")
        if not pid.isdigit() or int(pid) == 0:
            raise Fail("mysql_mainpid_invalid")
        try:
            with open(R(f"/proc/{pid}/comm"), encoding="ascii") as stream:
                if stream.read().strip() != "mysqld":
                    raise Fail("mysql_mainpid_not_mysqld", pid)
        except OSError:
            raise Fail("mysql_mainpid_unreadable", pid)
        ports = listening_ports()
        if 3306 not in ports:
            raise Fail("mysql_3306_not_listening")
        for name, spec in INSTANCES.items():
            if spec["port"] in ports:
                raise Fail("g1_port_in_use", str(spec["port"]))
        infos.append(f"mysql.service MainPID={pid}")

    def c_vendor():
        path = VENDOR_TEMPLATE
        if not lexists(path) or os.path.islink(R(path)):
            raise Fail("vendor_template_invalid", path)
        st = os.lstat(R(path))
        if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or st.st_gid != 0 or stat.S_IMODE(st.st_mode) != 0o644:
            raise Fail("vendor_template_invalid", path)
        with open(R(path), encoding="utf-8") as stream:
            text = stream.read()
        lines = [line.strip() for line in text.splitlines()]
        if VENDOR_EXECSTART not in lines or VENDOR_EXECSTARTPRE not in lines \
                or any(line.startswith("ExecStartPost=") for line in lines):
            raise Fail("vendor_template_unrecognized", path)
        infos.append(f"vendor template sha256={sha256_of(R(path))}")
        owners = None
        for alias in VENDOR_ALIASES:
            rc, out = run(["/usr/bin/dpkg-query", "-S", alias])
            if rc == 0 and out.strip():
                owners = sorted({part.strip() for line in out.splitlines() if ": " in line
                                 for part in line.rsplit(": ", 1)[0].split(",")})
                break
        if not owners:
            raise Fail("vendor_template_unowned", path)
        for package in owners:
            rc, out = run(["/usr/bin/dpkg", "--verify", package])
            if rc not in (0, 1):
                raise Fail("vendor_template_unverifiable", package)
            for line in out.splitlines():
                if line.split()[-1:] and line.split()[-1] in VENDOR_ALIASES:
                    raise Fail("vendor_template_modified", line.split()[-1])
        infos.append("vendor template package(s): " + ",".join(owners) + " verified by dpkg")

    check("root", c_root)
    check("installed_files" if state != "clean" else "g1_files_absent", c_files)
    check("g1_units_inactive", c_units_inactive)
    check("apparmor", c_apparmor)
    check("systemd_effective", c_systemd)
    check("g1_runtime_paths_absent", c_runtime_paths)
    check("mysql_3306_and_g1_ports", c_mysql_and_ports)
    check("vendor_template", c_vendor)

    for info in infos:
        print("INFO  " + info)
    failures = [exc for _, exc in results if exc is not None]
    if failures:
        print(f"{tag}=STOP;CODE={failures[0].code}")
        return 3
    print(f"{tag}=PASS")
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 2 or sys.argv[1] not in TAGS:
        print("usage: <325|clean|328|328h|328h2>")
        raise SystemExit(2)
    try:
        code = main(sys.argv[1])
    except BaseException as exc:
        print(f"{TAGS[sys.argv[1]]}=STOP;CODE=internal_error")
        code = 4
    raise SystemExit(code)
