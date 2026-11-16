#!/usr/bin/env python3
"""Live acceptance probe for the PHP-FPM confinement profiles. Records; never judges.

Two modes, always run as www-data, never with a real secret read:

* ``baseline``: unconfined. Calibrates the host. A denied-by-profile target must be
  reachable here, otherwise a later EACCES could be DAC, Yama or a missing file
  and would prove nothing about AppArmor.
* ``confined``: inside the derived ``<profile>-probe`` profile in enforce mode.

The probe refuses (exit 2, no output) if its label is not the expected one for the
mode. It only opens targets; it reads descriptor content solely when the file is
exactly the fixture canary size. Judgement (errno vs baseline, audit correlation,
peer inventory completeness) is done offline by ``verify-acceptance.py`` so a
probe error can never turn into a PASS.

Usage: python3 -I -c "$(cat probe.py)" <baseline|confined> "$(cat matrix.json)" <profile> '<env-json>'
env-json: {"peers": {"<role>": [worker pids]}, "scm_socket": "/tmp/...", "abstract": "<name>"}
"""
from __future__ import annotations

import ctypes
import errno
import hashlib
import json
import os
import signal
import socket
import stat
import sys
import tempfile

CANARY = "WPCG-FIXTURE-CANARY-v1\n"


def label() -> str:
    try:
        with open("/proc/self/attr/current", "rb") as handle:
            return handle.read().decode("ascii", "replace").strip("\0\n ")
    except OSError:
        return ""


def code(call) -> int:
    try:
        call()
        return 0
    except OSError as error:
        return error.errno or -1


def open_path(path: str, access: str) -> int:
    def go():
        if access == "r":
            os.close(os.open(path, os.O_RDONLY | os.O_NONBLOCK))
        elif os.path.lexists(path):
            os.close(os.open(path, (os.O_RDWR if access == "rw" else os.O_WRONLY) | os.O_NONBLOCK))
        else:
            os.close(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600))
            os.unlink(path)
    return code(go)


def exec_path(path: str) -> int:
    """errno of execve(); 0 only if the program really started (reported over a close-on-exec pipe)."""
    read_end, write_end = os.pipe2(os.O_CLOEXEC)
    pid = os.fork()
    if pid == 0:
        try:
            os.execv(path, [path, "--version"])
        except OSError as error:
            os.write(write_end, str(error.errno).encode())
        os._exit(127)
    os.close(write_end)
    data = os.read(read_end, 16)
    os.close(read_end)
    os.waitpid(pid, 0)
    return int(data) if data else 0


def connect(address) -> int:
    client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        client.settimeout(3)
        client.connect(address)
        return 0
    except OSError as error:
        return error.errno or -1
    finally:
        client.close()


def vm_readv(pid: int) -> int:
    class Iov(ctypes.Structure):
        _fields_ = [("base", ctypes.c_void_p), ("len", ctypes.c_size_t)]
    libc = ctypes.CDLL(None, use_errno=True)
    libc.process_vm_readv.argtypes = [ctypes.c_int, ctypes.POINTER(Iov), ctypes.c_ulong,
                                      ctypes.POINTER(Iov), ctypes.c_ulong, ctypes.c_ulong]
    buffer = ctypes.create_string_buffer(1)
    local, remote = Iov(ctypes.addressof(buffer), 1), Iov(0x1000, 1)
    ctypes.set_errno(0)
    result = libc.process_vm_readv(pid, ctypes.byref(local), 1, ctypes.byref(remote), 1, 0)
    # EFAULT = the kernel allowed the access and the address was simply unmapped.
    return 0 if result >= 0 else ctypes.get_errno()


def seize(pid: int) -> int:
    libc = ctypes.CDLL(None, use_errno=True)
    libc.ptrace.argtypes = [ctypes.c_long, ctypes.c_long, ctypes.c_void_p, ctypes.c_void_p]
    libc.ptrace.restype = ctypes.c_long
    ctypes.set_errno(0)
    return 0 if libc.ptrace(0x4206, pid, None, None) == 0 else ctypes.get_errno()


def record(results: list, ident: str, kind: str, target: str, value: int) -> None:
    results.append({"id": ident, "kind": kind, "target": target, "errno": value})


def peer_entries(results: list, role: str, pid: int, entries: list) -> None:
    base = f"/proc/{pid}"
    for entry in entries:
        path = f"{base}/{entry}"
        if entry in ("fd/", "fdinfo/"):
            value = code(lambda p=path: os.listdir(p))
        elif entry in ("root/", "cwd/"):
            value = code(lambda p=path: os.close(os.open(p, os.O_RDONLY | os.O_DIRECTORY)))
        elif entry in ("exe", "ns/mnt"):
            value = code(lambda p=path: os.readlink(p))
        else:
            value = open_path(path, "r")
        record(results, f"peer:{role}:{pid}:{entry}", "peer", path, value)
    record(results, f"peer:{role}:{pid}:signal", "peer-signal", base, code(lambda: os.kill(pid, 0)))
    record(results, f"peer:{role}:{pid}:process_vm_readv", "peer-vm", base, vm_readv(pid))


def descendant_calibration(results: list) -> None:
    """A child of this probe is traceable by DAC and Yama, so a refusal is AppArmor's."""
    child = os.fork()
    if child == 0:
        while True:
            signal.pause()
    try:
        record(results, "control:self-maps", "control", "/proc/self/maps", open_path("/proc/self/maps", "r"))
        record(results, "calibration:child-signal", "control", f"/proc/{child}", code(lambda: os.kill(child, 0)))
        record(results, "calibration:child-maps", "calibration", f"/proc/{child}/maps", open_path(f"/proc/{child}/maps", "r"))
        record(results, "calibration:child-environ", "calibration", f"/proc/{child}/environ",
               open_path(f"/proc/{child}/environ", "r"))
        record(results, "calibration:child-process_vm_readv", "calibration", f"/proc/{child}", vm_readv(child))
        record(results, "calibration:child-ptrace-seize", "calibration", f"/proc/{child}", seize(child))
    finally:
        os.kill(child, signal.SIGKILL)
        os.waitpid(child, 0)
    for item in results[-6:]:
        item["child"] = child  # pid of the calibration child, for audit correlation


def fd0() -> dict:
    try:
        info = os.fstat(0)
    except OSError as error:
        return {"state": "fstat-failed", "errno": error.errno}
    if not stat.S_ISREG(info.st_mode) or info.st_size != len(CANARY):
        return {"state": "not-canary-file", "size": info.st_size}
    try:
        os.lseek(0, 0, os.SEEK_SET)
        data = os.read(0, 64)
        return {"state": "read", "errno": 0, "marker_ok": data == CANARY.encode()}
    except OSError as error:
        return {"state": "read", "errno": error.errno, "marker_ok": False}


def scm(path: str) -> dict:
    """Receive a descriptor to a canary in a denied tree from an unconfined same-UID sender."""
    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        sock.settimeout(5)
        sock.connect(path)
        _, fds, _, _ = socket.recv_fds(sock, 16, 4)
    except OSError as error:
        return {"state": "channel-failed", "errno": error.errno or -1}
    finally:
        sock.close()
    if not fds:
        return {"state": "no-descriptor"}
    try:
        info = os.fstat(fds[0])
        if info.st_size != len(CANARY):
            return {"state": "not-canary-file", "size": info.st_size}
        return {"state": "received", "errno": code(lambda: os.read(fds[0], 64)),
                "read_ok": os.pread(fds[0], 64, 0) == CANARY.encode()}
    except OSError as error:
        return {"state": "received", "errno": error.errno, "read_ok": False}
    finally:
        for fd in fds:
            os.close(fd)


def main(argv: list) -> int:
    if len(argv) != 4 or argv[0] not in ("baseline", "confined"):
        print("usage: probe <baseline|confined> <matrix-json> <profile> <env-json>", file=sys.stderr)
        return 2
    mode, matrix_text, profile, env_text = argv
    matrix, env = json.loads(matrix_text), json.loads(env_text)
    spec = matrix["profiles"].get(profile)
    expected = "unconfined" if mode == "baseline" else f"{profile}-probe (enforce)"
    if spec is None or label() != expected:
        print(f"REFUSED: label {label()!r} is not {expected!r}; nothing probed", file=sys.stderr)
        return 2
    results: list = []
    for check in spec["checks"]:
        if not check.get("live", True) or check.get("probe_scope") == "original_profile":
            continue
        access, path = check["access"], check["path"]
        value = exec_path(path) if access == "x" else open_path(path, access)
        record(results, check["id"], "exec" if access == "x" else "file", path, value)
    for sock in spec["sockets"]:
        value = connect(sock["path"])
        record(results, f"socket:{sock['id']}", "socket", sock["path"], 0 if value == errno.ECONNREFUSED else value)
    scratch = tempfile.mkdtemp(prefix="wpcg-probe-")
    try:
        for index, target in enumerate(spec["live_symlink_escape_targets"]):
            link = os.path.join(scratch, f"escape-{index}")
            os.symlink(target, link)
            record(results, f"symlink-escape:{index}", "symlink", target, open_path(link, "r"))
    finally:
        for name in os.listdir(scratch):
            os.unlink(os.path.join(scratch, name))
        os.rmdir(scratch)
    record(results, "abstract-connect", "abstract", "@" + env["abstract"], connect("\0" + env["abstract"]))
    descendant_calibration(results)
    for role in spec["peer_roles"]:
        for pid in env["peers"].get(role, []):
            peer_entries(results, role, int(pid), matrix["peer_entries"])
    print(json.dumps({
        "schema": 1, "mode": mode, "profile": profile, "label": label(), "pid": os.getpid(),
        "matrix_sha256": hashlib.sha256(matrix_text.encode()).hexdigest(),
        "peers": {role: [int(p) for p in pids] for role, pids in env["peers"].items()},
        "results": results, "fd0": fd0(), "scm": scm(env["scm_socket"]),
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
