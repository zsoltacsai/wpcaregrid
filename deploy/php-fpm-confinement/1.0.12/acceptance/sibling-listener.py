#!/usr/bin/env python3
"""Harmless root-run canary listener for the sibling-socket negative check. Run as root, on the host, during acceptance.

Why: "the confined probe could not reach a sibling socket in /run/mysqld" proves nothing unless the same
connect succeeds without AppArmor. The real sibling sockets (restore instance, vendor ``mysqld.sock``)
may not exist, so this listener provides one that always does: it listens on the single pathname named by
``sibling_canary.path`` in the package matrix, accepts connections, sends only the constant banner and
closes. It reads nothing, serves nothing and holds no secret.

* The socket is created mode 0666 so the unconfined same-UID baseline (www-data) can connect: a denial is
  then AppArmor's, not DAC's. The probe must read the banner in the baseline run (a stale socket file, a
  different listener or ECONNREFUSED is a FAILURE, never evidence) and must be refused by AppArmor, with an
  exactly correlated audit record, in the confined run.
* Fails closed: refuses if anything already exists at the path, if the parent directory is not a real
  directory owned by root and not writable by group or others, or if the path differs from the matrix.
* Removes only the socket it created (inode compared) on exit. It prints one JSON line per event.

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


class Refused(Exception):
    pass


def prepare(matrix: dict, run_dir: str = "/run/mysqld") -> str:
    spec = matrix.get("sibling_canary")
    if not isinstance(spec, dict) or spec.get("banner") != BANNER:
        raise Refused("the matrix has no sibling_canary entry with this tool's banner")
    path = spec["path"]
    if path != "/run/mysqld/" + os.path.basename(path) or not os.path.basename(path) or path.endswith("/"):
        raise Refused(f"refusing: {path!r} is not a direct child of /run/mysqld")
    path = os.path.join(run_dir, os.path.basename(path))
    if spec["path"] in {s["path"] for p in matrix["profiles"].values() for s in p["sockets"] if s.get("expect") == "allow"}:
        raise Refused("refusing: the canary path is also an allowed socket")
    parent = run_dir
    try:
        info = os.lstat(parent)
    except OSError as error:
        raise Refused(f"refusing: {parent} is not accessible: {error}") from error
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise Refused(f"refusing: {parent} must be a real directory owned by root and not writable by group or others")
    if os.path.lexists(path):
        raise Refused(f"refusing: {path} already exists (not created by this tool)")
    return path


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
        path = prepare(matrix)
    except Refused as error:
        print(str(error), file=sys.stderr)
        return 2
    return serve(path, seconds)


def serve(path: str, seconds: int) -> int:
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    old_umask = os.umask(0o077)
    try:
        listener.bind(path)
    finally:
        os.umask(old_umask)
    os.chmod(path, 0o666)
    identity = os.lstat(path)
    listener.listen(8)
    listener.settimeout(0.5)
    stop = {"now": False}
    signal.signal(signal.SIGTERM, lambda *_: stop.update(now=True))
    signal.signal(signal.SIGINT, lambda *_: stop.update(now=True))
    accepted = 0
    print(json.dumps({"event": "ready", "path": path, "pid": os.getpid()}), flush=True)
    deadline = time.time() + seconds
    try:
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
    finally:
        listener.close()
        try:
            now = os.lstat(path)
            if (now.st_dev, now.st_ino) == (identity.st_dev, identity.st_ino):
                os.unlink(path)
        except OSError:
            pass
        print(json.dumps({"event": "stopped", "accepted": accepted}), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
