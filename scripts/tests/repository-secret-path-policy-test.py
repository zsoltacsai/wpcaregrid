#!/usr/bin/env python3
"""Attacker-controlled filenames are detected but never printed."""
import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("secret_paths", ROOT / "scripts/tests/repository-secret-path-policy.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def main():
    newline = bytes([10])
    sensitive = [
        b".env", b"storage/id_rsa", b"database/test.sqlite", b"folder" + newline + b"::error::sentinel/.env",
        b"folder" + newline + b"::add-mask::SECRET/auth.json",
    ]
    found = MODULE.find_sensitive(sensitive)
    assert len(found) == 5
    assert MODULE.find_sensitive([b".env.example", b"reports/a.md", b"public/index.php"]) == []


if __name__ == "__main__":
    main()
