#!/usr/bin/env python3
"""Synthetic exact-release admission tests for the production controller."""

from __future__ import annotations

import unittest
from unittest.mock import patch
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from scripts import production_deploy_controller as CONTROLLER


class ProductionDeploymentControllerTests(unittest.TestCase):
    def test_sync_holds_when_primary_advanced_after_exact_sha_approval(self):
        approved = "a" * 40
        newer_primary = "b" * 40
        helper_calls = []

        def helper(operation, *args):
            helper_calls.append((operation, args))
            return {"result": "PASS", "application_sha": "c" * 40,
                    "artifact_sha256": "d" * 64}

        with (patch.object(CONTROLLER, "_verify_install_identity"),
              patch.object(CONTROLLER, "_helper", side_effect=helper),
              patch.object(CONTROLLER, "_latest_primary_sha", return_value=newer_primary)):
            with self.assertRaisesRegex(
                    CONTROLLER.ControllerHold, "authorized_release_not_latest_primary"):
                CONTROLLER.sync(approved)

        self.assertEqual(helper_calls, [("preflight", ())])

    def test_sync_passes_only_the_exact_authorized_sha_to_release_authorization(self):
        approved = "a" * 40
        artifact = "d" * 64
        helper_calls = []

        def helper(operation, *args):
            helper_calls.append((operation, args))
            if operation == "preflight":
                return {"result": "PASS", "application_sha": "c" * 40,
                        "artifact_sha256": "e" * 64}
            if operation == "authorize":
                return {"result": "PASS", "application_sha": approved}
            return {"result": "PASS"}

        with (patch.object(CONTROLLER, "_verify_install_identity"),
              patch.object(CONTROLLER, "_helper", side_effect=helper),
              patch.object(CONTROLLER, "_latest_primary_sha", return_value=approved),
              patch.object(CONTROLLER, "_git", return_value=(approved + "\n").encode()),
              patch.object(CONTROLLER, "_package_release", return_value=("/synthetic/release.tar", artifact))):
            result = CONTROLLER.sync(approved)

        self.assertEqual(result, {"result": "PASS"})
        self.assertIn(("authorize", (approved,)), helper_calls)
        self.assertIn(("stage", (approved, artifact)), helper_calls)
        self.assertIn(("deploy", (approved, artifact)), helper_calls)

    def test_main_rejects_unpinned_sync_and_invalid_sha(self):
        for argv in (["sync"], ["sync", "A" * 40], ["sync", "a" * 39],
                     ["sync", "a" * 40, "extra"], ["deploy", "a" * 40]):
            with self.subTest(argv=argv):
                self.assertEqual(CONTROLLER.main(argv), 3)


if __name__ == "__main__":
    unittest.main(verbosity=2)
