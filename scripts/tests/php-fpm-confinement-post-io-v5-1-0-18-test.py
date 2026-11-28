#!/usr/bin/env python3
"""Regression tests for the 1.0.18 unproven-only continuation contract."""
import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
BUNDLE = ROOT / "deploy/php-fpm-confinement/orchestrators/1.0.18-post-io-v5"
sys.path.insert(0, str(BUNDLE))
import one_shot_bridge
spec = importlib.util.spec_from_file_location("gate2_1018_runner", BUNDLE / "acceptance-post.py")
runner = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = runner
spec.loader.exec_module(runner)


def result(status, failure_code="none"):
    return {"profile": "test", "status": status, "failure_code": failure_code}


class Gate218ProfileContinuationTests(unittest.TestCase):
    def test_unproven_only_continues_through_all_roles_but_blocks_cleanup(self):
        calls = []
        replies = [result("UNPROVEN", "owner-ack-required"), result("PASS"), result("PASS"), result("PASS")]

        def fake_profile(profile, *_args):
            calls.append(profile)
            item = replies[len(calls) - 1].copy()
            item["profile"] = profile
            return item

        with patch.object(runner, "profile_run", side_effect=fake_profile):
            outcomes, failed = runner.collect_profile_results("matrix", {}, object(), "boot", Path("/evidence"))

        self.assertEqual(calls, runner.PROFILES)
        self.assertEqual([item["status"] for item in outcomes], ["UNPROVEN", "PASS", "PASS", "PASS"])
        self.assertEqual(failed, "owner-ack-required")
        self.assertFalse(runner.cleanup_is_eligible(outcomes, failed))

    def test_hard_hold_stops_later_roles(self):
        calls = []

        def fake_profile(profile, *_args):
            calls.append(profile)
            if len(calls) == 1:
                return result("HOLD", "probe-command")
            return result("PASS")

        with patch.object(runner, "profile_run", side_effect=fake_profile):
            outcomes, failed = runner.collect_profile_results("matrix", {}, object(), "boot", Path("/evidence"))

        self.assertEqual(calls, runner.PROFILES[:1])
        self.assertEqual([item["status"] for item in outcomes], ["HOLD"])
        self.assertEqual(failed, "probe-command")
        self.assertFalse(runner.cleanup_is_eligible(outcomes, failed))

    def test_unproven_then_hard_hold_stops_and_preserves_cleanup_gate(self):
        calls = []
        replies = [result("UNPROVEN", "owner-ack-required"), result("HOLD", "inventory-changed")]

        def fake_profile(profile, *_args):
            calls.append(profile)
            item = replies[len(calls) - 1].copy()
            item["profile"] = profile
            return item

        with patch.object(runner, "profile_run", side_effect=fake_profile):
            outcomes, failed = runner.collect_profile_results("matrix", {}, object(), "boot", Path("/evidence"))

        self.assertEqual(calls, runner.PROFILES[:2])
        self.assertEqual([item["status"] for item in outcomes], ["UNPROVEN", "HOLD"])
        self.assertEqual(failed, "inventory-changed")
        self.assertFalse(runner.cleanup_is_eligible(outcomes, failed))

    def test_all_strict_pass_is_the_only_cleanup_eligible_result(self):
        outcomes = [result("PASS") for _ in runner.PROFILES]
        self.assertTrue(runner.cleanup_is_eligible(outcomes, ""))
        self.assertFalse(runner.cleanup_is_eligible(outcomes[:-1], ""))
        self.assertFalse(runner.cleanup_is_eligible(outcomes, "owner-ack-required"))
        outcomes[-1] = result("UNPROVEN", "owner-ack-required")
        self.assertFalse(runner.cleanup_is_eligible(outcomes, ""))

    def test_bridge_accepts_unproven_hold_but_rejects_false_pass(self):
        profiles = []
        for index, profile in enumerate(runner.PROFILES):
            status = "UNPROVEN" if index == 0 else "PASS"
            profiles.append({
                "profile": profile, "status": status,
                "inventory_before_rc": 0, "baseline_rc": 0, "confined_rc": 0,
                "inventory_after_rc": 0, "verifier_rc": 3 if status == "UNPROVEN" else 0,
                "sibling_required": profile.endswith("build") or profile.endswith("deploy"),
                "sibling_cleanup_ok": True, "probe_unloaded": True, "original_exec_check": True,
                "failure_code": "owner-ack-required" if status == "UNPROVEN" else "none",
            })
        report = {
            "phase": "acceptance-post", "rc": 1, "evidence_path": "/root/run",
            "package_pin_ok": True, "fixture_pre_match": True, "host_preflight_ok": True,
            "profile_results": profiles, "fixture_cleanup_rc": -1, "fixture_empty": False,
            "sibling_socket_absent": True, "overall_pass": False,
            "failure_code": "owner-ack-required", "exception_metadata_written": False,
            "exception_phase": "none", "exception_class": "none", "exception_errno": None,
            "exception_thrower": None, "exception_thrower_truncated": False,
        }
        encoded = b"__WPCG_ONESHOT_REPORT__:" + __import__("json").dumps(report).encode("ascii")
        self.assertIsNotNone(one_shot_bridge._parse_report(encoded))
        forged = dict(report, rc=0, overall_pass=True, fixture_cleanup_rc=0, fixture_empty=True)
        forged_encoded = b"__WPCG_ONESHOT_REPORT__:" + __import__("json").dumps(forged).encode("ascii")
        self.assertIsNone(one_shot_bridge._parse_report(forged_encoded))


if __name__ == "__main__":
    unittest.main(verbosity=2)
