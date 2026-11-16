#!/usr/bin/env python3
"""Derive a live-acceptance probe profile from a confinement profile.

The probe profile is the same profile under the name ``<name>-probe``. It adds only
the exec and library-read rules a ``python3 -I -c`` process needs. Every file,
ptrace, signal, socket and capability rule is copied unchanged, so a denial seen
by the probe is a denial the real service would see. Reads one profile on stdin
and writes the derived profile on stdout. It never loads or installs anything.
"""
from __future__ import annotations

import re
import sys

PROBE_RULES = (
    "  # probe-only additions: run python3 and read the standard library. Nothing else changes.\n"
    "  /usr/bin/python3* mrix,\n"
    "  /usr/lib/python3*/{,**} mr,\n"
    "  /proc/[0-9]*/attr/current r,\n"
)
HEADER = re.compile(r"^profile (wpcaregrid-php-fpm-[a-z-]+) ", re.MULTILINE)


def derive(text: str) -> str:
    headers = HEADER.findall(text)
    if len(headers) != 1 or text.count("\n}\n") != 1 or not text.endswith("\n}\n"):
        raise ValueError("unexpected profile structure")
    name = headers[0]
    if name.endswith("-probe") or "-probe" in text:
        raise ValueError("profile is already a probe")
    out = text.replace(f"profile {name} ", f"profile {name}-probe ", 1)
    out = out.replace(f"peer={name},", f"peer={name}-probe,")
    return out[: -len("}\n")] + "\n" + PROBE_RULES + "}\n"


def main() -> int:
    try:
        sys.stdout.write(derive(sys.stdin.read()))
    except ValueError as error:
        print(f"refused: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
