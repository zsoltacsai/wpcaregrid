#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path


MODULE_PATH = Path(__file__).parents[1] / "staging-diagnostics" / "sanitize_m12_output.py"
SPEC = importlib.util.spec_from_file_location("sanitize_m12_output", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def valid_output() -> str:
    rows = [f"M12|{key}|FALSE" for key in MODULE.PRIVACY_KEYS]
    rows.extend(f"SECURITY|{key}|FALSE" for key in MODULE.INCIDENT_KEYS)
    rows.extend(f"IDENTITY|{key}|{expected}" for key, expected in MODULE.IDENTITY)
    return "\n".join(rows) + "\n"


class StagingDiagnosticsOutputTest(unittest.TestCase):
    def test_valid_output_emits_only_allowlisted_values(self) -> None:
        self.assertEqual(
            MODULE.sanitize(valid_output()),
            [
                f"privacy.{key}=FALSE" for key in MODULE.PRIVACY_KEYS
            ]
            + [f"security.incidents.{key}=FALSE" for key in MODULE.INCIDENT_KEYS]
            + [f"app.{key}={expected}" for key, expected in MODULE.IDENTITY],
        )

    def test_true_unknown_and_identity_mismatch_are_bounded(self) -> None:
        rows = valid_output().splitlines()
        rows[0] = "M12|destructive_retention_enabled|TRUE"
        rows[len(MODULE.PRIVACY_KEYS)] = "SECURITY|auto_open|TRUE"
        rows[-1] = "IDENTITY|url|MISMATCH"
        result = MODULE.sanitize("\n".join(rows))
        self.assertEqual(result[0], "privacy.destructive_retention_enabled=TRUE")
        self.assertEqual(result[len(MODULE.PRIVACY_KEYS)], "security.incidents.auto_open=TRUE")
        self.assertEqual(result[-1], "app.url=MISMATCH")

    def test_fixed_failure_classification_is_sanitized(self) -> None:
        self.assertEqual(
            MODULE.sanitize("DIAGNOSTIC_STATUS|UNKNOWN|CACHE_FORMAT_INVALID\n"),
            ["DIAGNOSTIC_STATUS=UNKNOWN", "DIAGNOSTIC_CODE=CACHE_FORMAT_INVALID"],
        )

    def test_duplicate_missing_extra_or_reordered_rows_are_rejected(self) -> None:
        rows = valid_output().splitlines()
        for changed in (
            rows[:-1],
            rows + [rows[0]],
            [rows[1], rows[0], *rows[2:]],
            [*rows[:-1], rows[0]],
        ):
            with self.subTest(changed=changed[:2]):
                with self.assertRaises(MODULE.InvalidOutput):
                    MODULE.sanitize("\n".join(changed))

    def test_provider_supplied_data_and_secret_like_fields_are_never_echoed(self) -> None:
        hostile = valid_output().replace(
            "FALSE", "sk_live_sensitive-provider-value", 1
        )
        with self.assertRaises(MODULE.InvalidOutput):
            MODULE.sanitize(hostile)

    def test_unknown_record_fields_and_oversized_output_are_rejected(self) -> None:
        with self.assertRaises(MODULE.InvalidOutput):
            MODULE.sanitize(valid_output() + "SECRET|unexpected|hidden\n")
        with self.assertRaises(MODULE.InvalidOutput):
            MODULE.sanitize("x" * 4097)


if __name__ == "__main__":
    unittest.main()
