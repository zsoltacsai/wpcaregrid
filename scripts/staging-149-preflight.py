#!/usr/bin/python3
"""WP CareGrid staging-next helper 1.4.9 - READ-ONLY host preflight (run as root on the staging host).

Guarantees: creates/modifies/deletes nothing, installs nothing, starts or stops nothing, opens no network
connection, reads no secret (no .env, password, token, key, backup content), prints no file content.
Only lstat/stat/statvfs, reads of non-secret system files (/etc/os-release, /proc, nginx and php-fpm
config, sudoers rule files, MySQL auto.cnf for the instance UUID digest only) and a fixed list of read-only
commands (systemctl show, supervisorctl status, ps, visudo -c, php8.5 -n -r, mysqld --version) are used.

Run it as:  /usr/bin/python3 -I -B scripts/staging-149-preflight.py   (or pasted via stdin: python3 -I -B -)

Final line, exactly one of:
  STAGING_149_PREFLIGHT=PASS
  STAGING_149_PREFLIGHT=BLOCKED;CODE=<first blocker>
  STAGING_149_PREFLIGHT=STOP;CODE=<first stop condition>
PASS means every prerequisite of the 1.4.9 helper INSTALLATION is proven. NEXT_GATE lines describe later
steps (Phase B, MySQL, first deploy) and never change the result.
"""

import glob
import hashlib
import os
import pwd
import re
import socket
import stat
import subprocess
import sys

DEPLOY_UID = 996
FPM_UID = FPM_GID = 33
HOST = "staging-next.wpcaregrid.com"
SOCKET = "/run/php/php8.5-fpm.sock"
APP_DIR = "/var/www/wpcaregrid-staging-new"
DB = "wpcg_staging_next"
MYSQL_UUID_SHA256 = "bc35682d80f0894c2c2c2879552fb8fe13dc2a530868d79d817a6280e31bde1c"
BASE = "/var/lib/wpcaregrid-deploy/staging-next"
LIBEXEC = "/usr/local/libexec/wpcaregrid-deploy/staging-next"
VERSION = "1.4.9"
SOURCE_DIR = "/home/deploy/.private/wpcg-staging-next-helper-1.4.9"
SOURCE_PINS = {
    "wpcaregrid-staging-next-deploy-priv-1_4_9.py": "7b4fda009e1d098b7886866b6b0cafc4166dccf480c5cead595c89beabadd798",
    "staging_next_deploy_backend_1_4_9.py": "68bc80ad8a87b06e19a2f9be1a52dc50f80db25183f66a39b1936f8434b1975f",
    "staging_next_db_transaction_1_4_9.py": "809faa581019a9349eaae09ed18198f164781354ba5a89de55a858258dd7d290",
    "deployment_target_binding.py": "987220381d5ea5d02aea1571e7b150f3f9596b27ff56d4b764a4a9e1bbdbb910",
    "deployment_environment_contract.py": "f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1",
}
INSTALLER = "/home/deploy/.private/wpcaregrid-staging-next-helper-installer-1.4.9.py"
INSTALLER_SHA256 = "d26120cb4f8066aef8a754315c1cd5a5252e2f69fcc338578b44694fe09558e5"
G1_ARTIFACTS = {
    "/etc/apparmor.d/wpcaregrid-mysql-production": "5ad9b53b5a0001d32bffbd3e56d15d92158b026e9cf645411dda95d73f6478cd",
    "/etc/apparmor.d/wpcaregrid-mysql-production-restore": "8210c0193f6487cf3f90d969560020b4e611d6e986341d28397510839872a72e",
    "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf": "14fb59069eb42c7d0a938799330595f195bc43265df60b385e073ba58bf80a7b",
    "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf": "7de16da204b888e634ea46a61e00b592bcfa69b5426a02228534b627e9567ad9",
    "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf": "d5df1ab7132248defe4c529afedb7517d002da73295b98ad87aa50a6ffc9e8ad",
    "/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf": "644e98059d68462b66a575034b5fdfbb4dbc037569f2970ab7dfa7bdccecc017",
    "/usr/local/libexec/production_g1_mysql_contract.py": "45a9cab39c1276b7c9585c63b4204867ea8696b392589f750ba36cac972397a3",
    "/usr/local/libexec/wpcaregrid-g1-mysql.py": "5bddfcc886c8f94653f1aa9c55c63da7dca259042156db3e7e8a5378639e215a",
}
SUDOERS_LINE = re.compile(
    r"deploy-staging-next ALL=\(root\) NOPASSWD: NOSETENV: "
    r"/usr/local/libexec/wpcaregrid-deploy/staging-next/[0-9]+\.[0-9]+(?:\.[0-9]+)?/"
    r"wpcaregrid-deploy-staging-next-priv (?:preflight|verify-install|authorize \"[^\"\n]+\"|"
    r"stage \"[^\"\n]+\"|deploy \"[^\"\n]+\")\Z")
MIB = 1024 * 1024


class Host:
    """Filesystem/command access. `root` is "/" in production; tests point it at a fixture tree."""

    def __init__(self, root="/", euid=None, root_uid=0, runner=None, hostname=None):
        self.root = root
        self.euid = os.geteuid() if euid is None else euid
        self.root_uid = root_uid
        self.runner = runner or self._run
        self.hostname = hostname or socket.gethostname()

    def p(self, path):
        return path if self.root == "/" else self.root.rstrip("/") + path

    def lstat(self, path):
        try:
            return os.lstat(self.p(path)), None
        except FileNotFoundError:
            return None, "absent"
        except OSError:
            return None, "error"

    def read(self, path, limit=262144):
        try:
            fd = os.open(self.p(path), os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            try:
                if not stat.S_ISREG(os.fstat(fd).st_mode):
                    return None
                chunks, total = [], 0
                while total < limit:
                    chunk = os.read(fd, min(65536, limit - total))
                    if not chunk:
                        break
                    chunks.append(chunk)
                    total += len(chunk)
                return b"".join(chunks)
            finally:
                os.close(fd)
        except OSError:
            return None

    def read_nginx(self, path, limit=2_000_000):
        return self.read_follow(path, ("/etc/nginx", "/etc/letsencrypt", "/usr/share/nginx"), limit)

    def read_follow(self, path, trees, limit=262144):
        """Like read(), but follows a symlink only when its final target stays inside one of `trees`."""
        info, _ = self.lstat(path)
        if info is None:
            return None
        if not stat.S_ISLNK(info.st_mode):
            return self.read(path, limit)
        real = os.path.realpath(self.p(path))
        allowed = [os.path.realpath(self.p(x)) for x in trees]
        if not any(real == a or real.startswith(a + "/") for a in allowed):
            return None
        shown = real if self.root == "/" else real[len(os.path.realpath(self.root)):]
        return self.read(shown, limit)

    def listdir(self, path):
        try:
            return sorted(os.listdir(self.p(path)))
        except OSError:
            return None

    def _run(self, argv, timeout=8):
        if self.root != "/" or not os.path.exists(argv[0]):
            return None
        try:
            done = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                  stderr=subprocess.DEVNULL, timeout=timeout, check=False,
                                  env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C"})
        except (OSError, subprocess.TimeoutExpired):
            return None
        return done.returncode, done.stdout[:1_000_000]

    def run(self, argv, timeout=8):
        return self.runner(argv, timeout)


class Report:
    def __init__(self):
        self.blockers = []
        self.stops = []

    def line(self, cid, status, detail="", effect=None, code=None):
        text = re.sub(r"[^\x20-\x7e]", "?", str(detail))[:300]
        print(f"CHECK|{cid}|{status}|{text}")
        if effect and status in ("MISMATCH", "UNKNOWN", "BLOCKED", "STOP"):
            (self.stops if effect == "STOP" else self.blockers).append((code or cid, cid))

    def final(self):
        if self.stops:
            print(f"STAGING_149_PREFLIGHT=STOP;CODE={self.stops[0][0]}")
        elif self.blockers:
            print(f"STAGING_149_PREFLIGHT=BLOCKED;CODE={self.blockers[0][0]}")
        else:
            print("STAGING_149_PREFLIGHT=PASS")
        return 2 if self.stops else 1 if self.blockers else 0


def mode_of(info):
    return stat.S_IMODE(info.st_mode)


def chain(h, path):
    """Return None when no existing component is a symlink, else the offending component."""
    cursor = ""
    for part in [p for p in path.split("/") if p]:
        cursor += "/" + part
        info, err = h.lstat(cursor)
        if info is None:
            return None
        if stat.S_ISLNK(info.st_mode):
            return cursor
    return None


def dir_check(h, r, cid, path, *, uid=None, mode=None, required, effect_absent="BLOCKED", code=None, gate="NEXT_GATE"):
    """REQUIRED_PRESENT / EXPECTED_ABSENT / MISMATCH / UNKNOWN for one directory."""
    uid = h.root_uid if uid is None else uid
    link = chain(h, path)
    if link:
        r.line(cid, "MISMATCH", f"symlink component {link}", "STOP", "symlink_in_" + (code or cid))
        return
    info, err = h.lstat(path)
    if err == "error":
        r.line(cid, "UNKNOWN", f"{path} unreadable", "BLOCKED", (code or cid) + "_unknown")
    elif info is None:
        if required:
            r.line(cid, "MISMATCH", f"{path} missing (required)", effect_absent, (code or cid) + "_missing")
        else:
            r.line(cid, "EXPECTED_ABSENT", f"{path} ({gate}: create before first deploy)")
    elif not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or mode_of(info) & 0o022 or \
            (mode is not None and mode_of(info) != mode):
        r.line(cid, "MISMATCH", f"{path} uid={info.st_uid} mode={mode_of(info):o} expected uid={uid} "
               f"mode={mode if mode is None else format(mode, 'o')}", "STOP" if not required else "BLOCKED",
               (code or cid) + "_metadata")
    else:
        r.line(cid, "REQUIRED_PRESENT" if required else "PRESENT", f"{path} uid={info.st_uid} mode={mode_of(info):o}")


def root_chain(h, r, cid, path, terminal_mode=None):
    """Mirror of the installer's _check_directory_chain: every component root-owned, not a symlink,
    a directory, not group/other writable; optional exact terminal mode."""
    cursor = ""
    for index, part in enumerate([""] + [x for x in path.split("/") if x]):
        cursor = cursor + "/" + part if part else "/"
        cursor = cursor.replace("//", "/")
        info, err = h.lstat(cursor)
        last = cursor.rstrip("/") == path.rstrip("/")
        if info is None:
            r.line(cid, "MISMATCH" if err == "absent" else "UNKNOWN", f"{cursor} missing/unreadable (required)",
                   "BLOCKED", "installer_directory_missing")
            return
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != h.root_uid \
                or mode_of(info) & 0o022 or (last and terminal_mode is not None and mode_of(info) != terminal_mode):
            r.line(cid, "MISMATCH", f"{cursor} uid={info.st_uid} mode={mode_of(info):o} (installer needs root-owned, "
                   f"non-symlink, not group/other writable" + (f", mode {terminal_mode:o}" if terminal_mode and last else "") + ")",
                   "BLOCKED", "installer_directory_metadata")
            return
    r.line(cid, "REQUIRED_PRESENT", path)


def source_chain_problems(h, deploy_uid):
    """Mirror of the installer's _check_source_directory_chain."""
    problems, cursor = [], ""
    parts = [x for x in SOURCE_DIR.split("/") if x]
    for index, part in enumerate(parts):
        cursor += "/" + part
        info, _ = h.lstat(cursor)
        if info is None or not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            problems.append("source_parent_invalid:" + cursor)
            break
        if index < len(parts) - 1 and mode_of(info) & 0o022:
            problems.append("source_parent_writable:" + cursor)
        if index == len(parts) - 1 and (info.st_uid != deploy_uid or mode_of(info) != 0o700):
            problems.append("source_dir_owner_or_mode")
    return problems


# ---------------------------------------------------------------------------------------------- nginx
def nginx_tokens(text):
    out, i, n = [], 0, len(text)
    while i < n:
        c = text[i]
        if c.isspace():
            i += 1
        elif c == "#":
            while i < n and text[i] != "\n":
                i += 1
        elif c in "{};":
            out.append(c)
            i += 1
        elif c in "\"'":
            j, buf = i + 1, []
            while j < n and text[j] != c:
                if text[j] == "\\" and j + 1 < n:
                    j += 1
                buf.append(text[j])
                j += 1
            out.append("".join(buf))
            i = j + 1
        else:
            j = i
            while j < n and not text[j].isspace() and text[j] not in "{};":
                j += 1
            out.append(text[i:j])
            i = j
    return out


def nginx_parse(tokens):
    pos = 0

    def block():
        nonlocal pos
        items, args = [], []
        while pos < len(tokens):
            token = tokens[pos]
            pos += 1
            if token == ";":
                if args:
                    items.append((args, None))
                    args = []
            elif token == "{":
                items.append((args, block()))
                args = []
            elif token == "}":
                return items
            else:
                args.append(token)
        return items

    return block()


def nginx_load(h, path, depth=0, seen=None):
    seen = seen if seen is not None else [0]
    seen[0] += 1
    raw = h.read_nginx(path, 2_000_000)
    if raw is None or depth > 10 or seen[0] > 300:
        raise ValueError("unreadable")
    return nginx_expand(h, nginx_parse(nginx_tokens(raw.decode("utf-8", "replace"))), depth, seen)


def nginx_expand(h, items, depth, seen):
    out = []
    for args, kids in items:
        if args[:1] == ["include"] and len(args) == 2:
            pattern = args[1] if args[1].startswith("/") else "/etc/nginx/" + args[1]
            files = sorted(glob.glob(h.p(pattern))) if "*" in pattern or "?" in pattern else [h.p(pattern)]
            for file in files:
                shown = file if h.root == "/" else file[len(h.root.rstrip("/")):]
                out.extend(nginx_load(h, shown, depth + 1, seen))
        elif kids is not None:
            out.append((args, nginx_expand(h, kids, depth, seen)))
        else:
            out.append((args, None))
    return out


def nginx_walk(items):
    for args, kids in items:
        yield args, kids
        if kids:
            yield from nginx_walk(kids)


def nginx_route(h):
    """Return (state, detail). Mirrors the helper's own route check, without running nginx."""
    try:
        tree = nginx_load(h, "/etc/nginx/nginx.conf")
    except (ValueError, OSError, RecursionError):
        return "UNKNOWN", "nginx config unreadable, symlink outside nginx trees, or limits exceeded"
    servers = []
    for args, kids in nginx_walk(tree):
        if args == ["server"] and kids is not None:
            directives = list(nginx_walk(kids))
            names = [a for d, _ in directives if d[:1] == ["server_name"] for a in d[1:]]
            listens = [d[1:] for d, _ in directives if d[:1] == ["listen"]]
            if HOST in names and any("ssl" in listen and any(re.search(r"(?:^|:)443$", x) for x in listen)
                                     for listen in listens):
                servers.append(kids)
    if len(servers) != 1:
        return "MISMATCH", f"https vhosts for {HOST}: {len(servers)} (need exactly 1)"
    direct = [d[1] for d, kids in servers[0] if d[:1] == ["fastcgi_pass"] and len(d) == 2]
    in_locations = [nested[1] for d, kids in servers[0] if d[:1] == ["location"] and kids is not None
                    for nested, _ in nginx_walk(kids) if nested[:1] == ["fastcgi_pass"] and len(nested) == 2]
    passes = direct + in_locations
    if not in_locations:
        return "MISMATCH", "no fastcgi_pass inside a location of the staging-next vhost"
    wrong = sorted({p for p in passes if p != f"unix:{SOCKET}"})
    if wrong:
        return "MISMATCH", "fastcgi_pass targets differ from the php8.5 socket: " + ",".join(wrong)[:150]
    return "REQUIRED_PRESENT", f"{len(passes)} fastcgi_pass -> unix:{SOCKET}"


# ---------------------------------------------------------------------------------------------- helpers
def sha256_file(h, path):
    raw = h.read(path, 4_000_000)
    return None if raw is None else hashlib.sha256(raw).hexdigest()


def unit_state(h, unit):
    out = h.run(["/usr/bin/systemctl", "show", "--no-pager", "-p", "LoadState", "-p", "ActiveState",
                 "-p", "UnitFileState", unit])
    if out is None or out[0] != 0:
        return None
    return dict(line.split("=", 1) for line in out[1].decode("ascii", "replace").splitlines() if "=" in line)


def listening_ports(h):
    ports = set()
    for name in ("/proc/net/tcp", "/proc/net/tcp6"):
        raw = h.read(name, 4_000_000)
        for row in (raw or b"").decode("ascii", "replace").splitlines()[1:]:
            fields = row.split()
            if len(fields) > 3 and fields[3] == "0A":
                ports.add(int(fields[1].rsplit(":", 1)[1], 16))
    return ports if h.read("/proc/net/tcp") is not None else None


def flock_held(h, info):
    raw = h.read("/proc/locks", 8_000_000)
    if raw is None:
        return None
    needle = f"{os.major(info.st_dev):02x}:{os.minor(info.st_dev):02x}:{info.st_ino}"
    return any(needle in row.split() for row in raw.decode("ascii", "replace").splitlines())


def mountinfo(h, path):
    best = ("/", "unknown", "")
    for row in (h.read("/proc/self/mountinfo") or b"").decode("utf-8", "replace").splitlines():
        parts = row.split(" ")
        if len(parts) > 9 and "-" in parts:
            point = parts[4].replace("\\040", " ")
            tail = parts[parts.index("-") + 1:]
            if (path == point or path.startswith(point.rstrip("/") + "/")) and len(point) >= len(best[0]):
                best = (point, tail[0] if tail else "unknown", parts[5])
    return best


# ---------------------------------------------------------------------------------------------- checks
def check_host_and_resources(h, r):
    r.line("env.root_identity", "REQUIRED_PRESENT" if h.euid == h.root_uid else "MISMATCH", f"euid={h.euid}",
           "BLOCKED", "not_root")
    r.line("env.hostname", "INFO", h.hostname)
    for name in ("/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production"):
        info, _ = h.lstat(name)
        r.line("env.not_production:" + name, "MISMATCH" if info else "EXPECTED_ABSENT", name,
               "STOP" if info else None, "production_paths_present")
    osr = (h.read_follow("/etc/os-release", ("/usr/lib", "/etc")) or b"").decode("utf-8", "replace")
    pretty = re.search(r'^PRETTY_NAME="?([^"\n]*)', osr, re.M)
    version = re.search(r'^VERSION_ID="?([^"\n]*)', osr, re.M)
    r.line("os.release", "INFO" if version else "UNKNOWN",
           f"{pretty.group(1) if pretty else '?'} (target platform: Ubuntu 26.04.1 LTS)")
    r.line("os.kernel", "INFO", " ".join(os.uname()[2:3]) if h.root == "/" else "fixture")
    for path in ("/", "/usr/local", "/etc", "/var/lib", "/home"):
        try:
            vfs = os.statvfs(h.p(path))
        except OSError:
            r.line("os.disk:" + path, "UNKNOWN", "statvfs failed", "BLOCKED", "disk_unknown")
            continue
        free = vfs.f_bavail * vfs.f_frsize // MIB
        _, fstype, _ = mountinfo(h, path)
        bad = free < 512 or bool(vfs.f_flag & 1)
        r.line("os.disk:" + path, "MISMATCH" if bad else "INFO", f"free={free}MiB fs={fstype} ro={bool(vfs.f_flag & 1)}",
               "BLOCKED", "disk_low_or_read_only")
    mem = (h.read("/proc/meminfo") or b"").decode("ascii", "replace")
    total = re.search(r"MemTotal:\s+(\d+) kB", mem)
    avail = re.search(r"MemAvailable:\s+(\d+) kB", mem)
    if total and avail:
        low = int(avail.group(1)) // 1024 < 256
        r.line("os.memory", "MISMATCH" if low else "INFO",
               f"total={int(total.group(1)) // 1024}MiB available={int(avail.group(1)) // 1024}MiB",
               "BLOCKED", "memory_low")
    else:
        r.line("os.memory", "UNKNOWN", "meminfo unreadable", "BLOCKED", "memory_unknown")
    versions = [n for n in (h.listdir("/usr/bin") or []) if re.fullmatch(r"php[0-9]\.[0-9]+", n)]
    r.line("os.php_binaries", "INFO", ",".join(versions) or "none")


def check_php_and_web(h, r):
    binary = h.p("/usr/bin/php8.5")
    if not os.path.exists(binary) or not os.access(binary, os.X_OK):
        only84 = os.path.exists(h.p("/usr/bin/php8.4"))
        r.line("php85.cli", "BLOCKED", "php8.5 CLI missing" + ("; only PHP 8.4 is available" if only84 else ""),
               "BLOCKED", "php85_unavailable")
    else:
        out = h.run(["/usr/bin/php8.5", "-n", "-r", "echo PHP_VERSION;"])
        ok = out is not None and out[0] == 0 and re.fullmatch(r"8\.5\.[0-9]+.*", out[1].decode("ascii", "replace").strip())
        r.line("php85.cli", "REQUIRED_PRESENT" if ok else "UNKNOWN",
               out[1].decode("ascii", "replace").strip()[:40] if out else "not executable/timeout",
               "BLOCKED", "php85_unavailable")
    state = unit_state(h, "php8.5-fpm.service")
    active = bool(state) and state.get("ActiveState") == "active"
    r.line("php85.fpm.unit", "REQUIRED_PRESENT" if active else ("UNKNOWN" if state is None else "MISMATCH"),
           f"{state.get('ActiveState')}/{state.get('UnitFileState')}" if state else "systemctl unavailable",
           "BLOCKED", "php85_fpm_inactive")
    info, err = h.lstat(SOCKET)
    if info is None:
        r.line("php85.fpm.socket", "MISMATCH" if err == "absent" else "UNKNOWN", SOCKET + " missing/unreadable",
               "BLOCKED", "php85_socket_missing")
    else:
        good = stat.S_ISSOCK(info.st_mode) and (info.st_uid, info.st_gid) == (FPM_UID, FPM_GID)
        r.line("php85.fpm.socket", "REQUIRED_PRESENT" if good else "MISMATCH",
               f"uid={info.st_uid} gid={info.st_gid} mode={mode_of(info):o} sock={stat.S_ISSOCK(info.st_mode)}",
               "BLOCKED", "php85_socket_identity")
    pool = (h.read("/etc/php/8.5/fpm/pool.d/www.conf") or b"").decode("utf-8", "replace")
    listen = re.search(r"^\s*listen\s*=\s*(\S+)", pool, re.M)
    r.line("php85.fpm.pool", "REQUIRED_PRESENT" if listen and listen.group(1) == SOCKET else "MISMATCH",
           f"listen={listen.group(1) if listen else 'unknown'}", "BLOCKED", "php85_pool_listen_mismatch")
    web = unit_state(h, "nginx.service")
    r.line("nginx.unit", "REQUIRED_PRESENT" if web and web.get("ActiveState") == "active" else "MISMATCH",
           web.get("ActiveState") if web else "unavailable", "BLOCKED", "nginx_inactive")
    ports = listening_ports(h)
    r.line("nginx.port443", "REQUIRED_PRESENT" if ports and 443 in ports else "MISMATCH" if ports is not None else "UNKNOWN",
           "443 listening" if ports and 443 in ports else "443 not listening", "BLOCKED", "nginx_443_not_listening")
    state_name, detail = nginx_route(h)
    r.line("nginx.staging_next_route", state_name, detail, "BLOCKED", "nginx_route_" + state_name.lower())
    for tool in ("/usr/sbin/nginx", "/usr/sbin/visudo", "/usr/bin/python3"):
        present = os.path.exists(h.p(tool))
        r.line("installer.tool:" + tool, "REQUIRED_PRESENT" if present else "MISMATCH", tool,
               "BLOCKED", "installer_tool_missing")


def check_environment(h, r):
    app, err = h.lstat(APP_DIR)
    if app is None:
        r.line("env.application_dir", "MISMATCH", f"{APP_DIR} missing (installer requires a real directory)",
               "BLOCKED", "app_dir_missing")
    elif stat.S_ISLNK(app.st_mode) or not stat.S_ISDIR(app.st_mode):
        r.line("env.application_dir", "MISMATCH", "symlink or not a directory (installer requires a real directory)",
               "BLOCKED", "app_dir_invalid")
    else:
        r.line("env.application_dir", "REQUIRED_PRESENT", f"uid={app.st_uid} mode={mode_of(app):o}")
    layout = (
        ("state_root", BASE, None, False), ("state", BASE + "/state", 0o700, False),
        ("receipts", BASE + "/receipts", 0o700, False), ("releases", BASE + "/releases", 0o755, False),
        ("inbox", BASE + "/inbox", 0o755, False), ("shared", BASE + "/shared", None, False),
        ("shared_storage", BASE + "/shared/storage", None, False), ("shared_vendor", BASE + "/shared/vendor", None, False),
        ("backups", BASE + "/database-backups", 0o700, False),
    )
    for name, path, mode, required in layout:
        dir_check(h, r, "layout." + name, path, mode=mode, required=required, code="staging_next_" + name)
    dir_check(h, r, "layout.incoming", BASE + "/incoming", uid=DEPLOY_UID, mode=0o700, required=False,
              code="staging_next_incoming")
    env, err = h.lstat(BASE + "/shared/.env")
    r.line("layout.shared_env", "MISMATCH" if env and (stat.S_ISLNK(env.st_mode) or not stat.S_ISREG(env.st_mode))
           else "PRESENT" if env else "EXPECTED_ABSENT",
           f"metadata only uid={env.st_uid} mode={mode_of(env):o} size={env.st_size}" if env else "not created yet (content never read)",
           "STOP" if env and (stat.S_ISLNK(env.st_mode) or not stat.S_ISREG(env.st_mode)) else None, "shared_env_invalid")
    lock, err = h.lstat(BASE + "/state/deploy.lock")
    if lock is None:
        r.line("lock.deploy", "EXPECTED_ABSENT", "no deploy.lock")
    elif not stat.S_ISREG(lock.st_mode) or lock.st_uid != h.root_uid or mode_of(lock) != 0o600:
        r.line("lock.deploy", "MISMATCH", f"uid={lock.st_uid} mode={mode_of(lock):o}", "STOP", "deploy_lock_metadata")
    else:
        held = flock_held(h, lock)
        r.line("lock.deploy", "UNKNOWN" if held is None else "MISMATCH" if held else "PRESENT",
               "flock held by a process" if held else "file present, not locked" if held is False else "locks unreadable",
               "STOP" if held else "BLOCKED" if held is None else None, "deploy_lock_held" if held else "deploy_lock_unknown")
    for name, content in (("workers-disabled", b"schema=1;profile=staging-next;workers=disabled\n"),
                          ("scheduler-paused", b"schema=1;profile=staging-next;scheduler=paused\n")):
        path = f"{BASE}/state/{name}"
        info, err = h.lstat(path)
        if info is None:
            r.line("fence." + name, "EXPECTED_ABSENT" if err == "absent" else "UNKNOWN", "no fence marker (idle)",
                   "BLOCKED" if err != "absent" else None, "fence_unknown")
        else:
            valid = stat.S_ISREG(info.st_mode) and info.st_uid == h.root_uid and mode_of(info) == 0o600 \
                and h.read(path, 512) == content
            r.line("fence." + name, "MISMATCH", "fence marker present (" + ("valid" if valid else "invalid") + ") - release/recovery gate active",
                   "STOP", "fence_marker_present")
    gate, _ = h.lstat(BASE + "/shared/storage/framework/down")
    r.line("gate.maintenance", "MISMATCH" if gate else "EXPECTED_ABSENT", "maintenance gate file " + ("present" if gate else "absent"),
           "STOP" if gate else None, "maintenance_gate_present")
    names = h.listdir(BASE + "/database-backups")
    if names is None:
        r.line("backups.staging_next", "EXPECTED_ABSENT", "backup directory not present yet")
    else:
        good = [n for n in names if re.fullmatch(r"staging-next-[0-9a-f]{40}-[0-9]{1,30}-[0-9a-f]{8}\.sql\.gz", n)]
        r.line("backups.staging_next", "INFO" if len(good) == len(names) else "MISMATCH",
               f"files={len(names)} well_named={len(good)} (names only, content never read)",
               "STOP" if len(good) != len(names) else None, "unexpected_backup_names")
    legacy = h.listdir("/home/deploy/private-backups")
    r.line("backups.legacy_present", "INFO" if legacy is not None else "UNKNOWN",
           f"files={len(legacy)}" if legacy is not None else "directory unreadable")
    policy, perr = h.lstat("/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-1.4.9.json")
    r.line("phase_b.policy_1_4_9", "MISMATCH" if policy or perr == "error" else "EXPECTED_ABSENT",
           "Phase B policy " + ("present" if policy else "unreadable" if perr == "error"
                                else "absent (must stay absent during helper install)"),
           "STOP" if policy or perr == "error" else None, "phase_b_policy_present")
    creds = h.listdir("/etc/wpcaregrid-deploy/credentials")
    if creds is None:
        r.line("secrets.credentials_dir", "INFO", "no credentials directory")
    else:
        listing = []
        for name in creds[:20]:
            info, _ = h.lstat("/etc/wpcaregrid-deploy/credentials/" + name)
            listing.append(f"{name}(uid={info.st_uid},mode={mode_of(info):o})" if info else name)
        loose = [n for n in creds if (lambda i: i is not None and mode_of(i) & 0o077)(h.lstat("/etc/wpcaregrid-deploy/credentials/" + n)[0])]
        r.line("secrets.credentials_dir", "MISMATCH" if loose else "INFO", "metadata only: " + ",".join(listing),
               "STOP" if loose else None, "credential_file_permissions")
    r.line("secrets.api_key_file", "UNKNOWN", "the API-key file path is not defined in the repository; "
           "Owner must name it; this script never reads secret files", None)


def check_identity_and_helpers(h, r):
    identities = {}
    for name in ("deploy", "deploy-staging-next"):
        try:
            entry = pwd.getpwnam(name) if h.root == "/" else None
        except KeyError:
            entry = None
        if h.root != "/":
            raw = (h.read("/etc/passwd") or b"").decode()
            row = next((x.split(":") for x in raw.splitlines() if x.startswith(name + ":")), None)
            uid = int(row[2]) if row else None
        else:
            uid = entry.pw_uid if entry else None
        identities[name] = uid
        expected = DEPLOY_UID if name == "deploy-staging-next" else None
        ok = uid is not None and (expected is None or uid == expected)
        r.line("identity." + name, "REQUIRED_PRESENT" if ok else "MISMATCH",
               f"uid={uid}" + (f" expected={expected}" if expected else ""), "BLOCKED", "deploy_identity_mismatch")
    versions = h.listdir(LIBEXEC)
    r.line("helper.existing_versions", "INFO" if versions is not None else "UNKNOWN",
           ",".join(versions) if versions else "none installed", "BLOCKED" if versions is None else None, "libexec_unreadable")
    targets = {
        "helper_dir": f"{LIBEXEC}/{VERSION}",
        "policy": f"/etc/wpcaregrid-deploy/profiles/staging-next-{VERSION}.json",
        "sudoers": f"/etc/sudoers.d/wpcaregrid-staging-next-{VERSION}",
    }
    for name, path in targets.items():
        info, err = h.lstat(path)
        r.line("destination." + name, "MISMATCH" if info or err == "error" else "EXPECTED_ABSENT", path,
               "STOP" if info or err == "error" else None, "destination_exists_" + name)
    for path, mode in ((LIBEXEC, 0o755), ("/etc/wpcaregrid-deploy", None), ("/etc/wpcaregrid-deploy/profiles", 0o755),
                       ("/etc/sudoers.d", None)):
        root_chain(h, r, "installer.dir:" + path, path, mode)
    names = h.listdir("/etc/sudoers.d") or []
    related = [n for n in names if "wpcaregrid" in n or "staging" in n]
    bad = []
    files = [("/etc/sudoers", "sudoers")] + [("/etc/sudoers.d/" + n, n) for n in names]
    for full, n in files:
        info, err = h.lstat(full)
        if info is None:
            if full != "/etc/sudoers":
                bad.append(n + ":unreadable")
            continue
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode):
            bad.append(n + ":not_regular")
            continue
        if n in related and (info.st_uid != h.root_uid or mode_of(info) & 0o022):
            bad.append(n + ":metadata")
        raw = h.read(full, 1_000_000)
        if raw is None:
            bad.append(n + ":unreadable")
            continue
        for number, line in enumerate(raw.decode("utf-8", "replace").splitlines(), 1):
            line = line.strip()
            directive = re.match(r"[#@]\s*include(dir)?\s", line)
            if directive and n != "sudoers":
                bad.append(f"{n}:include@{number}")
            elif line and not line.startswith("#") and ("deploy-staging-next" in line or "/wpcaregrid-deploy/staging-next/" in line) \
                    and not SUDOERS_LINE.fullmatch(line):
                bad.append(f"{n}:rule@{number}")
    r.line("sudoers.inventory", "MISMATCH" if bad else "INFO",
           f"wpcaregrid files={','.join(related) or 'none'}" + (f" unexpected={','.join(bad)}" if bad else ""),
           "STOP", "unexpected_sudoers_state")
    out = h.run(["/usr/sbin/visudo", "-c"])
    r.line("sudoers.syntax", "UNKNOWN" if out is None else "REQUIRED_PRESENT" if out[0] == 0 else "MISMATCH",
           "visudo -c " + ("not runnable" if out is None else "ok" if out[0] == 0 else "reports errors"),
           "BLOCKED", "sudoers_syntax")
    src, err = h.lstat(SOURCE_DIR)
    inst, _ = h.lstat(INSTALLER)
    if src is None and inst is None:
        r.line("source.staged", "EXPECTED_ABSENT", "installer and source directory not copied yet (Owner GO #1)")
        return
    problems = []
    deploy_uid = identities.get("deploy")
    if deploy_uid is None:
        problems.append("deploy_identity_unknown")
    else:
        problems.extend(source_chain_problems(h, deploy_uid))
    if src is None or not stat.S_ISDIR(src.st_mode) or stat.S_ISLNK(src.st_mode) or mode_of(src) != 0o700:
        problems.append("source_dir_metadata")
    elif (h.listdir(SOURCE_DIR) or []) != sorted(SOURCE_PINS):
        problems.append("source_dir_contents")
    else:
        for name, pin in SOURCE_PINS.items():
            info, _ = h.lstat(f"{SOURCE_DIR}/{name}")
            if info is None or not stat.S_ISREG(info.st_mode) or mode_of(info) != 0o600 or info.st_uid != deploy_uid \
                    or sha256_file(h, f"{SOURCE_DIR}/{name}") != pin:
                problems.append(name)
    if inst is None or not stat.S_ISREG(inst.st_mode) or mode_of(inst) != 0o600 or inst.st_uid != deploy_uid \
            or sha256_file(h, INSTALLER) != INSTALLER_SHA256:
        problems.append("installer")
    r.line("source.staged", "MISMATCH" if problems else "REQUIRED_PRESENT",
           "staged files verified (5 sources + installer)" if not problems else "mismatch: " + ",".join(problems)[:200],
           "BLOCKED", "staged_source_mismatch")


def check_services_and_data(h, r):
    ports = listening_ports(h)
    out = h.run(["/usr/bin/supervisorctl", "status"])
    states = {}
    if out is not None and out[0] in (0, 3):
        for row in out[1].decode("ascii", "replace").splitlines():
            match = re.match(r"(\S+)\s+(RUNNING|STOPPED|STARTING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN)\b", row)
            if match:
                states[match.group(1)] = match.group(2)
    readable = out is not None and out[0] in (0, 3) and (bool(states) or not out[1].strip())
    for program in ("wpcaregrid-staging-next-default", "wpcaregrid-staging-next-backups"):
        mine = {k: v for k, v in states.items() if k == program or k.startswith(program + ":")}
        if not readable:
            r.line("worker." + program, "UNKNOWN", "supervisorctl status unavailable or unparsable",
                   "BLOCKED", "worker_state_unknown")
        elif any(v in ("RUNNING", "STARTING", "BACKOFF", "STOPPING") for v in mine.values()):
            r.line("worker." + program, "MISMATCH", "worker active", "STOP", "worker_running")
        else:
            r.line("worker." + program, "INFO" if mine else "EXPECTED_ABSENT",
                   ",".join(sorted(set(mine.values()))) if mine else "program not configured")
    timer = unit_state(h, "wpcaregrid-staging-next-scheduler.timer")
    r.line("scheduler.timer", "UNKNOWN" if timer is None else "MISMATCH" if timer.get("ActiveState") == "active" else "INFO",
           (timer.get("ActiveState") + "/" + timer.get("LoadState", "?")) if timer else "systemctl unavailable",
           "STOP" if timer and timer.get("ActiveState") == "active" else "BLOCKED" if timer is None else None,
           "scheduler_active" if timer and timer.get("ActiveState") == "active" else "scheduler_unknown")
    census = h.run(["/bin/ps", "-eo", "args="])
    if census is None:
        r.line("census.staging_next_consumers", "UNKNOWN", "ps unavailable", "BLOCKED", "consumer_census_unknown")
    else:
        count = sum(1 for row in census[1].decode("utf-8", "replace").splitlines()
                    if APP_DIR in row and re.search(r"artisan\s+(?:queue:|schedule:|horizon)", row))
        r.line("census.staging_next_consumers", "MISMATCH" if count else "EXPECTED_ABSENT",
               f"background consumers for the staging-next app: {count}", "STOP" if count else None,
               "background_consumer_active")
    # ---- later gates (never affect the result)
    my = unit_state(h, "mysql.service")
    r.line("NEXT_GATE.mysql_service", "INFO" if my else "UNKNOWN", (my.get("ActiveState") if my else "unavailable"))
    r.line("NEXT_GATE.mysql_ports", "INFO" if ports is not None else "UNKNOWN",
           ",".join(f"{p}={'listening' if ports and p in ports else 'closed'}" for p in (3306, 3307, 3308, 3309))
           if ports is not None else "/proc/net/tcp unreadable")
    version = h.run(["/usr/sbin/mysqld", "--no-defaults", "--version"], 10)
    text = version[1].decode("ascii", "replace") if version else ""
    r.line("NEXT_GATE.mysql_version", "INFO" if re.search(r"Ver 8\.4\.", text) else "UNKNOWN",
           text.strip()[:80] or "mysqld --version not runnable")
    auto = (h.read("/var/lib/mysql/auto.cnf", 4096) or b"").decode("ascii", "replace")
    uuid = re.search(r"^server-uuid=([0-9a-f-]{36})\s*$", auto, re.M)
    digest = hashlib.sha256(uuid.group(1).encode()).hexdigest() if uuid else None
    r.line("NEXT_GATE.mysql_instance_pin", "UNKNOWN" if digest is None else "INFO" if digest == MYSQL_UUID_SHA256 else "MISMATCH",
           "server_uuid digest " + ("unreadable" if digest is None else "matches the helper pin" if digest == MYSQL_UUID_SHA256
                                    else "DIFFERS from the helper pin (digest only shown)"))
    db, _ = h.lstat(f"/var/lib/mysql/{DB}")
    r.line("NEXT_GATE.staging_next_database", "INFO" if db else "EXPECTED_ABSENT",
           f"{DB} schema directory " + ("exists" if db else "absent (created in a later, separately approved step)"))
    for unit in ("mysql@wpcaregrid-production.service", "mysql@wpcaregrid-production-restore.service"):
        st = unit_state(h, unit)
        r.line("NEXT_GATE.g1:" + unit, "INFO" if st else "UNKNOWN",
               f"{st.get('LoadState')}/{st.get('ActiveState')}" if st else "unavailable")
    results = {}
    for path, pin in G1_ARTIFACTS.items():
        info, _ = h.lstat(path)
        results[path.rsplit("/", 1)[-1]] = "absent" if info is None else \
            "match" if sha256_file(h, path) == pin else "differs"
    r.line("NEXT_GATE.g1_artifacts", "INFO", ",".join(f"{k}={v}" for k, v in sorted(results.items())) +
           " (compared with current primary manifest; older #325 files may differ)")
    fingerprint = hashlib.sha256()
    for path in ("/var/lib/wpcaregrid", "/var/www/wpcaregrid"):
        names = h.listdir(path)
        fingerprint.update(path.encode())
        for n in names or []:
            info, _ = h.lstat(f"{path}/{n}")
            if info is not None:
                fingerprint.update(f"{n}|{stat.S_IFMT(info.st_mode)}|{mode_of(info)}|{info.st_uid}\n".encode())
    r.line("legacy.fingerprint", "INFO", fingerprint.hexdigest() + " (top-level names/type/owner/mode of the legacy app and state paths; "
           "rerun after installation - identical unless legacy staging itself changed)")


def main(h=None):
    h = h or Host()
    r = Report()
    print("# WP CareGrid staging-next 1.4.9 read-only preflight")
    if h.euid != h.root_uid:
        # Without root most checks cannot be proven; fail fast instead of reporting misleading findings.
        r.line("env.root_identity", "MISMATCH", f"euid={h.euid}", "BLOCKED", "not_root")
        print("# blockers: not_root")
        return r.final()
    for step in (check_host_and_resources, check_php_and_web, check_environment, check_identity_and_helpers,
                 check_services_and_data):
        try:
            step(h, r)
        except Exception as error:  # the output contract must hold even for unexpected host states
            r.line("internal." + step.__name__, "UNKNOWN", type(error).__name__, "BLOCKED", "preflight_internal_error")
    print("# blockers: " + (",".join(c for c, _ in r.stops + r.blockers) or "none"))
    return r.final()


if __name__ == "__main__":
    sys.exit(main())
