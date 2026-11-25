#!/usr/bin/env python3
"""Live acceptance probe for the PHP-FPM confinement profiles. Records; never judges.

Two modes, always run as www-data, never with a real secret read:

* ``baseline``: unconfined. Calibrates the host. A denied-by-profile target must be reachable here,
  otherwise a later EACCES could be DAC, Yama or a missing file and would prove nothing about AppArmor.
* ``confined``: inside the derived ``<profile>-probe`` profile in enforce mode.

The probe refuses (exit 2, no output) if its label is not the expected one for the mode. It only opens
targets; it reads descriptor content solely when the file is exactly the fixture canary size. Judgement
(errno vs baseline, audit correlation, peer inventory completeness) is done offline by
``verify-acceptance.py`` so a probe error can never turn into a PASS.

Every result carries the pid that performed the call (the probe, or a short-lived child for execve) and
its own realtime window ``t0``..``t1`` in nanoseconds, so a kernel audit record can be bound to exactly
one call, one pid and one moment. The document also records the boot id, the probe pid, its kernel start
time and its lifetime.

Usage: python3 -I -B -c "$(cat probe.py)" <baseline|confined> "$(cat matrix.json)" <profile> '<env-json>'
env-json: {"peers": {"<role>": [worker pids]}, "scm_socket": "/tmp/...", "abstract": "<name>",
          "tcp": {"AF_INET": ["127.0.0.1", port], "AF_INET6": ["::1", port]}}  (the sender's loopback listeners)
"""
from __future__ import annotations

import ctypes
import errno
import hashlib
import json
import os
import socket
import stat
import sys
import tempfile
import time

CANARY = "WPCG-FIXTURE-CANARY-v1\n"


def label() -> str:
    try:
        with open("/proc/self/attr/current", "rb") as handle:
            return handle.read().decode("ascii", "replace").strip("\0\n ")
    except OSError:
        return ""


def matches_enforced_profile_label(actual: str, profile: str) -> bool:
    """Match only the direct profile or its exact unconfined//&profile enforce stack."""
    return actual in (f"{profile} (enforce)", f"unconfined//&{profile} (enforce)")


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


def exec_path(path: str) -> tuple:
    """(errno, child pid) of execve(). 0 only if the program really started.

    The child's stdout/stderr go to /dev/null so a program that starts can never write into the JSON
    document, and its pid is returned because the kernel audit record for a denied exec names the child.
    """
    read_end, write_end = os.pipe2(os.O_CLOEXEC)
    pid = os.fork()
    if pid == 0:
        try:
            null = os.open("/dev/null", os.O_RDWR)
            os.dup2(null, 0)
            os.dup2(null, 1)
            os.dup2(null, 2)
            os.execv(path, [path, "--version"])
        except OSError as error:
            os.write(write_end, str(error.errno).encode())
        os._exit(127)
    os.close(write_end)
    data = os.read(read_end, 16)
    os.close(read_end)
    os.waitpid(pid, 0)
    return (int(data) if data else 0), pid


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


def connect_banner(address, banner: str) -> tuple:
    """(errno, banner_ok): connect and read the canary listener's constant banner.

    banner_ok is True only when the peer sent exactly the banner, i.e. a real canary listener answered. A
    stale socket file or an absent listener (ECONNREFUSED, ENOENT) is reported as that errno, never as success.
    """
    client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        client.settimeout(3)
        client.connect(address)
        data = b""
        try:
            while len(data) < len(banner):
                chunk = client.recv(len(banner) - len(data))
                if not chunk:
                    break
                data += chunk
        except OSError:
            pass
        return 0, data == banner.encode()
    except OSError as error:
        return error.errno or -1, False
    finally:
        client.close()


class Iov(ctypes.Structure):
    _fields_ = [("base", ctypes.c_void_p), ("len", ctypes.c_size_t)]


def network(check: dict, tcp: dict | None = None) -> int:
    """errno of one socket operation; nothing is ever sent.

    create: make one socket. bind: bind it to loopback port 0. connect: make one socket and connect it to the
    loopback listener the harness sender opened (``tcp`` maps family name to [address, port]); with no listener
    the call reports EDESTADDRREQ so a baseline can never pass without one.
    """
    def go():
        sock = socket.socket(getattr(socket, check["family"]), getattr(socket, check["type"]))
        try:
            if check["action"] == "bind" and check["family"] == "AF_UNIX":
                path = f"/tmp/wpcg-probe-bind-{os.getpid()}"
                sock.bind(path)
                os.unlink(path)
            elif check["action"] == "bind":
                sock.bind(("127.0.0.1", 0))
            elif check["action"] == "connect":
                target = (tcp or {}).get(check["family"])
                if not target:
                    raise OSError(errno.EDESTADDRREQ, "no loopback listener for this family")
                sock.settimeout(3)
                sock.connect((target[0], int(target[1])))
        finally:
            sock.close()
    return code(go)


def vm_readv(pid: int, remote_address: int) -> int:
    """Raw errno of process_vm_readv(): 0 = bytes read; EFAULT = the kernel allowed it, the address was unmapped."""
    libc = ctypes.CDLL(None, use_errno=True)
    libc.process_vm_readv.argtypes = [ctypes.c_int, ctypes.POINTER(Iov), ctypes.c_ulong,
                                      ctypes.POINTER(Iov), ctypes.c_ulong, ctypes.c_ulong]
    buffer = ctypes.create_string_buffer(1)
    local, remote = Iov(ctypes.addressof(buffer), 1), Iov(remote_address, 1)
    ctypes.set_errno(0)
    result = libc.process_vm_readv(pid, ctypes.byref(local), 1, ctypes.byref(remote), 1, 0)
    return 0 if result == 1 else (ctypes.get_errno() if result < 0 else errno.EIO)


def seize(pid: int) -> int:
    libc = ctypes.CDLL(None, use_errno=True)
    libc.ptrace.argtypes = [ctypes.c_long, ctypes.c_long, ctypes.c_void_p, ctypes.c_void_p]
    libc.ptrace.restype = ctypes.c_long
    ctypes.set_errno(0)
    return 0 if libc.ptrace(0x4206, pid, None, None) == 0 else ctypes.get_errno()


def starttime_ticks(pid: str = "self") -> int:
    with open(f"/proc/{pid}/stat", "rb") as handle:
        return int(handle.read().decode("ascii", "replace").rsplit(")", 1)[1].split()[19])


def boot_wall_ns() -> int:
    with open("/proc/stat", "rb") as handle:
        for line in handle.read().decode("ascii", "replace").splitlines():
            if line.startswith("btime "):
                return int(line.split()[1]) * 1_000_000_000
    return 0


def timed(results: list, ident: str, kind: str, target: str, call, *, pid: int | None = None, extra: dict | None = None) -> None:
    t0 = time.time_ns()
    outcome = call()
    child = None
    if isinstance(outcome, tuple):
        outcome, child = outcome
    entry = {"id": ident, "kind": kind, "target": target, "errno": outcome, "pid": child if child else (pid or os.getpid()),
             "t0": t0, "t1": time.time_ns()}
    entry.update(extra or {})
    results.append(entry)


def peer_entries(results: list, role: str, pid: int, entries: list) -> None:
    base = f"/proc/{pid}"
    for entry in entries:
        path = f"{base}/{entry}"
        if entry in ("fd/", "fdinfo/"):
            call = (lambda p=path: code(lambda: os.listdir(p)))
        elif entry in ("root/", "cwd/"):
            call = (lambda p=path: code(lambda: os.close(os.open(p, os.O_RDONLY | os.O_DIRECTORY))))
        elif entry in ("exe", "ns/mnt"):
            call = (lambda p=path: code(lambda: os.readlink(p)))
        else:
            call = (lambda p=path: open_path(p, "r"))
        timed(results, f"peer:{role}:{pid}:{entry}", "peer", path, call)
    timed(results, f"peer:{role}:{pid}:signal", "peer-signal", base, lambda: code(lambda: os.kill(pid, 0)))
    # 0x1000 is almost certainly unmapped in the peer: EFAULT means the kernel PERMITTED the access.
    timed(results, f"peer:{role}:{pid}:process_vm_readv", "peer-vm", base, lambda: vm_readv(pid, 0x1000))


def descendant_calibration(results: list) -> None:
    """Use a short-lived direct child for the existing descendant-access probes.

    The target address is a buffer that exists in the parent and, after fork, at the same address in the
    child, so a permitted ``process_vm_readv`` really reads one byte (errno 0), never a bare EFAULT. A
    denied operation is recorded for the verifier; this probe does not identify which kernel control denied it.
    """
    buffer = ctypes.create_string_buffer(b"x", 1)
    address = ctypes.addressof(buffer)
    read_end, write_end = os.pipe2(os.O_CLOEXEC)
    try:
        child = os.fork()
    except BaseException:
        os.close(read_end)
        os.close(write_end)
        raise
    if child == 0:
        try:
            os.close(write_end)
            while os.read(read_end, 4096):
                pass
            os.close(read_end)
        except BaseException:
            os._exit(127)
        os._exit(0)
    first = len(results)
    try:
        os.close(read_end)
        timed(results, "control:self-maps", "control", "/proc/self/maps", lambda: open_path("/proc/self/maps", "r"))
        timed(results, "calibration:child-signal", "control", f"/proc/{child}", lambda: code(lambda: os.kill(child, 0)))
        timed(results, "calibration:child-maps", "calibration", f"/proc/{child}/maps",
              lambda: open_path(f"/proc/{child}/maps", "r"))
        timed(results, "calibration:child-environ", "calibration", f"/proc/{child}/environ",
              lambda: open_path(f"/proc/{child}/environ", "r"))
        timed(results, "calibration:child-process_vm_readv", "calibration", f"/proc/{child}", lambda: vm_readv(child, address))
        timed(results, "calibration:child-ptrace-seize", "calibration", f"/proc/{child}", lambda: seize(child))
    except BaseException as probe_error:
        try:
            _close_calibration_pipe_and_reap(write_end, child)
        except BaseException as cleanup_error:
            if hasattr(probe_error, "add_note"):
                probe_error.add_note(f"calibration child cleanup also failed: {cleanup_error!r}")
        raise
    else:
        _close_calibration_pipe_and_reap(write_end, child)
    for item in results[first:]:
        item["child"] = child  # pid of the calibration child, for exact /proc/<child>/... correlation


def _close_calibration_pipe_and_reap(write_end: int, child: int) -> None:
    """Close the child's EOF gate and reap it; never signal a confined child."""
    close_error = None
    try:
        os.close(write_end)
    except OSError as error:
        close_error = error

    while True:
        try:
            waited, status = os.waitpid(child, 0)
            break
        except InterruptedError:
            continue

    if close_error is not None:
        raise close_error
    if waited != child or not os.WIFEXITED(status) or os.WEXITSTATUS(status) != 0:
        raise RuntimeError("calibration child did not exit cleanly after pipe EOF")


def fd0() -> dict:
    t0 = time.time_ns()
    try:
        info = os.fstat(0)
    except OSError as error:
        return {"state": "fstat-failed", "errno": error.errno, "t0": t0, "t1": time.time_ns()}
    if not stat.S_ISREG(info.st_mode) or info.st_size != len(CANARY):
        return {"state": "not-canary-file", "size": info.st_size, "t0": t0, "t1": time.time_ns()}
    try:
        os.lseek(0, 0, os.SEEK_SET)
        data = os.read(0, 64)
        return {"state": "read", "errno": 0, "marker_ok": data == CANARY.encode(), "t0": t0, "t1": time.time_ns()}
    except OSError as error:
        return {"state": "read", "errno": error.errno, "marker_ok": False, "t0": t0, "t1": time.time_ns()}


def scm(path: str) -> dict:
    """Receive a descriptor to a canary in a denied tree from an unconfined same-UID sender.

    Every outcome names its stage (connect, recv, read) and carries its own time window so the verifier
    can require the kernel event that belongs to that exact stage.
    """
    t0 = time.time_ns()
    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        sock.settimeout(5)
        try:
            sock.connect(path)
        except OSError as error:
            return {"state": "channel-failed", "stage": "connect", "errno": error.errno or -1, "t0": t0, "t1": time.time_ns()}
        try:
            _, fds, _, _ = socket.recv_fds(sock, 16, 4)
        except OSError as error:
            return {"state": "channel-failed", "stage": "recv", "errno": error.errno or -1, "t0": t0, "t1": time.time_ns()}
    finally:
        sock.close()
    if not fds:
        return {"state": "no-descriptor", "t0": t0, "t1": time.time_ns()}
    try:
        info = os.fstat(fds[0])
        if info.st_size != len(CANARY):
            return {"state": "not-canary-file", "size": info.st_size, "t0": t0, "t1": time.time_ns()}
        try:
            data = os.pread(fds[0], 64, 0)
            return {"state": "received", "stage": "read", "errno": 0, "read_ok": data == CANARY.encode(),
                    "t0": t0, "t1": time.time_ns()}
        except OSError as error:
            return {"state": "received", "stage": "read", "errno": error.errno or -1, "read_ok": False,
                    "t0": t0, "t1": time.time_ns()}
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
    actual_label = label()
    expected = "unconfined" if mode == "baseline" else f"{profile}-probe (enforce)"
    label_ok = actual_label == "unconfined" if mode == "baseline" else matches_enforced_profile_label(actual_label, f"{profile}-probe")
    if spec is None or not label_ok:
        print(f"REFUSED: label {actual_label!r} is not an accepted label for {expected!r}; nothing probed", file=sys.stderr)
        return 2
    started = time.time_ns()
    results: list = []
    for check in spec["checks"]:
        if not check.get("live", True) or check.get("probe_scope") == "original_profile":
            continue
        access, path = check["access"], check["path"]
        if access == "x":
            timed(results, check["id"], "exec", path, lambda p=path: exec_path(p))
        else:
            timed(results, check["id"], "file", path, lambda p=path, a=access: open_path(p, a))
    for sock in spec["sockets"]:
        if sock.get("banner"):
            seen: dict = {}
            def banner_call(s=sock, store=seen):
                code_, store["banner_ok"] = connect_banner(s["path"], s["banner"])
                return code_
            timed(results, f"socket:{sock['id']}", "socket", sock["path"], banner_call)
            results[-1]["banner_ok"] = seen.get("banner_ok", False)
        else:
            # ECONNREFUSED is returned as it is: a refused connection is not evidence of a permitted or reachable
            # peer (no listener) and not an AppArmor denial; the verdict treats it as a failure.
            timed(results, f"socket:{sock['id']}", "socket", sock["path"], lambda s=sock: connect(s["path"]))
    scratch = tempfile.mkdtemp(prefix="wpcg-probe-")
    try:
        for index, target in enumerate(spec["live_symlink_escape_targets"]):
            link = os.path.join(scratch, f"escape-{index}")
            os.symlink(target, link)
            timed(results, f"symlink-escape:{index}", "symlink", target, lambda l=link: open_path(l, "r"))
    finally:
        for name in os.listdir(scratch):
            os.unlink(os.path.join(scratch, name))
        os.rmdir(scratch)
    timed(results, "abstract-connect", "abstract", "@" + env["abstract"], lambda: connect("\0" + env["abstract"]))
    for net in spec.get("network_checks", []):
        timed(results, f"net:{net['id']}", "net", f"{net['family']}/{net['type']}/{net['action']}", lambda n=net: network(n, env.get("tcp")))
    descendant_calibration(results)
    for role in spec["peer_roles"]:
        for pid in env["peers"].get(role, []):
            peer_entries(results, role, int(pid), matrix["peer_entries"])
    fd0_result = fd0()
    scm_result = scm(env["scm_socket"])
    ticks = starttime_ticks()
    derived_start = boot_wall_ns() + ticks * 1_000_000_000 // os.sysconf("SC_CLK_TCK")
    print(json.dumps({
        "schema": 2, "mode": mode, "profile": profile, "label": label(), "pid": os.getpid(),
        "starttime_ticks": ticks, "kernel_start_ns": derived_start, "started_ns": started, "ended_ns": time.time_ns(),
        "boot_id": open("/proc/sys/kernel/random/boot_id").read().strip(),
        "matrix_sha256": hashlib.sha256(matrix_text.encode()).hexdigest(),
        "peers": {role: [int(p) for p in pids] for role, pids in env["peers"].items()},
        "results": results, "fd0": fd0_result, "scm": scm_result, "scm_socket": env["scm_socket"],
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
