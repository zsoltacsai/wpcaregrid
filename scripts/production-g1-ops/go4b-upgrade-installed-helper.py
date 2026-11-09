#!/usr/bin/env python3
"""GO-4b: replace exactly the installed G1 helper and manifest by the log-directory hotfix versions.

Why: the installed #328 helper refuses /var/log/mysql unless it is mysql:adm 02750 (provision-paths
STOP managed_directory_mode_invalid). The hotfix helper accepts 0750 and 02750. The installer is
no-overwrite by design, so this tool is the only place that replaces installed bytes, and it replaces
only these two files in /usr/local/libexec; the contract, option files, AppArmor profiles and systemd
drop-ins are not touched and must already be byte-identical to the pinned values.

Usage (root):  python3 -I -B go4b-upgrade-installed-helper.py <hotfix-package-dir>

Checks before any write: the package directory (root-owned, no symlinks) holds the two new files with
the pinned hashes; the installed helper, contract and manifest are the pinned OLD #328 files (or already
the new ones: idempotent); both G1 units are inactive. Each replacement is a same-directory temporary
file + fsync + atomic rename. After the replacement the helper's own `verify-install` runs; if it or any
step fails, the old bytes are restored. Prints one tag line:
  G1_GO4B_HELPER_UPGRADE=PASS[;NOTE=already_upgraded]                       exit 0
  G1_GO4B_HELPER_UPGRADE=STOP;CODE=<cause>[;ROLLED_BACK=yes|NO]              exit 3
"""
import hashlib
import os
import stat
import subprocess
import sys

LIBEXEC = "/usr/local/libexec"
HELPER = LIBEXEC + "/wpcaregrid-g1-mysql.py"
CONTRACT = LIBEXEC + "/production_g1_mysql_contract.py"
MANIFEST = LIBEXEC + "/production_g1_mysql_manifest.json"
OLD = {  # the #328 bb7e20e files installed by GO-4
    HELPER: "5bddfcc886c8f94653f1aa9c55c63da7dca259042156db3e7e8a5378639e215a",
    CONTRACT: "45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3",
    MANIFEST: "87f7aa3a8acee74fbf0bb697ec5e93b540fe9484927bab4212b24df71e2d22e9",
}
NEW = {  # the hotfix package
    HELPER: "457ddb292f2ddf1f094375254a031de3bb6a450838cb201429363cb2397f02da",
    CONTRACT: OLD[CONTRACT],
    MANIFEST: "290ed20acfdac72f4150f572521b8f0e6564de33f7ff2979fc5b5a2381410732",
}
SOURCE_NAMES = {HELPER: "production-g1-mysql.py", MANIFEST: "production-g1-mysql-manifest.json"}
MODES = {HELPER: 0o755, CONTRACT: 0o644, MANIFEST: 0o644}
UNITS = ("mysql@wpcaregrid-production.service", "mysql@wpcaregrid-production-restore.service")
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/nonexistent", "LANG": "C", "LC_ALL": "C"}


class Stop(Exception):
    def __init__(self, code, rolled_back=None):
        super().__init__(code)
        self.code, self.rolled_back = code, rolled_back


def read_regular(path, mode):
    """Bytes of a root-owned regular file with exactly `mode` (None: any), never through a symlink."""
    st = os.lstat(path)
    if not stat.S_ISREG(st.st_mode):
        raise Stop("not_a_regular_file:" + os.path.basename(path))
    if st.st_uid != 0 or st.st_gid != 0:
        raise Stop("owner_invalid:" + os.path.basename(path))
    if mode is not None and stat.S_IMODE(st.st_mode) != mode:
        raise Stop("mode_invalid:" + os.path.basename(path))
    with open(path, "rb") as stream:
        return stream.read()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def default_run(args):
    return subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=120,
                          check=False, close_fds=True, stdin=subprocess.DEVNULL, env=ENV)


def replace_atomic(path, content, mode):
    target = path
    temp = target + ".g1-upgrade-tmp"
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(temp, 0, 0)
        os.chmod(temp, mode)
        os.rename(temp, target)
    except BaseException:
        if os.path.lexists(temp):
            os.unlink(temp)
        raise
    directory = os.open(os.path.dirname(target), os.O_RDONLY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def main(package_dir, run=default_run, euid=None):
    """`run` and `euid` exist for tests only; production calls main(package_dir)."""
    if (os.geteuid() if euid is None else euid) != 0:
        raise Stop("not_root")
    if os.path.islink(package_dir) or not os.path.isdir(package_dir):
        raise Stop("package_directory_invalid")
    st = os.lstat(package_dir)
    if st.st_uid != 0 or stat.S_IMODE(st.st_mode) & 0o022:
        raise Stop("package_directory_owner_or_mode_invalid")
    package = {}
    for target, name in SOURCE_NAMES.items():
        package[target] = read_regular(os.path.join(package_dir, name), None)
        if sha(package[target]) != NEW[target]:
            raise Stop("package_file_hash_mismatch:" + name)
    for stale in (HELPER, MANIFEST):
        if os.path.lexists(stale + ".g1-upgrade-tmp"):
            raise Stop("leftover_temporary_file")
    current = {path: read_regular(path, MODES[path]) for path in OLD}
    state = {path: sha(data) for path, data in current.items()}
    if state[CONTRACT] != OLD[CONTRACT]:
        raise Stop("installed_contract_hash_mismatch")
    for unit in UNITS:
        done = run(["/usr/bin/systemctl", "is-active", unit])
        if done.returncode != 3 or done.stdout.strip() != "inactive":
            raise Stop("g1_unit_not_inactive")
    already = state[HELPER] == NEW[HELPER] and state[MANIFEST] == NEW[MANIFEST]
    if not already and (state[HELPER] != OLD[HELPER] or state[MANIFEST] != OLD[MANIFEST]):
        raise Stop("installed_helper_or_manifest_unrecognized")

    def verify():
        done = run(["/usr/bin/python3", "-I", HELPER, "verify-install"])
        if done.returncode != 0 or "STATIC_INSTALL_VERIFY=PASS" not in done.stdout:
            raise Stop("verify_install_failed")

    if already:
        verify()
        return "G1_GO4B_HELPER_UPGRADE=PASS;NOTE=already_upgraded"
    try:
        replace_atomic(HELPER, package[HELPER], MODES[HELPER])
        replace_atomic(MANIFEST, package[MANIFEST], MODES[MANIFEST])
        for path in (HELPER, MANIFEST):
            if sha(read_regular(path, MODES[path])) != NEW[path]:
                raise Stop("post_write_hash_mismatch")
        verify()
    except BaseException as exc:
        restored = True
        for path in (MANIFEST, HELPER):
            try:
                if sha(read_regular(path, MODES[path])) != OLD[path]:
                    replace_atomic(path, current[path], MODES[path])
                if sha(read_regular(path, MODES[path])) != OLD[path]:
                    restored = False
            except BaseException:
                restored = False
        code = exc.code if isinstance(exc, Stop) else "internal_error:" + exc.__class__.__name__
        raise Stop(code, rolled_back="yes" if restored else "NO")
    return "G1_GO4B_HELPER_UPGRADE=PASS"


if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("G1_GO4B_HELPER_UPGRADE=STOP;CODE=usage")
        raise SystemExit(2)
    try:
        print(main(sys.argv[1]))
        code = 0
    except Stop as exc:
        extra = f";ROLLED_BACK={exc.rolled_back}" if exc.rolled_back else ""
        print(f"G1_GO4B_HELPER_UPGRADE=STOP;CODE={exc.code}{extra}")
        code = 3
    except BaseException as exc:
        print(f"G1_GO4B_HELPER_UPGRADE=STOP;CODE=internal_error:{exc.__class__.__name__}")
        code = 4
    raise SystemExit(code)
