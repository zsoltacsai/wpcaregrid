#!/usr/bin/env python3
"""Reject tracked local secret/runtime files without echoing attacker-controlled paths."""
import re
import subprocess
import sys

PATTERNS = tuple(re.compile(pattern) for pattern in (
    rb"(^|/)\.env$", rb"(^|/)\.env\.(production|backup)$", rb"\.sqlite$", rb"\.sqlite3$",
    rb"(^|/)id_(rsa|ed25519|ecdsa)$", rb"\.pem$", rb"\.p12$", rb"(^|/)auth\.json$",
    rb"^public/hot$", rb"^storage/.+\.key$",
))


def find_sensitive(paths):
    return [path for path in paths if any(pattern.search(path) for pattern in PATTERNS)]


def main():
    result = subprocess.run(["git", "ls-files", "-z"], check=True, stdout=subprocess.PIPE)
    matches = find_sensitive(result.stdout.split(b"\0"))
    if matches:
        print(f"Local or secret-like tracked paths found: {len(matches)} (paths redacted).", file=sys.stderr)
        return 1
    print("No local or secret-like tracked paths found.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
