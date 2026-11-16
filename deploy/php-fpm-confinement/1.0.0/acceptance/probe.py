#!/usr/bin/env python3
"""Live acceptance probe for the PHP-FPM confinement profiles.

Run only on a host, only inside the derived ``<profile>-probe`` profile, as
www-data, from ``systemd-run`` (see docs/PHP_FPM_CONFINEMENT_GO13.md). Source tests
cannot show kernel enforcement; this probe is how a person gets that evidence.

Fail closed: the probe refuses to probe (exit 2) unless /proc/self/attr/current
names the expected probe profile in enforce mode. Any denied check that succeeds,
an allowed check that fails, or an inconclusive check (a "deny" target that does
not exist, so the kernel never reached the LSM) is a failure (exit 1). Exit 0 is
printed only when every check was conclusive and matched.

Usage: python3 -I -c "$(cat probe.py)" "$(cat matrix.json)" <profile> [peer_worker_pid ...]
Peer pids must be www-data WORKER pids of a different pool: a root-owned master would
make kill/process_vm_readv/proc reads fail for ordinary DAC reasons and prove nothing.
"""
from __future__ import annotations

import ctypes
import errno
import json
import os
import socket
import sys
import tempfile


def confinement(expected: str) -> str | None:
    try:
        with open("/proc/self/attr/current", "rb") as handle:
            label = handle.read().decode("ascii", "replace").strip("\0\n ")
    except OSError:
        return None
    return label if label == f"{expected}-probe (enforce)" else None


def attempt(path: str, access: str) -> int:
    """Return 0 or the errno of one open() decided by the kernel."""
    try:
        if access == "r":
            os.close(os.open(path, os.O_RDONLY | os.O_NONBLOCK))
        elif os.path.lexists(path):
            flags = os.O_RDWR if access == "rw" else os.O_WRONLY
            os.close(os.open(path, flags | os.O_NONBLOCK))
        else:
            os.close(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600))
            os.unlink(path)
        return 0
    except OSError as error:
        return error.errno


def exec_probe(path: str) -> int:
    pid = os.fork()
    if pid == 0:
        try:
            os.execv(path, [path, "--version"])
        except OSError as error:
            os._exit(error.errno)
    _, status = os.wait()
    return os.WEXITSTATUS(status) if os.WIFEXITED(status) else 0


def judge(expect: str, code: int) -> str:
    if expect == "allow":
        return "PASS" if code == 0 else "FAIL"
    if code in (errno.EACCES, errno.EPERM):
        return "PASS"
    if code == errno.ENOENT:
        return "INCONCLUSIVE"
    return "FAIL"


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print("usage: probe <matrix.json> <profile> [peer_pid ...]", file=sys.stderr)
        return 2
    # The matrix is passed inline (the confined probe cannot read arbitrary files).
    matrix = json.loads(argv[0]) if argv[0].lstrip().startswith("{") else json.load(open(argv[0], encoding="utf-8"))
    profile = argv[1]
    spec = matrix["profiles"].get(profile)
    if spec is None or confinement(profile) is None:
        print("REFUSED: not running inside the expected enforced probe profile; nothing probed", file=sys.stderr)
        return 2
    results: list[tuple[str, str]] = []
    scratch = tempfile.mkdtemp(prefix="wpcg-probe-")
    try:
        for check in spec["checks"]:
            if not check.get("live", True):
                continue
            path, access, expect = check["path"], check["access"], check["expect"]
            code = exec_probe(path) if access == "x" else attempt(path, access)
            results.append((check["id"], judge(expect, code)))
        for index, target in enumerate(spec["symlink_escape_targets"]):
            link = os.path.join(scratch, f"escape-{index}")
            os.symlink(target, link)
            results.append((f"symlink-escape-{index}", judge("deny", attempt(link, "r"))))
        for sock in spec["sockets"]:
            client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            try:
                client.connect(sock["path"])
                code = 0
            except OSError as error:
                code = 0 if error.errno == errno.ECONNREFUSED and sock["expect"] == "allow" else error.errno
            finally:
                client.close()
            results.append((f"socket-{sock['id']}", judge(sock["expect"], code)))
        libc = ctypes.CDLL(None, use_errno=True)
        for peer in argv[2:]:
            pid = int(peer)
            for name in ("environ", "cmdline", "maps", "mem"):
                path = f"/proc/{pid}/{name}"
                results.append((f"peer-{pid}-{name}", judge("deny", attempt(path, "r"))))
            try:
                os.kill(pid, 0)
                results.append((f"peer-{pid}-signal", "FAIL"))
            except OSError as error:
                results.append((f"peer-{pid}-signal", "PASS" if error.errno == errno.EPERM else "INCONCLUSIVE"))

            class Iov(ctypes.Structure):
                _fields_ = [("base", ctypes.c_void_p), ("len", ctypes.c_size_t)]

            buffer = ctypes.create_string_buffer(1)
            local, remote = Iov(ctypes.addressof(buffer), 1), Iov(0x1000, 1)
            libc.process_vm_readv.argtypes = [ctypes.c_int, ctypes.POINTER(Iov), ctypes.c_ulong,
                                              ctypes.POINTER(Iov), ctypes.c_ulong, ctypes.c_ulong]
            rc = libc.process_vm_readv(pid, ctypes.byref(local), 1, ctypes.byref(remote), 1, 0)
            err = ctypes.get_errno()
            results.append((f"peer-{pid}-process_vm_readv",
                            "PASS" if rc == -1 and err == errno.EPERM else
                            ("INCONCLUSIVE" if rc == -1 and err == errno.ESRCH else "FAIL")))
    finally:
        for name in os.listdir(scratch):
            os.unlink(os.path.join(scratch, name))
        os.rmdir(scratch)
    bad = [item for item in results if item[1] != "PASS"]
    for name, outcome in results:
        print(f"{outcome} {name}")
    if bad:
        print(f"FAILED: {len(bad)} of {len(results)} checks were not a conclusive pass", file=sys.stderr)
        return 1
    print(f"OK: {len(results)} checks conclusive and matched ({profile})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
