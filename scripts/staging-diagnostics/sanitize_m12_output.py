#!/usr/bin/env python3
"""Validate the fixed output protocol from the read-only staging helper."""

from __future__ import annotations

import sys

PRIVACY_KEYS = (
    "destructive_retention_enabled",
    "profile_anonymization_enabled",
    "backup_deletion_enabled",
    "provider_deletion_enabled",
    "bulk_retention_execution_enabled",
    "synthetic_profile_anonymization_enabled",
)
IDENTITY = (
    ("deployment_environment", "staging"),
    ("deployment_hostname", "stg1.wpcaregrid.com"),
    ("url", "https://staging.wpcaregrid.com"),
)
FAILURE_CODES = {
    "OWNER_CHECK_UNAVAILABLE",
    "HELPER_IDENTITY_INVALID",
    "CACHE_PATH_UNSAFE",
    "CACHE_METADATA_INVALID",
    "CACHE_READ_FAILED",
    "CACHE_PATH_RACE",
    "CACHE_TOO_LARGE",
    "CACHE_FORMAT_INVALID",
}


class InvalidOutput(ValueError):
    pass


def sanitize(raw: str) -> list[str]:
    if "\x00" in raw or len(raw.encode("utf-8", errors="strict")) > 4096:
        raise InvalidOutput

    rows = raw.splitlines()

    if len(rows) == 1:
        parts = rows[0].split("|")
        if len(parts) == 3 and parts[0] == "DIAGNOSTIC_STATUS" and parts[1] == "UNKNOWN" and parts[2] in FAILURE_CODES:
            return ["DIAGNOSTIC_STATUS=UNKNOWN", f"DIAGNOSTIC_CODE={parts[2]}"]
        raise InvalidOutput

    if len(rows) != len(PRIVACY_KEYS) + len(IDENTITY):
        raise InvalidOutput

    safe: list[str] = []
    for line, key in zip(rows[: len(PRIVACY_KEYS)], PRIVACY_KEYS, strict=True):
        parts = line.split("|")
        if len(parts) != 3 or parts[0] != "M12" or parts[1] != key or parts[2] not in {"TRUE", "FALSE", "UNKNOWN"}:
            raise InvalidOutput
        safe.append(f"privacy.{key}={parts[2]}")

    for line, (key, expected) in zip(rows[len(PRIVACY_KEYS) :], IDENTITY, strict=True):
        parts = line.split("|")
        if len(parts) != 3 or parts[0] != "IDENTITY" or parts[1] != key:
            raise InvalidOutput
        if parts[2] not in {expected, "MISMATCH", "UNKNOWN"}:
            raise InvalidOutput
        safe.append(f"app.{key}={parts[2]}")

    return safe


def main() -> int:
    try:
        raw = sys.stdin.read(4097)
        result = sanitize(raw)
    except (InvalidOutput, UnicodeError):
        print("DIAGNOSTIC_STATUS=INVALID_OUTPUT")
        return 2

    print("\n".join(result))
    return 1 if result and result[0] == "DIAGNOSTIC_STATUS=UNKNOWN" else 0


if __name__ == "__main__":
    raise SystemExit(main())
