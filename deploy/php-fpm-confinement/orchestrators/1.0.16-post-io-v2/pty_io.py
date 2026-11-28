"""PTY transaction diagnostics with status-only reporting.

This module is for local fake-PTY testing. It never prints or returns PTY bytes,
typed input, command text, or argv. Callers must supply explicit prompt tokens.
"""
from __future__ import annotations

import os
import pty
import select
import signal
import time
from typing import Callable, Iterable


class _Reader:
    def __init__(self, fd: int):
        self.fd = fd
        self.pending = bytearray()

    def _fill(self, deadline: float) -> bool:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            return False
        ready, _, _ = select.select([self.fd], [], [], min(remaining, 0.1))
        if not ready:
            return True
        try:
            chunk = os.read(self.fd, 4096)
        except OSError:
            return False
        if not chunk:
            return False
        self.pending.extend(chunk)
        if len(self.pending) > 16384:
            del self.pending[:-8192]
        return True

    def wait_token(self, tokens: Iterable[bytes], timeout: float) -> str:
        tokens = tuple(tokens)
        deadline = time.monotonic() + timeout
        while True:
            for token in tokens:
                index = self.pending.find(token)
                if index >= 0:
                    del self.pending[:index + len(token)]
                    return token
            if time.monotonic() >= deadline:
                return "timeout"
            if not self._fill(deadline):
                return "eof"

    def wait_exact_line(self, expected: bytes, timeout: float) -> str:
        deadline = time.monotonic() + timeout
        while True:
            while b"\n" in self.pending:
                line, rest = self.pending.split(b"\n", 1)
                self.pending = bytearray(rest)
                line = line.strip(b"\r")
                if line == expected:
                    return "matched"
            if time.monotonic() >= deadline:
                return "timeout"
            if not self._fill(deadline):
                return "eof"

    def wait_eof(self, timeout: float) -> str:
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if not self._fill(deadline):
                return "eof"
        return "timeout"


def _write_all(fd: int, data: bytes | bytearray) -> None:
    view = memoryview(data)
    while view:
        written = os.write(fd, view)
        view = view[written:]


def _reap(pid: int, fd: int, report: dict, send_hup: bool) -> None:
    if send_hup:
        try:
            os.kill(pid, signal.SIGHUP)
        except ProcessLookupError:
            pass
    try:
        os.close(fd)
    except OSError:
        pass
    try:
        waited, status = os.waitpid(pid, 0)
    except ChildProcessError:
        report["child_reaped"] = False
        return
    report["child_reaped"] = waited == pid
    if waited == pid:
        if os.WIFEXITED(status):
            report["su_exit_code"] = os.WEXITSTATUS(status)
        elif os.WIFSIGNALED(status):
            report["su_exit_code"] = None
            report["su_signal"] = os.WTERMSIG(status)
