#!/usr/bin/python3
"""WP CareGrid staging-next helper 1.4.9 - Owner GO #1 (on-host variant): stage the six pinned files.

Run as ROOT on the staging host:  python3 -I -B - check|stage   (the program text arrives on stdin).

It downloads the six files over HTTPS from the pinned commit of the public repository, verifies every
sha256 pin in memory (nothing is written before all six match), and in `stage` mode creates them under
/home/deploy/.private owned by `deploy` (dir 0700, files 0600), create-only. `check` writes NOTHING.

Safety properties: no git clone needed; HTTPS only, redirects and proxies refused, size-capped; downloaded
bytes are never executed; root writes only through directory file descriptors opened with O_NOFOLLOW and
validated with fstat (the deploy-owned tree cannot redirect root with symlinks or renames); no existing
object is overwritten or removed; atomic publish; rollback removes only what this run created; independent
read-back; no file content printed. It installs nothing and touches no sudoers/helper/service/DB/G1/legacy
staging path.

Final line, exactly one of:
  STAGING_149_STAGE=CHECK_PASS | PASS | STOP;CODE=<reason>
"""

import hashlib
import os
import pwd
import secrets
import signal
import ssl
import stat
import sys
import urllib.request

COMMIT = "49234694f062168a27b471fc43774409e6c4219a"
BASE_URL = "https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/" + COMMIT + "/scripts/"
USER = "deploy"
HOME = "/home/deploy"
EXPECTED_PW_DIR = "/home/deploy"
PRIV_NAME = ".private"
SRC_NAME = "wpcg-staging-next-helper-1.4.9"
INST_NAME = "wpcaregrid-staging-next-helper-installer-1.4.9.py"
INSTALLER_SOURCE = "install_staging_next_helper_1_4_9.py"
PINS = {
    "wpcaregrid-staging-next-deploy-priv-1_4_9.py": "7b4fda009e1d098b7886866b6b0cafc4166dccf480c5cead595c89beabadd798",
    "staging_next_deploy_backend_1_4_9.py": "68bc80ad8a87b06e19a2f9be1a52dc50f80db25183f66a39b1936f8434b1975f",
    "staging_next_db_transaction_1_4_9.py": "809faa581019a9349eaae09ed18198f164781354ba5a89de55a858258dd7d290",
    "deployment_target_binding.py": "987220381d5ea5d02aea1571e7b150f3f9596b27ff56d4b764a4a9e1bbdbb910",
    "deployment_environment_contract.py": "f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1",
    INSTALLER_SOURCE: "d26120cb4f8066aef8a754315c1cd5a5252e2f69fcc338578b44694fe09558e5",
}
MAX_FILE = 2_000_000
MIN_FREE_BYTES = 64 * 1024 * 1024
NOFOLLOW_DIR = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0)


def stop(code):
    print("STAGING_149_STAGE=STOP;CODE=" + str(code))
    sys.exit(2)


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None  # a redirect becomes an HTTPError => STOP


def fetch(name):
    """Download one pinned file: fixed HTTPS URL, no proxy, no redirect, size-capped. Returns bytes."""
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}), _NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    request = urllib.request.Request(BASE_URL + name, headers={"User-Agent": "wpcg-staging-149/1"})
    try:
        with opener.open(request, timeout=30) as response:
            if response.status != 200 or not response.geturl().startswith(BASE_URL):
                stop("download_unexpected_response:" + name)
            data = response.read(MAX_FILE + 1)
    except SystemExit:
        raise
    except Exception as error:
        stop("download_failed:" + name + ":" + type(error).__name__)
    return data


def lstat_at(name, dir_fd=None):
    try:
        return os.stat(name, dir_fd=dir_fd, follow_symlinks=False)
    except FileNotFoundError:
        return None
    except OSError:
        stop("lstat_failed")


def open_dir(name, dir_fd=None):
    try:
        return os.open(name, NOFOLLOW_DIR, dir_fd=dir_fd)
    except OSError:
        stop("directory_open_failed:" + name)


def remove_tree_at(parent_fd, name):
    """Rollback helper: remove a directory this run created (its files, then itself). Raises OSError only."""
    inner = os.open(name, NOFOLLOW_DIR, dir_fd=parent_fd)
    try:
        for entry_name in os.listdir(inner):
            os.unlink(entry_name, dir_fd=inner)
    finally:
        os.close(inner)
    os.rmdir(name, dir_fd=parent_fd)


def check_dir_fd(fd, label, *, owner, exact_mode=None, no_gow=True):
    info = os.fstat(fd)
    if not stat.S_ISDIR(info.st_mode):
        stop("directory_invalid:" + label)
    if info.st_uid != owner:
        stop("directory_owner:" + label)
    if no_gow and stat.S_IMODE(info.st_mode) & 0o022:
        stop("directory_writable_by_group_or_other:" + label)
    if exact_mode is not None and stat.S_IMODE(info.st_mode) != exact_mode:
        stop("directory_mode:" + label)


def verified_downloads(fetcher):
    files = {}
    for name, pin in PINS.items():
        try:
            data = fetcher(name)
        except SystemExit:
            raise
        except Exception as error:
            stop("download_failed:" + name + ":" + type(error).__name__)
        if not isinstance(data, bytes) or not 0 < len(data) <= MAX_FILE:
            stop("download_size_invalid:" + name)
        if hashlib.sha256(data).hexdigest() != pin:
            stop("download_hash_mismatch:" + name)
        files[name] = data
    return files


def write_new(dir_fd, name, data, uid, gid):
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0), 0o600,
                 dir_fd=dir_fd)
    try:
        view = memoryview(data)
        while view:
            view = view[os.write(fd, view):]
        os.fchown(fd, uid, gid)
        os.fchmod(fd, 0o600)
        os.fsync(fd)
    finally:
        os.close(fd)


def make_dir(parent_fd, name, uid, gid):
    os.mkdir(name, 0o700, dir_fd=parent_fd)
    fd = open_dir(name, parent_fd)
    os.fchown(fd, uid, gid)
    os.fchmod(fd, 0o700)
    return fd


def readback(priv_fd, uid, gid):
    src_fd = open_dir(SRC_NAME, priv_fd)
    try:
        check_dir_fd(src_fd, SRC_NAME, owner=uid, exact_mode=0o700)
        if sorted(os.listdir(src_fd)) != sorted(n for n in PINS if n != INSTALLER_SOURCE):
            stop("readback_contents")
        targets = [(src_fd, n, n, SRC_NAME + "/" + n) for n in PINS if n != INSTALLER_SOURCE]
        targets.append((priv_fd, INST_NAME, INSTALLER_SOURCE, INST_NAME))
        lines = []
        for parent_fd, entry, pin_name, shown in targets:
            fd = os.open(entry, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0), dir_fd=parent_fd)
            try:
                info = os.fstat(fd)
                if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_gid != gid \
                        or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1:
                    stop("readback_metadata:" + pin_name)
                digest = hashlib.sha256(os.read(fd, MAX_FILE + 1)).hexdigest()
            finally:
                os.close(fd)
            if digest != PINS[pin_name]:
                stop("readback_hash:" + pin_name)
            lines.append("FILE|" + HOME + "/" + PRIV_NAME + "/" + shown + "|" + digest + "|0600|uid=" + str(uid)
                         + "|gid=" + str(gid))
        return lines
    finally:
        os.close(src_fd)


def terminated(signum, frame):
    stop("terminated_by_signal_" + str(signum))


def main(mode=None, fetcher=None):
    mode = mode if mode is not None else (sys.argv[1] if len(sys.argv) == 2 else "")
    if mode not in ("check", "stage"):
        stop("usage_check_or_stage")
    for name in ("SIGHUP", "SIGTERM", "SIGINT"):
        signal.signal(getattr(signal, name), terminated)
    if os.geteuid() != 0:
        stop("must_run_as_root")
    try:
        entry = pwd.getpwnam(USER)
    except KeyError:
        stop("deploy_user_missing")
    uid, gid = entry.pw_uid, entry.pw_gid
    if uid == 0 or entry.pw_dir != EXPECTED_PW_DIR:
        stop("deploy_user_identity_invalid")
    for production in ("/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production"):
        if lstat_at(production) is not None:
            stop("production_paths_present")

    # trusted parents: root-owned, real directories, not writable by group/other
    for path in ("/", "/home"):
        info = lstat_at(path)
        if info is None or not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != 0 \
                or stat.S_IMODE(info.st_mode) & 0o022:
            stop("untrusted_parent:" + path)
    try:
        vfs = os.statvfs("/home")
        if vfs.f_bavail * vfs.f_frsize < MIN_FREE_BYTES or vfs.f_flag & 1:
            stop("disk_space_or_read_only")
    except OSError:
        stop("statvfs_failed")

    home_fd = open_dir(HOME)
    priv_fd = None
    try:
        check_dir_fd(home_fd, HOME, owner=uid)
        priv_info = lstat_at(PRIV_NAME, home_fd)
        if priv_info is not None:
            priv_fd = open_dir(PRIV_NAME, home_fd)
            check_dir_fd(priv_fd, HOME + "/" + PRIV_NAME, owner=uid, exact_mode=0o700)
            if lstat_at(SRC_NAME, priv_fd) is not None or lstat_at(INST_NAME, priv_fd) is not None:
                stop("destination_exists")

        files = verified_downloads(fetcher or fetch)
        print("PRECONDITIONS=PASS;DOWNLOAD=VERIFIED;FILES=" + str(len(files)) + ";MODE=" + mode)
        if mode == "check":
            print("STAGING_149_STAGE=CHECK_PASS")
            return

        made = []          # ("empty"|"tree"|"file", parent_fd, name) created by THIS run
        temp = None        # (parent_fd, name)
        temp_fd = src_fd = None
        try:
            if priv_fd is None:
                os.mkdir(PRIV_NAME, 0o700, dir_fd=home_fd)
                made.append(("empty", home_fd, PRIV_NAME))
                priv_fd = open_dir(PRIV_NAME, home_fd)
                os.fchown(priv_fd, uid, gid)
                os.fchmod(priv_fd, 0o700)
                check_dir_fd(priv_fd, HOME + "/" + PRIV_NAME, owner=uid, exact_mode=0o700)
            temp_name = ".stage149." + secrets.token_hex(8)
            temp_fd = make_dir(priv_fd, temp_name, uid, gid)
            temp = (priv_fd, temp_name)
            src_fd = make_dir(temp_fd, "src", uid, gid)
            for name, data in files.items():
                if name != INSTALLER_SOURCE:
                    write_new(src_fd, name, data, uid, gid)
            write_new(temp_fd, "installer", files[INSTALLER_SOURCE], uid, gid)
            os.fsync(src_fd)
            os.fsync(temp_fd)
            if lstat_at(SRC_NAME, priv_fd) is not None or lstat_at(INST_NAME, priv_fd) is not None:
                raise RuntimeError("destination_appeared")
            os.rename("src", SRC_NAME, src_dir_fd=temp_fd, dst_dir_fd=priv_fd)
            made.append(("tree", priv_fd, SRC_NAME))
            os.link("installer", INST_NAME, src_dir_fd=temp_fd, dst_dir_fd=priv_fd, follow_symlinks=False)
            made.append(("file", priv_fd, INST_NAME))
            os.unlink("installer", dir_fd=temp_fd)
            os.rmdir(temp_name, dir_fd=priv_fd)
            temp = None
            os.fsync(priv_fd)
            report = readback(priv_fd, uid, gid)
        except BaseException as error:
            # roll back only what this run created: the temporary tree first, then published objects
            if temp is not None:
                try:
                    remove_tree_at(temp_fd, "src")
                except OSError:
                    pass
                try:
                    for entry_name in os.listdir(temp_fd):
                        os.unlink(entry_name, dir_fd=temp_fd)
                    os.rmdir(temp[1], dir_fd=temp[0])
                except OSError:
                    pass
            for kind, parent_fd, name in reversed(made):
                try:
                    if kind == "file":
                        os.unlink(name, dir_fd=parent_fd)
                    elif kind == "tree":
                        remove_tree_at(parent_fd, name)
                    else:
                        os.rmdir(name, dir_fd=parent_fd)
                except OSError:
                    pass
            if isinstance(error, SystemExit):
                raise
            stop("stage_failed:" + type(error).__name__)
        finally:
            for fd in (src_fd, temp_fd):
                if fd is not None:
                    try:
                        os.close(fd)
                    except OSError:
                        pass
        for line in report:
            print(line)
        print("STAGING_149_STAGE=PASS")
    finally:
        for fd in (priv_fd, home_fd):
            if fd is not None:
                try:
                    os.close(fd)
                except OSError:
                    pass


def run():
    """Entry point: whatever happens, the output contract (one final STAGING_149_STAGE= line) holds."""
    try:
        main()
    except SystemExit:
        raise
    except BaseException as error:
        stop("unexpected_error:" + type(error).__name__)


if __name__ == "__main__":
    run()
