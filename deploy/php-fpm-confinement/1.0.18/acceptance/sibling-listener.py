#!/usr/bin/env python3
"""Harmless root-run canary listener for the sibling-socket negative check. Run as root, on the host, during acceptance.

Why: "the confined probe could not reach a sibling socket in /run/mysqld" proves nothing unless the same
connect succeeds without AppArmor. The real sibling sockets (restore instance, vendor ``mysqld.sock``)
may not exist, so this listener provides one that always does: it listens on the single pathname named by
the fixed ``sibling_canary.path`` in the package matrix, accepts connections, sends only the constant banner and
closes. It reads nothing, serves nothing and holds no secret.

* The socket is created mode 0666 so the unconfined same-UID baseline (www-data) can connect: a denial is
  then AppArmor's, not DAC's. The probe must read the banner in the baseline run (a stale socket file, a
  different listener or ECONNREFUSED is a FAILURE, never evidence) and must be refused by AppArmor, with an
  exactly correlated audit record, in the confined run.
* Fails closed: refuses if anything already exists at the path, if the pinned parent directory is not root:root
  and not writable by group or others, or if the path differs from the matrix. Every filesystem operation is anchored
  to the open directory fd, so renaming/replacing the pathname cannot redirect the root-owned listener.
* Removes only the socket it created (inode compared) on exit. It prints one JSON line per event.

Before running, create the matrix parent as root:root mode 0755; the tool never creates directories.

Usage: sibling-listener.py <matrix.json> [--seconds N]    (N defaults to 600, at most 3600)
"""
from __future__ import annotations

import json
import os
import signal
import socket
import stat
import sys
import time

BANNER = "WPCG-SIBLING-CANARY-v1\n"
RUN_DIR = "/run"
MYSQLD_DIR = "/run/mysqld"
EXPECTED_PARENT = MYSQLD_DIR + "/wpcaregrid-acceptance"
EXPECTED_PATH = EXPECTED_PARENT + "/canary.sock"


class Refused(Exception):
    pass


def _check_directory(fd: int, *, uid: int, gid: int, writable_mask: int, label: str,
                     exact_mode: int | None = None) -> None:
    info = os.fstat(fd)
    mode = stat.S_IMODE(info.st_mode)
    if (not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_gid != gid
            or info.st_mode & writable_mask or (exact_mode is not None and mode != exact_mode)):
        raise Refused(f"refusing: {label} has unexpected type, owner, group, or writable mode")


def prepare(matrix: dict) -> int:
    spec = matrix.get("sibling_canary")
    if not isinstance(spec, dict) or spec.get("banner") != BANNER:
        raise Refused("the matrix has no sibling_canary entry with this tool's banner")
    if spec.get("path") != EXPECTED_PATH or spec.get("parent") != EXPECTED_PARENT:
        raise Refused("refusing: the matrix canary path/parent does not match the pinned acceptance location")
    if spec.get("mode") != "0666":
        raise Refused("refusing: the matrix canary socket mode is not the pinned baseline-readable mode")
    if spec["path"] in {s["path"] for p in matrix["profiles"].values()
                         for s in p.get("sockets", []) if s.get("expect") == "allow"}:
        raise Refused("refusing: the canary path is also an allowed socket")

    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    run_fd = mysqld_fd = parent_fd = None
    try:
        run_fd = os.open(RUN_DIR, flags)
        _check_directory(run_fd, uid=0, gid=0, writable_mask=0o022, label=RUN_DIR)
        mysqld_fd = os.open("mysqld", flags, dir_fd=run_fd)
        mysqld_info = os.fstat(mysqld_fd)
        if not stat.S_ISDIR(mysqld_info.st_mode) or mysqld_info.st_mode & 0o022:
            raise Refused("refusing: /run/mysqld must be a real directory not writable by group or others")
        parent_fd = os.open("wpcaregrid-acceptance", flags, dir_fd=mysqld_fd)
        _check_directory(parent_fd, uid=0, gid=0, writable_mask=0o022,
                         label=EXPECTED_PARENT, exact_mode=0o755)
        try:
            os.stat("canary.sock", dir_fd=parent_fd, follow_symlinks=False)
        except FileNotFoundError:
            result = parent_fd
            parent_fd = None
            return result
        raise Refused("refusing: canary socket path already exists (not created by this tool)")
    except OSError as error:
        raise Refused(f"refusing: acceptance directory chain is inaccessible: {error}") from error
    finally:
        for fd in (parent_fd, mysqld_fd, run_fd):
            if fd is not None:
                os.close(fd)



def main(argv: list) -> int:
    args = list(argv)
    seconds = 600
    if "--seconds" in args:
        index = args.index("--seconds")
        seconds = min(int(args[index + 1]), 3600)
        del args[index:index + 2]
    if len(args) != 1 or os.geteuid() != 0:
        print("usage (as root): sibling-listener.py <matrix.json> [--seconds N]", file=sys.stderr)
        return 2
    with open(args[0], encoding="utf-8") as handle:
        matrix = json.load(handle)
    try:
        parent_fd = prepare(matrix)
    except Refused as error:
        print(str(error), file=sys.stderr)
        return 2
    return serve(EXPECTED_PATH, seconds, parent_fd)


def serve(path: str, seconds: int, parent_fd: int) -> int:
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    basename = "canary.sock"
    anchored_path = f"/proc/self/fd/{parent_fd}/{basename}"
    identity = None
    accepted = 0
    try:
        old_umask = os.umask(0o077)
        try:
            # Binding through the pinned directory fd prevents the mysql-owned parent from
            # swapping the checked child directory for a symlink between validation and bind.
            listener.bind(anchored_path)
        finally:
            os.umask(old_umask)
        identity = os.stat(basename, dir_fd=parent_fd, follow_symlinks=False)
        if not stat.S_ISSOCK(identity.st_mode):
            raise Refused("refusing: bound entry is not a socket")
        os.chmod(basename, 0o666, dir_fd=parent_fd, follow_symlinks=False)
        identity = os.stat(basename, dir_fd=parent_fd, follow_symlinks=False)
        if not stat.S_ISSOCK(identity.st_mode):
            raise Refused("refusing: canary entry changed during setup")
        listener.listen(8)
        listener.settimeout(0.5)
        stop = {"now": False}
        signal.signal(signal.SIGTERM, lambda *_: stop.update(now=True))
        signal.signal(signal.SIGINT, lambda *_: stop.update(now=True))
        print(json.dumps({"event": "ready", "path": path, "pid": os.getpid()}), flush=True)
        deadline = time.time() + seconds
        while not stop["now"] and time.time() < deadline:
            try:
                client, _ = listener.accept()
            except OSError:
                continue
            try:
                client.settimeout(1)
                client.sendall(BANNER.encode())
            except OSError:
                pass
            finally:
                client.close()
            accepted += 1
            print(json.dumps({"event": "accepted", "count": accepted, "ns": time.time_ns()}), flush=True)
        return 0
    finally:
        listener.close()
        try:
            if identity is not None:
                now = os.stat(basename, dir_fd=parent_fd, follow_symlinks=False)
                if stat.S_ISSOCK(now.st_mode) and (now.st_dev, now.st_ino) == (identity.st_dev, identity.st_ino):
                    os.unlink(basename, dir_fd=parent_fd)
        except OSError:
            pass
        finally:
            os.close(parent_fd)
        print(json.dumps({"event": "stopped", "accepted": accepted}), flush=True)


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
