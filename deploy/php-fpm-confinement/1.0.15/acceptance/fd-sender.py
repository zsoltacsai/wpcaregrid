#!/usr/bin/env python3
"""Unconfined helper for the live probe. Run as www-data (never confined, never root).

Listens on one abstract socket (the probe must be refused connecting to it), one
path socket under /tmp (allowed by the probe profile) and loopback TCP listeners on
127.0.0.1 and ::1 (ephemeral ports, printed on the READY line; a profile without any network
right must be refused connecting to them), and hands a descriptor for the
fixture canary over SCM_RIGHTS to the first path-socket client. It transfers only the
canary file, never a real secret. Exits after one transfer or the timeout.

Usage: fd-sender.py <nonce> <canary-file>
"""
from __future__ import annotations

import os
import socket
import stat
import sys
import time

CANARY = "WPCG-FIXTURE-CANARY-v1\n"


def main(argv: list) -> int:
    if len(argv) != 2 or not argv[0].isalnum() or os.geteuid() == 0:
        print("usage (as www-data): fd-sender.py <alnum-nonce> <canary-file>", file=sys.stderr)
        return 2
    nonce, canary = argv
    fd = os.open(canary, os.O_RDONLY | os.O_NONBLOCK | getattr(os, "O_NOFOLLOW", 0))
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_size != len(CANARY) or os.pread(fd, 64, 0) != CANARY.encode():
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
    tcp = {}
    for family, address in ((socket.AF_INET, "127.0.0.1"), (socket.AF_INET6, "::1")):
        try:
            listener_tcp = socket.socket(family, socket.SOCK_STREAM)
            listener_tcp.bind((address, 0))
            listener_tcp.listen(8)
            listener_tcp.settimeout(0.2)
            tcp[family] = listener_tcp
        except OSError:
            pass  # no ::1 on this host: the harness reports that family as unavailable, never as proven
    ports = {fam: sock.getsockname()[1] for fam, sock in tcp.items()}
    print(f"READY {path} wpcg-probe-{nonce} v4={ports.get(socket.AF_INET, 'none')} v6={ports.get(socket.AF_INET6, 'none')}", flush=True)
    deadline, sent = time.time() + 120, False
    try:
        while time.time() < deadline and not sent:
            try:
                abstract.accept()[0].close()
            except OSError:
                pass
            for tcp_listener in tcp.values():
                try:
                    tcp_listener.accept()[0].close()
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
        for tcp_listener in tcp.values():
            tcp_listener.close()
        os.unlink(path)
        os.rmdir(directory)
    return 0 if sent else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
