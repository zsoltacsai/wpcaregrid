#!/usr/bin/env python3
"""Focused regression tests for the versioned 1.0.17 acceptance verifier."""

from __future__ import annotations

import contextlib
import hashlib
import importlib.util
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "deploy/php-fpm-confinement/1.0.17"
ACCEPTANCE = PKG / "acceptance"
MATRIX_TEXT = (ACCEPTANCE / "matrix.json").read_text(encoding="utf-8")
MATRIX = json.loads(MATRIX_TEXT)


def load(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


base = load(ROOT / "scripts/tests/php-fpm-confinement-test.py", "wpcg_gate2_base_test_helpers")
base.ACCEPTANCE = ACCEPTANCE
base.MATRIX_TEXT = MATRIX_TEXT
base.PROFILES = {name: "" for name in MATRIX["profiles"]}
verifier = load(ACCEPTANCE / "verify-acceptance.py", "wpcg_verify_1017_test")


def run(profile="wpcaregrid-nginx"):
    module, matrix, baseline, confined, before, after, audit, ctx = base.synthetic_run(profile)
    return module, matrix, baseline, confined, before, after, audit, ctx


def verify_all(profile="wpcaregrid-nginx", *, mutate=None):
    module, matrix, baseline, confined, before, after, audit, ctx = run(profile)
    state = {"matrix": MATRIX_TEXT, "baseline": baseline, "confined": confined,
             "before": before, "after": after, "audit": audit, "ctx": ctx}
    if mutate:
        mutate(state)
    verdict = module.verify(state["matrix"], profile, "post", state["baseline"], state["confined"],
                            state["before"], state["after"], state["audit"])
    return verdict, state


class Gate217VerifierTests(unittest.TestCase):
    def test_side_by_side_copy_preserves_runtime_and_only_versions_verifier_inputs(self):
        old = ROOT / "deploy/php-fpm-confinement/1.0.16"
        old_files = {p.relative_to(old).as_posix() for p in old.rglob("*") if p.is_file()}
        new_files = {p.relative_to(PKG).as_posix() for p in PKG.rglob("*") if p.is_file()}
        self.assertEqual(new_files, old_files)
        permitted = {"binding.json", "acceptance/matrix.json", "acceptance/verify-acceptance.py", "SHA256SUMS"}
        for rel in sorted(new_files):
            self.assertEqual((PKG / rel).stat().st_mode & 0o777, (old / rel).stat().st_mode & 0o777, rel)
        for rel in sorted(new_files - permitted):
            self.assertEqual((PKG / rel).read_bytes(), (old / rel).read_bytes(), rel)
        old_binding = json.loads((old / "binding.json").read_text())
        new_binding = json.loads((PKG / "binding.json").read_text())
        self.assertEqual(new_binding["package_version"], "1.0.17")
        old_binding.pop("package_version")
        new_binding.pop("package_version")
        self.assertEqual(new_binding, old_binding)
        old_matrix = json.loads((old / "acceptance/matrix.json").read_text())
        new_matrix = json.loads((PKG / "acceptance/matrix.json").read_text())
        self.assertEqual(new_matrix.pop("version"), "1.0.17")
        old_matrix.pop("version")
        self.assertEqual(new_matrix, old_matrix)
        checksums = (PKG / "SHA256SUMS").read_text().splitlines()
        self.assertEqual(len(checksums), len(new_files) - 1)
        for line in checksums:
            digest, rel = line.split("  ", 1)
            self.assertFalse(rel.startswith("./"), rel)
            self.assertEqual(hashlib.sha256((PKG / rel).read_bytes()).hexdigest(), digest, rel)

    def test_symlinked_deny_targets_resolve_through_every_declared_link(self):
        checks, _ = verifier.expected_checks(MATRIX, "wpcaregrid-nginx", {"production": [2101]}, "post")
        expected = {
            "production-env-via-link": "/var/lib/wpcaregrid-deploy/production/shared/.env",
            "production-public-storage-link": "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/laravel.log",
            "production-config-cache": "/var/www/wpcaregrid-production/releases/wpcg-fixture/bootstrap/cache/config.php",
            "production-app-config": "/var/www/wpcaregrid-production/releases/wpcg-fixture/config/app.php",
            "production-code": "/var/www/wpcaregrid-production/releases/wpcg-fixture/artisan",
        }
        for ident, path in expected.items():
            self.assertEqual(checks[ident][1]["audit_path"], path, ident)
        self.assertTrue(checks["production-backups"][1]["audit_path"].endswith("/"))

    def test_synthetic_acceptance_still_passes_for_every_profile(self):
        for profile in MATRIX["profiles"]:
            verdict, _ = verify_all(profile)
            self.assertEqual(verdict.failures, [], (profile, verdict.failures[:5]))

    def test_exact_stacked_audit_profile_name_is_correlated(self):
        def stacked(state):
            label = "unconfined//&wpcaregrid-nginx-probe"
            state["confined"]["label"] = label + " (enforce)"
            state["audit"] = state["audit"].replace('profile="wpcaregrid-nginx-probe"', f'profile="{label}"')
        verdict, _ = verify_all(mutate=stacked)
        self.assertEqual(verdict.failures, [])

    def test_profile_and_peer_prefix_impostors_do_not_correlate(self):
        row = {"profile": "wpcaregrid-nginx-probe-evil", "pid": 7, "operation": "ptrace",
               "_boot": "boot", "_ts_ns": 10, "peer": "wpcaregrid-php-fpm-production-evil"}
        used = set()
        self.assertIsNone(verifier.window_hit([row], used, "wpcaregrid-nginx-probe", "boot", 7, 10, 10,
                                               {"ptrace"}, peer="wpcaregrid-php-fpm-production"))

    def test_first_calibration_child_maps_candidate_is_not_lost_as_falsey_index(self):
        probe = "wpcaregrid-nginx-probe"
        records = [{"profile": probe, "pid": 10, "operation": "ptrace", "peer": probe,
                    "_boot": "boot", "_ts_ns": 15}]
        result = {"pid": 10, "child": 11, "t0": 10, "t1": 20}
        hit = verifier.check_audit_hit(records, set(), probe, "boot", result,
                                       "calibration:child-maps", "calibration", {}, {})
        self.assertEqual(hit, 0)

    def test_directory_audit_name_accepts_only_exact_slash_variants(self):
        checks, _ = verifier.expected_checks(MATRIX, "wpcaregrid-nginx", {"production": [2101]}, "post")
        item = checks["production-backups"][1]
        probe = "wpcaregrid-nginx-probe"
        record = {"profile": probe, "pid": 10, "operation": "open",
                  "name": item["audit_path"].rstrip("/"), "_boot": "boot", "_ts_ns": 15}
        self.assertEqual(verifier.check_audit_hit([record], set(), probe, "boot",
                                                   {"pid": 10, "t0": 10, "t1": 20},
                                                   "production-backups", "file", item, {}), 0)
        record["name"] = item["audit_path"].rstrip("/") + "/child"
        self.assertIsNone(verifier.check_audit_hit([record], set(), probe, "boot",
                                                    {"pid": 10, "t0": 10, "t1": 20},
                                                    "production-backups", "file", item, {}))

    def test_mem_baseline_mask_is_unproven_and_requires_child_calibration(self):
        def masked(state):
            for run_name in ("baseline", "confined"):
                result = next(r for r in state[run_name]["results"] if r["id"].endswith(":mem"))
                result["errno"] = 13
        verdict, _ = verify_all(mutate=masked)
        self.assertFalse(verdict.failures, verdict.failures[:5])
        self.assertTrue(any(i.endswith(":mem") for i in verdict.unproven))

    def test_mem_mask_still_fails_if_child_ptrace_calibration_is_not_bound(self):
        def masked_and_unbound(state):
            for run_name in ("baseline", "confined"):
                result = next(r for r in state[run_name]["results"] if r["id"].endswith(":mem"))
                result["errno"] = 13
            state["confined"]["results"] = [r for r in state["confined"]["results"]
                                                  if r["id"] != "calibration:child-process_vm_readv"]
        verdict, _ = verify_all(mutate=masked_and_unbound)
        self.assertTrue(any("calibration" in failure for failure in verdict.failures), verdict.failures[:8])

    def test_denial_in_unbound_failed_window_is_not_silently_tolerated(self):
        def unbind_target(state):
            state["audit"] = state["audit"].replace(
                'name=\\"/var/www/wpcaregrid-production/releases/wpcg-fixture/artisan\\"',
                'name=\\"/unexpected/target\\"', 1)
        verdict, _ = verify_all(mutate=unbind_target)
        self.assertTrue(any("production-code" in f for f in verdict.failures), verdict.failures[:8])
        self.assertTrue(any("unexplained-denial" in f for f in verdict.failures), verdict.failures[:8])

    def test_bound_event_for_optional_unreachable_check_stays_unproven_not_unexplained(self):
        def unreachable(state):
            row = next(r for r in state["baseline"]["results"] if r["id"] == "public-staging-next")
            row["errno"] = 2  # ENOENT: optional target absent in the unconfined baseline.
        verdict, _ = verify_all(mutate=unreachable)
        self.assertFalse(any("unexplained-denial" in failure for failure in verdict.failures), verdict.failures[:8])
        self.assertFalse(verdict.failures, verdict.failures[:8])
        self.assertTrue(any("public-staging-next" in ident for ident in verdict.unproven))

    def test_bound_event_for_yama_masked_peer_vm_stays_unproven_not_unexplained(self):
        def yama_mask(state):
            row = next(r for r in state["baseline"]["results"]
                       if r["id"] == "peer:production:2101:process_vm_readv")
            row["errno"] = 13  # EACCES before confinement means attribution is inconclusive.
        verdict, _ = verify_all(mutate=yama_mask)
        self.assertFalse(any("unexplained-denial" in failure for failure in verdict.failures), verdict.failures[:8])
        self.assertFalse(verdict.failures, verdict.failures[:8])
        self.assertTrue(any("peer:production:2101:process_vm_readv" in ident for ident in verdict.notes))

    def test_signal_zero_success_remains_a_hard_failure(self):
        def signal_allowed(state):
            result = next(r for r in state["confined"]["results"] if r["id"].endswith(":signal"))
            result["errno"] = 0
        verdict, _ = verify_all(mutate=signal_allowed)
        self.assertTrue(any("confined access not refused" in f for f in verdict.failures), verdict.failures[:8])

    def test_matrix_rejects_symlink_loop_and_unsupported_deny_contract(self):
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["layout"]["symlinks"].update(
            {"/cycle/a": "/cycle/b", "/cycle/b": "/cycle/a"})
        self.assertTrue(any("symlink map" in p for p in verifier.identity_problems(bad)))
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["checks"][1]["access"] = "unsupported"
        self.assertTrue(any("supported audit operation" in p for p in verifier.identity_problems(bad)))

    def test_matrix_rejects_dot_segments_in_audit_paths(self):
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["checks"][1]["path"] = "/safe/../outside"
        self.assertTrue(any("exact audit path" in p for p in verifier.identity_problems(bad)))
        with self.assertRaises(ValueError):
            verifier.resolve_matrix_path("/safe/../outside", {})

    def test_matrix_rejects_unsupported_socket_type(self):
        bad = json.loads(MATRIX_TEXT)
        target = next(net for net in bad["profiles"]["wpcaregrid-php-cli-production-build"]["network_checks"]
                      if net["id"] == "inet-stream-create")
        target["type"] = "SOCK_NOT_REAL"
        self.assertTrue(any("network check" in p for p in verifier.identity_problems(bad)))

    def test_matrix_rejects_generated_identifier_collisions_and_missing_peer_units(self):
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["checks"][0]["id"] = "abstract-connect"
        self.assertTrue(any("reserved identifier" in p for p in verifier.identity_problems(bad)))
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["peer_roles"] = ["ghost"]
        self.assertTrue(any("without a profile and unit" in p for p in verifier.identity_problems(bad)))
        bad = json.loads(MATRIX_TEXT)
        bad["role_profiles"]["orphan"] = "unused-profile"
        self.assertTrue(any("no corresponding systemd unit" in p for p in verifier.identity_problems(bad)))

    def test_matrix_rejects_shared_or_undeclared_role_profiles(self):
        bad = json.loads(MATRIX_TEXT)
        bad["role_profiles"]["nginx"] = bad["role_profiles"]["production"]
        self.assertTrue(any("two roles share one AppArmor profile" in p
                            for p in verifier.identity_problems(bad)))
        bad = json.loads(MATRIX_TEXT)
        bad["role_profiles"]["nginx"] = "ghost-profile"
        self.assertTrue(any("not declared in matrix profiles" in p
                            for p in verifier.identity_problems(bad)))

    def test_malformed_null_layout_and_socket_path_fail_closed(self):
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["layout"] = None
        self.assertTrue(any("valid layout object" in p for p in verifier.identity_problems(bad)))
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["sockets"][0]["path"] = None
        self.assertTrue(any("no exact audit path" in p for p in verifier.identity_problems(bad)))

    def test_malformed_matrix_containers_fail_closed_without_traceback(self):
        for key, value in (("role_units", None), ("role_runtime", None), ("profiles", None)):
            bad = json.loads(MATRIX_TEXT)
            bad[key] = value
            self.assertTrue(verifier.identity_problems(bad), key)
        for key in ("checks", "sockets", "peer_roles", "network_checks"):
            bad = json.loads(MATRIX_TEXT)
            bad["profiles"]["wpcaregrid-nginx"][key] = None
            self.assertTrue(verifier.identity_problems(bad), key)
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["checks"][0]["id"] = {"not": "hashable"}
        self.assertTrue(verifier.identity_problems(bad))
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-nginx"]["peer_roles"] = [{"not": "hashable"}]
        self.assertTrue(verifier.identity_problems(bad))
        bad = json.loads(MATRIX_TEXT)
        bad["profiles"]["wpcaregrid-php-cli-production-build"]["network_checks"][0]["family"] = {"not": "hashable"}
        self.assertTrue(verifier.identity_problems(bad))

    def test_hard_failures_return_rejected_before_ack_request(self):
        verdict, state = verify_all(mutate=lambda s: (
            next(r for r in s["confined"]["results"] if r["id"].endswith(":signal")).update(errno=0),
            next(r for r in s["baseline"]["results"] if r["id"].endswith(":mem")).update(errno=13),
            next(r for r in s["confined"]["results"] if r["id"].endswith(":mem")).update(errno=13)))
        with tempfile.TemporaryDirectory(prefix="wpcg-1017-verifier-") as temp:
            root = Path(temp)
            paths = [root / name for name in ("matrix.json", "base.json", "conf.json", "before.json", "after.json", "audit.jsonl")]
            paths[0].write_text(MATRIX_TEXT)
            for path, data in zip(paths[1:5], (state["baseline"], state["confined"], state["before"], state["after"])):
                path.write_text(json.dumps(data))
            paths[5].write_text(state["audit"])
            stdout, stderr = io.StringIO(), io.StringIO()
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                rc = verifier.main([str(paths[0]), "wpcaregrid-nginx", "post", *map(str, paths[1:])])
        self.assertEqual(rc, 1)
        self.assertIn("REJECTED:", stderr.getvalue())
        self.assertNotIn("owner_unproven_ack_required", stderr.getvalue())


if __name__ == "__main__":
    unittest.main(verbosity=2)
