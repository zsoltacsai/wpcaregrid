#!/usr/bin/env python3
"""GO-5 final, read-only runtime isolation acceptance of the two Production G1 MySQL instances.

Run only after both instances were started and `verify-running` passed. Reads systemd
state, /proc and /sys; connects to nothing, changes nothing, prints no credential.

  python3 -I -B go5-runtime-acceptance.py --mysql-main-pid <MainPID printed by GO-1>

Last line / exit code:
  G1_GO5_RUNTIME_ACCEPTANCE=PASS                 0
  G1_GO5_RUNTIME_ACCEPTANCE=STOP;CODE=<cause>    3   (first failed check; every check is still printed)
"""
import argparse
import errno
import os
import re
import subprocess
import sys

INSTANCES = {
    "wpcaregrid-production": (3308, "wpcaregrid-mysql-production"),
    "wpcaregrid-production-restore": (3309, "wpcaregrid-mysql-production-restore"),
}
LOOPBACK_HEX = "0100007F"  # 127.0.0.1 in /proc/net/tcp


class Fail(Exception):
    def __init__(self, code, detail=""):
        super().__init__(code)
        self.code, self.detail = code, detail


def default_run(args):
    try:
        done = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=60,
                              check=False, stdin=subprocess.DEVNULL,
                              env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C", "LC_ALL": "C"})
    except (OSError, subprocess.TimeoutExpired):
        raise Fail("command_failed", args[0])
    return done.returncode, done.stdout


def listeners(root):
    found = set()  # (address_hex, port)
    for table in ("/proc/net/tcp", "/proc/net/tcp6"):
        path = root + table
        if not os.path.exists(path):
            continue
        with open(path, encoding="ascii") as stream:
            next(stream, None)
            for line in stream:
                fields = line.split()
                if len(fields) > 3 and fields[3] == "0A":
                    address, port = fields[1].rsplit(":", 1)
                    found.add((address, int(port, 16)))
    return found


def main(mysql_pid, root="", run=default_run):
    results = []

    def check(name, fn):
        try:
            fn()
            results.append(None)
            print(f"CHECK {name} OK")
        except Fail as exc:
            results.append(exc)
            print(f"FAIL  {name} {exc.code} {exc.detail}".rstrip())
        except Exception as exc:
            results.append(Fail("internal_error", exc.__class__.__name__))
            print(f"FAIL  {name} internal_error {exc.__class__.__name__}")

    def unit_pid(unit):
        rc, out = run(["/usr/bin/systemctl", "show", unit, "-P", "MainPID"])
        if rc != 0 or not out.strip().isdigit() or int(out.strip()) == 0:
            raise Fail("unit_main_pid_invalid", unit)
        return out.strip()

    def active(unit):
        rc, out = run(["/usr/bin/systemctl", "is-active", unit])
        if rc != 0 or out.strip() != "active":
            raise Fail("unit_not_active", unit)

    def read(path):
        with open(root + path, "rb") as stream:
            return stream.read()

    def instance_checks(name, port, profile):
        unit = f"mysql@{name}.service"
        active(unit)
        pid = unit_pid(unit)
        if read(f"/proc/{pid}/comm").strip() != b"mysqld":
            raise Fail("main_pid_not_mysqld", name)
        label = read(f"/proc/{pid}/attr/current").decode("ascii", "replace").strip()
        if label != f"{profile} (enforce)":
            raise Fail("apparmor_label_not_enforced", name)
        cmdline = read(f"/proc/{pid}/cmdline").split(b"\0")
        if f"--defaults-group-suffix=@{name}".encode() not in cmdline:
            raise Fail("process_not_using_instance_group", name)
        socks = listeners(root)
        if (LOOPBACK_HEX, port) not in socks:
            raise Fail("instance_port_not_listening_on_loopback", str(port))
        if any(p == port and a != LOOPBACK_HEX for a, p in socks):
            raise Fail("instance_port_listening_beyond_loopback", str(port))

    def no_x_protocol():
        if any(p == 33060 for _, p in listeners(root)):
            raise Fail("x_protocol_port_listening")

    def legacy_untouched():
        rc, out = run(["/usr/bin/systemctl", "is-active", "mysql.service"])
        if rc != 0 or out.strip() != "active":
            raise Fail("mysql_service_not_active")
        if unit_pid("mysql.service") != str(mysql_pid):
            raise Fail("mysql_service_main_pid_changed")
        if not any(p == 3306 for _, p in listeners(root)):
            raise Fail("mysql_3306_not_listening")

    def distinct_pids():
        pids = {unit_pid(f"mysql@{n}.service") for n in INSTANCES} | {unit_pid("mysql.service")}
        if len(pids) != 3:
            raise Fail("instances_share_a_process")

    for name, (port, profile) in INSTANCES.items():
        check(f"instance {name}", lambda n=name, p=port, f=profile: instance_checks(n, p, f))
    check("no X Protocol listener (33060)", no_x_protocol)
    check("legacy mysql.service and 3306 unchanged", legacy_untouched)
    check("three distinct server processes", distinct_pids)
    failures = [r for r in results if r is not None]
    if failures:
        print(f"G1_GO5_RUNTIME_ACCEPTANCE=STOP;CODE={failures[0].code}")
        return 3
    print("G1_GO5_RUNTIME_ACCEPTANCE=PASS")
    return 0


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--mysql-main-pid", required=True, type=int)
    parser.add_argument("--root", default="")  # tests only
    args = parser.parse_args()
    try:
        if os.geteuid() != 0 and not args.root:
            raise Fail("not_root")
        code = main(args.mysql_main_pid, root=args.root)
    except BaseException as exc:
        print(f"G1_GO5_RUNTIME_ACCEPTANCE=STOP;CODE={exc.code if isinstance(exc, Fail) else 'internal_error'}")
        code = 3
    raise SystemExit(code)
