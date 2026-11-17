#!/usr/bin/env python3
"""Regression tests for scripts/npm_audit_gate.py (synthetic fixtures, no network)."""
import contextlib
import copy
import io
import datetime as dt
import importlib.util
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("npm_audit_gate", ROOT / "scripts/npm_audit_gate.py")
gate = importlib.util.module_from_spec(spec)
sys.modules["npm_audit_gate"] = gate
spec.loader.exec_module(gate)

POLICY = json.loads((ROOT / "config/security/npm-audit-exceptions.json").read_text(encoding="utf-8"))
TODAY = dt.date(2026, 11, 17)
GHSA_URL = "https://github.com/advisories/GHSA-fc5q-39xc-vjpf"
OTHER_URL = "https://github.com/advisories/GHSA-aaaa-bbbb-cccc"


def advisory(**overrides):
    value = {
        "source": 1247710, "name": "picomatch", "dependency": "picomatch",
        "title": "picomatch vulnerable to ReDoS through single-branch repeated extglobs",
        "url": GHSA_URL, "severity": "high", "cwe": ["CWE-1333"],
        "cvss": {"score": 7.5, "vectorString": "CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:H"},
        "range": "<=4.0.7",
    }
    value.update(overrides)
    return value


def entry(name, via, severity="high", nodes=None):
    return {"name": name, "severity": severity, "isDirect": False, "via": via, "effects": [],
            "range": "*", "nodes": nodes or [f"node_modules/{name}"], "fixAvailable": False}


def baseline_report():
    vulnerabilities = {
        "picomatch": entry("picomatch", [advisory()], nodes=[
            "node_modules/picomatch", "node_modules/vite-plugin-full-reload/node_modules/picomatch"]),
        "fdir": entry("fdir", ["picomatch"]),
        "tinyglobby": entry("tinyglobby", ["fdir", "picomatch"]),
        "vite-plugin-full-reload": entry("vite-plugin-full-reload", ["picomatch"]),
        "laravel-vite-plugin": entry("laravel-vite-plugin", ["tinyglobby", "vite", "vite-plugin-full-reload"]),
        "vite": entry("vite", ["picomatch", "tinyglobby"]),
    }
    return {"auditReportVersion": 2, "vulnerabilities": vulnerabilities, "metadata": counts(vulnerabilities)}


def counts(vulnerabilities):
    tally = {s: 0 for s in ("info", "low", "moderate", "high", "critical")}
    for item in vulnerabilities.values():
        tally[item["severity"]] += 1
    tally["total"] = len(vulnerabilities)
    return {"vulnerabilities": tally, "dependencies": {"prod": 1, "dev": 5, "optional": 0, "peer": 0, "total": 6}}


def with_entries(report, **changes):
    report = copy.deepcopy(report)
    report["vulnerabilities"].update(changes)
    report["metadata"] = counts(report["vulnerabilities"])
    return report


def lock(**overrides):
    packages = {"": {"name": "x"}}
    for path, version in (
        ("node_modules/picomatch", "4.0.7"), ("node_modules/fdir", "6.5.0"),
        ("node_modules/tinyglobby", "0.2.17"), ("node_modules/vite", "8.3.8"),
        ("node_modules/laravel-vite-plugin", "3.2.1"), ("node_modules/vite-plugin-full-reload", "1.2.0"),
        ("node_modules/vite-plugin-full-reload/node_modules/picomatch", "2.3.2"),
    ):
        packages[path] = {"version": version, "dev": True}
    packages.update(overrides)
    return {"lockfileVersion": 3, "packages": packages}


def manifest(**sections):
    value = {"dependencies": {"alpinejs": "^3"}, "devDependencies": {"vite": "^8"}}
    value.update(sections)
    return value


def registry(versions=("4.0.6", "4.0.7"), tags=None):
    return lambda _package: {"versions": list(versions), "dist_tags": tags or {"latest": versions[-1]}}


class GateTest(unittest.TestCase):
    def run_gate(self, report=None, *, exit_code=1, text=None, lock_data=None, manifest_data=None,
                 today=TODAY, lookup=None, policy=None):
        if text is None:
            text = json.dumps(report if report is not None else baseline_report())
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "package-lock.json").write_text(json.dumps(lock_data or lock()), encoding="utf-8")
            (root / "package.json").write_text(json.dumps(manifest_data or manifest()), encoding="utf-8")
            return gate.run_gate(text, exit_code, policy or POLICY, root, today, lookup or registry())

    def assertFails(self, result, fragment):
        self.assertFalse(result.passed, "gate unexpectedly passed")
        self.assertTrue(any(fragment in failure for failure in result.failures), result.failures)

    # ---- passing behaviour
    def test_policy_file_is_valid(self):
        gate.load_policy(ROOT / "config/security/npm-audit-exceptions.json")
        self.assertEqual(POLICY["expires"], "2026-12-01")
        self.assertEqual(POLICY["owner_approval"], "Owner GO-20.1")

    def test_excepted_only_report_passes(self):
        result = self.run_gate()
        self.assertTrue(result.passed, result.failures)
        self.assertTrue(any("GHSA-fc5q-39xc-vjpf" in note for note in result.notes))

    def test_moderate_and_low_do_not_affect_the_gate(self):
        report = with_entries(baseline_report(), lodash=entry("lodash", [advisory(source=5, url=OTHER_URL, name="lodash", severity="moderate")], "moderate"),
                              minimist=entry("minimist", [advisory(source=6, url=OTHER_URL, name="minimist", severity="low")], "low"))
        self.assertTrue(self.run_gate(report).passed)

    def test_no_high_vulnerabilities_marks_exception_unused(self):
        result = self.run_gate(baseline_report() | {"vulnerabilities": {}, "metadata": counts({})}, exit_code=0)
        self.assertTrue(result.passed, result.failures)
        self.assertIn("exception unused — remove it (docs/SECURITY_EXCEPTIONS.md)", result.notes)

    # ---- unrelated / expanded advisories
    def test_unrelated_high_advisory_on_unrelated_package_fails(self):
        report = with_entries(baseline_report(), lodash=entry("lodash", [advisory(source=9, url=OTHER_URL, name="lodash", range="<4.17.21")]))
        result = self.run_gate(report)
        self.assertFails(result, "lodash")

    def test_second_advisory_on_allowlisted_package_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["vite"]["via"].append(
            advisory(source=777, url=OTHER_URL, name="vite", range="<8.3.9"))
        result = self.run_gate(report)
        self.assertFails(result, "advisory not covered")

    def test_second_advisory_on_picomatch_entry_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["picomatch"]["via"].append(advisory(source=778, url=OTHER_URL))
        self.assertFails(self.run_gate(report), "advisory not covered")

    def test_critical_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["vite"]["severity"] = "critical"
        report["metadata"] = counts(report["vulnerabilities"])
        self.assertFails(self.run_gate(report), "critical vulnerability")

    def test_unexpected_package_name_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["rollup"] = entry("rollup", ["picomatch"])
        report["metadata"] = counts(report["vulnerabilities"])
        self.assertFails(self.run_gate(report), "rollup")

    def test_excepted_advisory_on_wrong_package_entry_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["vite"]["via"].append(advisory())
        self.assertFails(self.run_gate(report), "advisory not covered")

    def test_excepted_advisory_with_different_identity_fails(self):
        for label, change in (("source", {"source": 1247711}), ("url", {"url": OTHER_URL}),
                              ("range", {"range": "<=4.0.8"}), ("name", {"name": "picomatch2"})):
            with self.subTest(label):
                report = baseline_report()
                report["vulnerabilities"]["picomatch"]["via"] = [advisory(**change)]
                self.assertFails(self.run_gate(report), "advisory not covered")

    def test_string_via_pointing_to_missing_entry_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["fdir"]["via"] = ["picomatch", "ghost"]
        self.assertFails(self.run_gate(report), "'ghost'")

    def test_string_via_pointing_to_uncovered_entry_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["vite"]["via"].append("esbuild")
        report["vulnerabilities"]["esbuild"] = entry("esbuild", [advisory(source=5, url=OTHER_URL, name="esbuild")], "moderate")
        report["metadata"] = counts(report["vulnerabilities"])
        result = self.run_gate(report)
        self.assertFails(result, "esbuild")

    def test_via_cycle_without_advisory_fails_and_terminates(self):
        report = {"auditReportVersion": 2, "metadata": None, "vulnerabilities": {
            "vite": entry("vite", ["tinyglobby"]), "tinyglobby": entry("tinyglobby", ["vite"])}}
        report["metadata"] = counts(report["vulnerabilities"])
        self.assertFails(self.run_gate(report), "no excepted advisory")

    def test_via_cycle_with_excepted_advisory_passes(self):
        report = baseline_report()
        report["vulnerabilities"]["fdir"]["via"] = ["picomatch", "tinyglobby"]
        self.assertTrue(self.run_gate(report).passed)

    # ---- exposure
    def test_prod_lock_entry_fails(self):
        for flags in ({"dev": None}, {"dev": True, "optional": True}, {"dev": True, "peer": True},
                      {"dev": True, "devOptional": True}, {"dev": False}):
            with self.subTest(flags):
                data = lock(**{"node_modules/vite": {"version": "8.3.8", **flags}})
                self.assertFails(self.run_gate(lock_data=data), "not dev-only")

    def test_nested_prod_picomatch_lock_entry_fails(self):
        data = lock(**{"node_modules/vite-plugin-full-reload/node_modules/picomatch": {"version": "2.3.2"}})
        self.assertFails(self.run_gate(lock_data=data), "not dev-only")

    def test_package_in_package_json_dependencies_fails(self):
        for section in ("dependencies", "optionalDependencies", "peerDependencies"):
            with self.subTest(section):
                data = manifest(**{section: {"vite": "^8"}})
                self.assertFails(self.run_gate(manifest_data=data), "production exposure")

    def test_lock_picomatch_outside_excepted_range_fails(self):
        data = lock(**{"node_modules/picomatch": {"version": "4.0.8", "dev": True}})
        self.assertFails(self.run_gate(lock_data=data), "outside the excepted range")

    def test_missing_lock_entry_fails(self):
        data = lock()
        del data["packages"]["node_modules/fdir"]
        self.assertFails(self.run_gate(lock_data=data), "no entry for it")

    # ---- expiry and registry
    def test_expired_exception_fails(self):
        for day in (dt.date(2026, 12, 1), dt.date(2027, 1, 5)):
            with self.subTest(day):
                self.assertFails(self.run_gate(today=day), "expired on 2026-12-01")
        self.assertTrue(self.run_gate(today=dt.date(2026, 11, 30)).passed)

    def test_fixed_picomatch_version_published_fails(self):
        for versions, tags in ((("4.0.7", "4.0.8"), None), (("4.0.7", "5.0.0"), None), (("4.0.7",), {"latest": "4.1.0"})):
            with self.subTest(versions):
                self.assertFails(self.run_gate(lookup=registry(versions, tags)), "no longer applies")

    def test_prerelease_above_range_is_not_a_fix(self):
        self.assertTrue(self.run_gate(lookup=registry(("4.0.7", "4.1.0-beta.1"))).passed)

    def test_registry_failure_fails_closed(self):
        def broken(_package):
            raise RuntimeError("network down")
        self.assertFails(self.run_gate(lookup=broken), "fail closed")
        self.assertFails(self.run_gate(lookup=lambda _p: {"versions": [], "dist_tags": {}}), "fail closed")

    # ---- malformed npm output
    def test_malformed_json_fails(self):
        full = json.dumps(baseline_report())
        for text in ("", "   \n", "not json", full[: len(full) // 2], "[]", "null"):
            with self.subTest(text[:20]):
                self.assertFalse(self.run_gate(text=text).passed)

    def test_wrong_audit_report_version_fails(self):
        for version in (1, 3, "2", None, True):
            with self.subTest(version):
                report = baseline_report()
                report["auditReportVersion"] = version
                self.assertFails(self.run_gate(report), "auditReportVersion")

    def test_error_key_fails(self):
        report = baseline_report()
        report["error"] = {"code": "ENOAUDIT", "summary": "boom"}
        self.assertFails(self.run_gate(report), "reported an error")

    def test_missing_keys_fail(self):
        for key in ("vulnerabilities", "metadata"):
            with self.subTest(key):
                report = baseline_report()
                del report[key]
                self.assertFalse(self.run_gate(report).passed)
        report = baseline_report()
        del report["metadata"]["vulnerabilities"]["high"]
        self.assertFails(self.run_gate(report), "metadata.vulnerabilities.high")

    def test_npm_exit_code_other_than_0_or_1_fails(self):
        for code in (2, 127, -9):
            with self.subTest(code):
                self.assertFails(self.run_gate(exit_code=code), "exited with code")

    def test_exit_code_inconsistent_with_report_fails(self):
        self.assertFails(self.run_gate(exit_code=0), "exited 0")
        empty = {"auditReportVersion": 2, "vulnerabilities": {}, "metadata": counts({})}
        self.assertFails(self.run_gate(empty, exit_code=1), "exited 1")

    def test_empty_vulnerabilities_with_nonzero_high_count_fails(self):
        report = {"auditReportVersion": 2, "vulnerabilities": {}, "metadata": baseline_report()["metadata"]}
        self.assertFails(self.run_gate(report, exit_code=1), "metadata count mismatch")

    def test_metadata_count_mismatch_fails(self):
        report = baseline_report()
        report["metadata"]["vulnerabilities"]["high"] = 5
        self.assertFails(self.run_gate(report), "metadata count mismatch for high")
        report = baseline_report()
        report["metadata"]["vulnerabilities"]["critical"] = 1
        self.assertFails(self.run_gate(report), "metadata count mismatch for critical")
        report = baseline_report()
        report["metadata"]["vulnerabilities"]["total"] = 7
        self.assertFails(self.run_gate(report), "total")

    def test_unknown_severity_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["vite"]["severity"] = "severe"
        self.assertFalse(self.run_gate(report).passed)


    # ---- F1: dev dependencies must really be audited
    def test_dev_scan_of_zero_with_dev_lock_entries_fails(self):
        for report in (baseline_report(), {"auditReportVersion": 2, "vulnerabilities": {}, "metadata": counts({})}):
            report["metadata"]["dependencies"]["dev"] = 0
            with self.subTest(len(report["vulnerabilities"])):
                self.assertFails(self.run_gate(report, exit_code=1 if report["vulnerabilities"] else 0),
                                 "scanned 0 dev dependencies")

    def test_missing_dependency_counts_fail(self):
        report = baseline_report()
        del report["metadata"]["dependencies"]
        self.assertFails(self.run_gate(report), "metadata.dependencies.dev")

    def test_dev_zero_is_fine_when_lock_has_no_dev_entries(self):
        report = {"auditReportVersion": 2, "vulnerabilities": {}, "metadata": counts({})}
        report["metadata"]["dependencies"]["dev"] = 0
        data = {"lockfileVersion": 3, "packages": {"": {"name": "x"}, "node_modules/a": {"version": "1.0.0"}}}
        self.assertTrue(self.run_gate(report, exit_code=0, lock_data=data).passed)

    def test_npm_audit_argv_includes_dev_optional_peer(self):
        self.assertEqual(gate.NPM_AUDIT_ARGV,
                         ["npm", "audit", "--json", "--include=dev", "--include=optional", "--include=peer"])
        with mock.patch.object(gate.subprocess, "run") as run:
            run.return_value = subprocess.CompletedProcess([], 0, stdout="{}", stderr="")
            gate.run_npm_audit(ROOT)
        self.assertEqual(run.call_args.args[0], gate.NPM_AUDIT_ARGV)

    def test_npm_stderr_is_printed_and_neutralised(self):
        out = io.StringIO()
        with mock.patch.object(gate.subprocess, "run") as run, contextlib.redirect_stdout(out):
            run.return_value = subprocess.CompletedProcess([], 2, stdout="", stderr="::set-output name=x::1\nboom")
            gate.run_npm_audit(ROOT)
        for line in out.getvalue().splitlines():
            self.assertFalse(line.lstrip().startswith("::"), line)
        self.assertIn("boom", out.getvalue())

    def test_safe_text_defuses_workflow_commands(self):
        for raw in ("::error::x", "a\n::stop-commands::t", "\r::warning::y"):
            for line in gate.safe_text(raw).splitlines():
                self.assertFalse(line.startswith("::"))
                self.assertNotIn("::", line)

    # ---- F3: auto-invalidation
    def test_any_new_stable_version_fails_even_below_limit_line(self):
        for versions in (("4.0.7", "4.0.8"), ("4.0.7", "3.0.3"), ("4.0.7", "2.3.3"), ("4.0.7", "1.2.1"), ("4.0.7", "5.0.0")):
            with self.subTest(versions):
                self.assertFails(self.run_gate(lookup=registry(versions)), "known_stable_versions")

    def test_known_stable_versions_all_pass_and_policy_lists_them(self):
        known = POLICY["known_stable_versions"]
        self.assertIn("4.0.7", known)
        self.assertIn("2.3.2", known)
        self.assertEqual(max(known, key=gate.parse_stable), "4.0.7")
        self.assertTrue(self.run_gate(lookup=registry(tuple(known), {"latest": "4.0.7"})).passed)

    def test_real_fix_in_fix_available_fails(self):
        for fix in (True, {"name": "vite", "version": "8.4.0", "isSemVerMajor": False},
                    {"name": "vite", "version": "8.4.0"}):
            with self.subTest(fix):
                report = baseline_report()
                report["vulnerabilities"]["vite"]["fixAvailable"] = fix
                self.assertFails(self.run_gate(report), "real (non-major) fix")

    def test_major_downgrade_suggestion_is_not_a_fix(self):
        report = baseline_report()
        for item in report["vulnerabilities"].values():
            item["fixAvailable"] = {"name": "vite", "version": "6.2.7", "isSemVerMajor": True}
        self.assertTrue(self.run_gate(report).passed)

    # ---- F5: severity cross-check
    def test_moderate_entry_with_unrelated_high_via_fails(self):
        report = with_entries(baseline_report(), lodash=entry("lodash", [advisory(source=9, url=OTHER_URL, name="lodash")], "moderate"))
        self.assertFails(self.run_gate(report), "lodash")

    def test_moderate_entry_with_critical_via_fails_as_critical(self):
        report = with_entries(baseline_report(), lodash=entry("lodash", [advisory(source=9, url=OTHER_URL, name="lodash", severity="critical")], "low"))
        self.assertFails(self.run_gate(report), "critical vulnerability")

    def test_excepted_advisory_relabelled_critical_or_lower_fails(self):
        for severity in ("critical", "moderate", "low", None):
            with self.subTest(severity):
                report = baseline_report()
                report["vulnerabilities"]["picomatch"]["via"] = [advisory(severity=severity)]
                self.assertFails(self.run_gate(report), "advisory not covered")

    def test_covered_entry_without_nodes_fails(self):
        report = baseline_report()
        report["vulnerabilities"]["vite"]["nodes"] = []
        self.assertFails(self.run_gate(report), "no installed node")

    # ---- F7: scope is pinned
    def test_policy_scope_is_pinned(self):
        self.assertEqual(POLICY["allowed_packages"], ["picomatch", "fdir", "tinyglobby", "vite-plugin-full-reload",
                                                      "laravel-vite-plugin", "vite"])
        self.assertEqual(POLICY["id"], "GHSA-fc5q-39xc-vjpf")
        self.assertEqual(POLICY["advisory"], {
            "source_id": 1247710, "url": GHSA_URL, "package": "picomatch", "vulnerable_range": "<=4.0.7",
            "title": "picomatch vulnerable to ReDoS through single-branch repeated extglobs", "severity": "high"})
        self.assertEqual(POLICY["fixed_version_gate"], {"package": "picomatch", "max_vulnerable_version": "4.0.7"})

    def test_expiry_is_capped_by_hard_limit(self):
        self.assertEqual(gate.MAX_EXPIRY, dt.date(2026, 12, 1))
        policy = copy.deepcopy(POLICY)
        policy["expires"] = "2026-12-02"
        with self.assertRaises(ValueError):
            gate.validate_policy(policy)
        policy["expires"] = "2026-12-01"
        gate.validate_policy(policy)


class CliTest(unittest.TestCase):
    def setUp(self):
        stdout = contextlib.redirect_stdout(io.StringIO())
        stdout.__enter__()
        self.addCleanup(stdout.__exit__, None, None, None)
        env = mock.patch.dict(os.environ)
        env.start()
        self.addCleanup(env.stop)
        for name in gate.CI_ENV_VARS:  # these tests may use offline flags, which CI itself must refuse
            os.environ.pop(name, None)

    def test_offline_flags_are_refused_in_ci(self):
        os.environ["CI"] = "true"
        for flags in (["--audit-json", "x.json"], ["--audit-exit-code", "0"], ["--registry-json=r.json"],
                      ["--policy", "p.json"]):
            with self.subTest(flags):
                self.assertEqual(gate.main(flags, today=TODAY, registry_lookup=registry(),
                                           audit_runner=lambda _r: ("{}", 0)), 1)

    def test_cli_saves_raw_report_and_reports_failure(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "package-lock.json").write_text(json.dumps(lock()), encoding="utf-8")
            (root / "package.json").write_text(json.dumps(manifest()), encoding="utf-8")
            out = root / "out" / "report.json"
            raw = json.dumps(baseline_report())
            status = gate.main(["--root", str(root), "--policy", str(ROOT / "config/security/npm-audit-exceptions.json"),
                                "--report-out", str(out)],
                               today=TODAY, registry_lookup=registry(), audit_runner=lambda _root: (raw, 1))
            self.assertEqual(status, 0)
            self.assertEqual(out.read_text(encoding="utf-8"), raw)
            status = gate.main(["--root", str(root), "--policy", str(ROOT / "config/security/npm-audit-exceptions.json")],
                               today=TODAY, registry_lookup=registry(), audit_runner=lambda _root: ("", 2))
            self.assertEqual(status, 1)

    def test_policy_validation_rejects_broken_policy(self):
        for mutate in (lambda p: p.pop("expires"), lambda p: p.update(allowed_packages=[]),
                       lambda p: p["advisory"].update(source_id="1247710"), lambda p: p.update(id="GHSA-other")):
            policy = copy.deepcopy(POLICY)
            mutate(policy)
            with self.assertRaises(ValueError):
                gate.validate_policy(policy)

    def test_ci_uses_the_gate_and_no_bare_exception_flags(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("python3 scripts/npm_audit_gate.py", workflow)
        self.assertNotIn("npm audit --audit-level=high", workflow)
        self.assertNotIn("--omit=dev", workflow)
        gate_source = (ROOT / "scripts/npm_audit_gate.py").read_text(encoding="utf-8")
        for forbidden in ("--omit=dev", "--force", "|| true", "--audit-level"):
            self.assertNotIn(forbidden, gate_source)

    def npm_gate_steps(self):
        github = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        start = github.index("- name: npm advisories")
        github_step = github[start:github.index("\n      - name:", start + 10)]
        gitlab = (ROOT / ".gitlab-ci.yml").read_text(encoding="utf-8")
        start = gitlab.index("python3 scripts/tests/npm-audit-gate-test.py")
        gitlab_step = gitlab[start:gitlab.index("set -eu\n      version=8", start)]
        return github_step, gitlab_step

    def test_ci_gate_steps_contain_no_bypass(self):
        for step in self.npm_gate_steps():
            for forbidden in ("|| true", "continue-on-error", "--omit=dev", "--omit", "--force", "--audit-level",
                              "npm_config_omit", "NODE_ENV"):
                self.assertNotIn(forbidden, step)
            self.assertNotRegex(step, r"(?m)^\s*(- )?npm audit")  # only the gate may invoke npm audit
        self.assertNotIn("continue-on-error", (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8").split("npm advisories")[1].split("Secret scan")[0])

    def test_ci_invocation_is_pinned(self):
        github_step, gitlab_step = self.npm_gate_steps()
        for step in (github_step, gitlab_step):
            invocations = [line.strip() for line in step.splitlines() if "scripts/npm_audit_gate.py" in line
                           and "py_compile" not in line]
            self.assertEqual(len(invocations), 1, invocations)
            for flag in gate.OFFLINE_FLAGS:
                self.assertNotIn(flag, step)
            self.assertRegex(invocations[0], r'^(- )?python3 scripts/npm_audit_gate\.py --report-out "[^" ]+" \|\| status=\$\?$')
            self.assertIn('exit "$status"', step)
            self.assertIn("sha256sum", step)
            self.assertIn("npm --version", step)
            self.assertIn("py_compile scripts/npm_audit_gate.py scripts/tests/npm-audit-gate-test.py", step)
        self.assertIn('--report-out "$report"', github_step)
        self.assertIn('--report-out "$report"', gitlab_step)
        self.assertIn('report="$CI_PROJECT_DIR/npm-audit-report.json"', gitlab_step)
        self.assertIn('if [[ -s "$report" ]]; then cat -- "$report"; else echo "no report written"; fi', github_step)


if __name__ == "__main__":
    unittest.main()
