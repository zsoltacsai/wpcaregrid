#!/usr/bin/env python3
"""Unconfined helper for the live probe. Run as www-data (never confined, never root).

Listens on one abstract socket (the probe must be refused connecting to it) and one
path socket under /tmp (allowed by the probe profile), and hands a descriptor for the
fixture canary over SCM_RIGHTS to the first path-socket client. It transfers only the
canary file, never a real secret. Exits after one transfer or the timeout.

Usage: fd-sender.py <nonce> <canary-file>
"""
from __future__ import annotations

import os
import socket
import sys
import time

CANARY = "WPCG-FIXTURE-CANARY-v1\n"


def main(argv: list) -> int:
    if len(argv) != 2 or not argv[0].isalnum() or os.geteuid() == 0:
        print("usage (as www-data): fd-sender.py <alnum-nonce> <canary-file>", file=sys.stderr)
        return 2
    nonce, canary = argv
    fd = os.open(canary, os.O_RDONLY)
    if os.fstat(fd).st_size != len(CANARY) or os.pread(fd, 64, 0) != CANARY.encode():
        print("REFUSED: not the fixture canary; nothing sent", file=sys.stderr)
        return 2
    directory = f"/tmp/wpcg-probe-{nonce}"
    os.mkdir(directory, 0o700)
    path = f"{directory}/scm.sock"
    listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    abstract = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    listener.bind(path)
    abstract.bind("\0" + f"wpcg-probe-{nonce}")
    for item in (listener, abstract):
        item.listen(4)
        item.settimeout(1)
    print(f"READY {path} wpcg-probe-{nonce}", flush=True)
    deadline, sent = time.time() + 120, False
    try:
        while time.time() < deadline and not sent:
            try:
                abstract.accept()[0].close()
            except OSError:
                pass
            try:
                client = listener.accept()[0]
            except OSError:
                continue
            socket.send_fds(client, [b"canary"], [fd])
            client.close()
            sent = True
    finally:
        listener.close()
        abstract.close()
        os.unlink(path)
        os.rmdir(directory)
    return 0 if sent else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
