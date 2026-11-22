#!/usr/bin/env python3
"""Offline policy tests for weekly dependency and release management."""

from __future__ import annotations

import importlib.util
import hashlib
import io
import contextlib
import tempfile
import json
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location(
    "weekly_release_management", ROOT / "scripts/weekly_release_management.py")
weekly = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(weekly)
SMOKE_SPEC = importlib.util.spec_from_file_location(
    "weekly_release_smoke", ROOT / "scripts/weekly_release_smoke.py")
smoke = importlib.util.module_from_spec(SMOKE_SPEC)
assert SMOKE_SPEC.loader is not None
SMOKE_SPEC.loader.exec_module(smoke)


def pr(number: int, package: str, ecosystem: str = "composer", label: str | None = None):
    labels = [{"name": label}] if label else []
    branch_ecosystem = {"npm": "npm_and_yarn", "github_actions": "github_actions"}.get(ecosystem, ecosystem)
    return {"number": number, "title": f"Bump {package} from 1.0.0 to 1.1.0 @owner <unsafe>",
            "html_url": f"https://github.com/zsoltacsai/wpcaregrid/pull/{number}",
            "user": {"login": "dependabot[bot]"},
            "head": {"ref": f"dependabot/{branch_ecosystem}/{package}-1.1.0"},
            "labels": labels}


def alert(number: int, package: str, severity: str, ecosystem: str = "composer"):
    return {"number": number, "state": "open",
            "dependency": {"manifest_path": "/composer.lock", "scope": "development"},
            "security_vulnerability": {"package": {"name": package, "ecosystem": ecosystem}},
            "security_advisory": {"ghsa_id": f"GHSA-{number}", "severity": severity}}


class WeeklyReleaseManagementTest(unittest.TestCase):
    def test_raw_byte_diagnostic_uses_exact_read_and_preserves_limit(self):
        self.assertEqual(weekly.MAX_JSON_BYTES, 8_000_000)
        for name, size in (("open-pulls", 313203), ("dependabot-alerts", 4),
                           ("open-pulls", 8_000_000), ("open-pulls", 8_000_001)):
            raw = b"[]" + b" " * (size - 2)
            stderr = io.StringIO()
            with patch.object(Path, "read_bytes", return_value=raw) as read:
                with contextlib.redirect_stderr(stderr):
                    if size > weekly.MAX_JSON_BYTES:
                        with self.assertRaisesRegex(weekly.PolicyError, "^input_too_large$"):
                            weekly._read_json(Path("/private/input"), diagnostic_name=name)
                    else:
                        self.assertEqual(weekly._read_json(Path("/private/input"),
                                                         diagnostic_name=name), [])
                read.assert_called_once()
            self.assertEqual(stderr.getvalue(),
                             f"CANDIDATE_JSON_READ name={name} bytes={size} limit=8000000\n")

    def test_intake_emits_both_read_diagnostics_on_stderr(self):
        with tempfile.TemporaryDirectory() as directory:
            paths = [Path(directory) / name for name in ("private-pulls", "private-alerts", "out")]
            paths[0].write_bytes(b"[]" + b" " * 313201)
            paths[1].write_bytes(b"[[]]")
            stderr = io.StringIO()
            with contextlib.redirect_stderr(stderr), contextlib.redirect_stdout(io.StringIO()):
                rc = weekly.main(["intake", "--pull-requests", str(paths[0]),
                                  "--alerts", str(paths[1]), "--output", str(paths[2])])
            self.assertEqual(rc, 0)
            self.assertEqual(stderr.getvalue(),
                             "CANDIDATE_JSON_READ name=open-pulls bytes=313203 limit=8000000\n"
                             "CANDIDATE_JSON_READ name=dependabot-alerts bytes=4 limit=8000000\n")

    def test_critical_and_high_security_prs_sort_ahead_of_routine(self):
        result = weekly.classify_dependency_updates(
            [pr(12, "laravel/framework"), pr(13, "laravel/pail"), pr(14, "vite", "npm")],
            [alert(21, "laravel/framework", "critical"), alert(22, "vite", "high", "npm")])
        self.assertEqual([item["category"] for item in result["updates"]],
                         ["security_critical", "security_high", "routine"])
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["updates"][0]["next_action"], "expedite")

    def test_unpatched_high_or_critical_alert_holds_intake(self):
        result = weekly.classify_dependency_updates([], [alert(23, "laravel/framework", "high")])
        self.assertEqual(result["status"], "HOLD")
        self.assertEqual(result["unpatched_critical_high"][0]["alert_number"], 23)
        self.assertEqual(result["unpatched_critical_high"][0]["manifest_path"], "/composer.lock")

    def test_security_labeled_pr_without_matching_alert_requires_manual_triage(self):
        result = weekly.classify_dependency_updates([pr(31, "unknown/package", label="security")], [])
        self.assertEqual(result["updates"][0]["category"], "security_unclassified")
        self.assertEqual(result["status"], "HOLD")

    def test_non_dependabot_pr_is_ignored(self):
        item = pr(40, "vendor/package")
        item["user"]["login"] = "someone-else"
        result = weekly.classify_dependency_updates([item], [])
        self.assertEqual(result["updates"], [])

    def test_incomplete_or_malformed_api_page_fails_closed(self):
        with self.assertRaises(weekly.PolicyError):
            weekly.classify_dependency_updates({"items": []}, [])
        with self.assertRaises(weekly.PolicyError):
            weekly.classify_dependency_updates([], [{"state": "open"}])

    def test_untrusted_pr_titles_are_sanitized_for_handoff(self):
        result = weekly.classify_dependency_updates([pr(51, "package")], [])
        markdown = weekly.dependency_markdown(result)
        self.assertNotIn("@owner", markdown)
        self.assertNotIn("<unsafe>", markdown)

    def test_production_gate_holds_when_activation_is_absent(self):
        sha = "a" * 40
        result = weekly.production_gate(first_launch_sha="", recurring_enabled="false",
                                        candidate_sha=sha, staging_sha=sha,
                                        staging_smoke_sha=sha, recovery_receipt=None,
                                        deployment_lock_clear="true", incident_clear="true")
        self.assertEqual(result["status"], "HOLD")
        self.assertFalse(result["deployment_executed"])
        self.assertFalse(result["checks"]["first_launch_completed"])
        self.assertFalse(result["checks"]["recurring_policy_activated"])

    def test_production_gate_requires_sha_bound_staging_and_restore(self):
        sha = "a" * 40
        first = "b" * 40
        receipt = {"result": "PASS", "environment": "production", "candidate_sha": sha}
        result = weekly.production_gate(first_launch_sha=first, recurring_enabled="true",
                                        candidate_sha=sha, staging_sha="c" * 40,
                                        staging_smoke_sha=sha, recovery_receipt=receipt,
                                        deployment_lock_clear="true", incident_clear="true")
        self.assertEqual(result["status"], "HOLD")
        self.assertFalse(result["checks"]["staging_exact_sha"])

    def test_production_gate_passes_only_when_all_gates_match(self):
        sha = "a" * 40
        receipt = {"result": "PASS", "environment": "production", "candidate_sha": sha}
        result = weekly.production_gate(first_launch_sha="b" * 40, recurring_enabled="true",
                                        candidate_sha=sha, staging_sha=sha,
                                        staging_smoke_sha=sha, recovery_receipt=receipt,
                                        deployment_lock_clear="true", incident_clear="true")
        self.assertEqual(result["status"], "PASS")
        self.assertFalse(result["deployment_executed"])

    def test_candidate_requires_exact_checkout_and_primary_remote_ref(self):
        sha = "a" * 40
        with patch.object(weekly, "_git", side_effect=[sha, "b" * 40]):
            with self.assertRaisesRegex(weekly.PolicyError, "candidate_sha_mismatch"):
                weekly.candidate_manifest(sha, "1", "2")

    def test_candidate_manifest_pins_lockfiles_and_starts_unfrozen(self):
        sha = "a" * 40
        with patch.object(weekly, "_git", side_effect=[sha, sha]):
            result = weekly.candidate_manifest(sha, "123", "456")
        self.assertEqual(result["candidate_sha"], sha)
        self.assertEqual(set(result["lockfile_sha256"]), set(weekly.LOCKFILES))
        self.assertEqual(result["freeze_status"], "NOT_FROZEN")
        self.assertEqual(result["production_status"], "DISABLED")

    def test_exact_ci_gate_rejects_latest_failure_even_after_earlier_success(self):
        sha = "a" * 40
        runs = {"workflow_runs": [
            {"id": 101, "run_number": 41, "head_sha": sha, "event": "push",
             "path": ".github/workflows/ci.yml", "created_at": "2026-11-14T12:00:00Z",
             "status": "completed", "conclusion": "success"},
            {"id": 102, "run_number": 42, "head_sha": sha, "event": "push",
             "path": ".github/workflows/ci.yml", "created_at": "2026-11-14T13:00:00Z",
             "status": "completed", "conclusion": "failure"},
        ]}
        with self.assertRaisesRegex(weekly.PolicyError, "latest_exact_sha_ci_not_successful"):
            weekly.latest_primary_ci_run(runs, sha)

    def test_exact_ci_gate_accepts_latest_completed_success_for_exact_sha(self):
        sha = "a" * 40
        result = weekly.latest_primary_ci_run({"workflow_runs": [
            {"id": 103, "run_number": 43, "head_sha": sha, "event": "push",
             "path": ".github/workflows/ci.yml", "created_at": "2026-11-14T14:00:00Z",
             "status": "completed", "conclusion": "success"},
        ]}, sha)
        self.assertEqual(result, 103)

    def test_review_subject_uses_two_owner_artifacts_and_exact_pr_head(self):
        sha, head, base = "a" * 40, "b" * 40, "c" * 40
        prs = [{"number": 57, "merge_commit_sha": sha, "merged_at": "2026-11-15T00:00:00Z",
                "base": {"ref": weekly.PRIMARY_BRANCH, "sha": base}, "head": {"sha": head}}]
        comments = []
        for comment_id, role, agent in ((1, "integration_qa", "hermione-qa"), (2, "appsec", "moody-appsec")):
            body = ("WPCG_RELEASE_REVIEW_ARTIFACT_V3\n" f"role={role}\npr=57\nhead_sha={head}\n"
                    f"base_sha={base}\nagent={agent}\nreport_sha256={'d' * 64}\nreport_base64=eA==\n")
            comments.append({"id": comment_id, "body": body, "author_association": "OWNER",
                             "user": {"login": "zsoltacsai"}})
        self.assertEqual(weekly.merged_pr_subject(prs, comments, sha),
                         {"pr_number": 57, "head_sha": head, "base_sha": base})

    def test_review_subject_refuses_missing_or_duplicate_artifacts(self):
        sha, head, base = "a" * 40, "b" * 40, "c" * 40
        prs = [{"number": 57, "merge_commit_sha": sha, "merged_at": "now",
                "base": {"ref": weekly.PRIMARY_BRANCH, "sha": base}, "head": {"sha": head}}]
        with self.assertRaisesRegex(weekly.PolicyError, "review_artifact_subject_missing_or_ambiguous"):
            weekly.merged_pr_subject(prs, [], sha)

    def test_review_subject_uses_shared_exact_corrections_and_requires_fresh_artifacts(self):
        merge, head, base = "a" * 40, "b" * 40, "c" * 40
        prs = [{"number": 57, "merge_commit_sha": merge, "merged_at": "now",
                "base": {"ref": weekly.PRIMARY_BRANCH, "sha": base},
                "head": {"sha": head}}]
        fresh = []
        for comment_id, role, agent in ((1, "integration_qa", "hermione-qa"),
                                        (2, "appsec", "moody-appsec")):
            body = ("WPCG_RELEASE_REVIEW_ARTIFACT_V3\n" f"role={role}\npr=57\nhead_sha={head}\n"
                    f"base_sha={base}\nagent={agent}\nreport_sha256={'d' * 64}\nreport_base64=eA==\n")
            fresh.append({"id": comment_id, "body": body, "created_at": "2026-11-16T12:00:00Z",
                          "author_association": "OWNER", "user": {"login": "zsoltacsai"}})

        fixture_path = ROOT / "scripts/tests/fixtures/v3-artifact-ledger-historical.json"
        historical = json.loads(fixture_path.read_text(encoding="utf-8"))
        with self.assertRaisesRegex(weekly.PolicyError, "review_artifact_invalid"):
            weekly.merged_pr_subject(prs, historical + fresh, merge)

        corrections = []
        for index, target in enumerate(historical):
            body_digest = hashlib.sha256(target["body"].encode("utf-8")).hexdigest()
            correction_body = weekly._release_admission.render_artifact_correction(
                target_comment_id=target["id"], target_body_sha256=body_digest,
                target_pr=int(target["body"].split("pr=", 1)[1].splitlines()[0]),
                target_head_sha=target["body"].split("head_sha=", 1)[1].splitlines()[0],
                target_base_sha=target["body"].split("base_sha=", 1)[1].splitlines()[0],
                target_role=target["body"].split("role=", 1)[1].splitlines()[0])
            corrections.append({"id": 9_900_000_000 + index, "body": correction_body,
                                "created_at": "2026-11-16T13:00:00Z",
                                "author_association": "OWNER", "user": {"login": "zsoltacsai"}})
        self.assertEqual(weekly.merged_pr_subject(prs, historical + corrections + fresh, merge),
                         {"pr_number": 57, "head_sha": head, "base_sha": base})
        with self.assertRaisesRegex(weekly.PolicyError, "review_artifact_invalid"):
            weekly.merged_pr_subject(prs, historical + corrections[:-1] + fresh, merge)

    def test_recovery_receipt_refuses_untrusted_parent_metadata(self):
        with self.assertRaises(weekly.PolicyError):
            weekly.validate_recovery_receipt(Path("/tmp/not-a-release-receipt.json"),
                                             environment="staging", candidate_sha="a" * 40)

    def test_recovery_receipt_contract_binds_backup_config_key_data_and_rollback(self):
        sha, source_sha = "a" * 40, "b" * 40
        now = weekly.datetime(2026, 11, 15, 12, 0, tzinfo=weekly.timezone.utc)
        receipt = {
            "schema": weekly.RECEIPT_SCHEMA, "result": "PASS", "environment": "staging",
            "candidate_sha": sha, "backup_id": "backup-2026-11-15-01",
            "backup_sha256": "c" * 64, "transfer_receipt_sha256": "d" * 64,
            "source_application_sha": source_sha, "configuration_recovery_sha256": "e" * 64,
            "app_key_recovery_sha256": "f" * 64, "destination_id": "isolated-clone-01",
            "destination_isolated": True, "restore_evidence_sha256": "1" * 64,
            "data_integrity_sha256": "2" * 64, "trigger_inventory_sha256": "3" * 64,
            "migration_rehearsal_sha256": "4" * 64, "rollback_sha": source_sha,
            "rollback_database_compatible": True, "rpo_seconds": 3600, "rto_seconds": 7200,
            "issued_at": "2026-11-15T10:00:00Z", "expires_at": "2026-11-16T10:00:00Z",
        }
        self.assertEqual(weekly.validate_recovery_receipt_document(
            receipt, environment="staging", candidate_sha=sha, now=now), receipt)
        del receipt["app_key_recovery_sha256"]
        with self.assertRaisesRegex(weekly.PolicyError, "recovery_receipt_digest_invalid"):
            weekly.validate_recovery_receipt_document(
                receipt, environment="staging", candidate_sha=sha, now=now)

    def test_staging_gate_requires_latest_success_for_exact_candidate(self):
        sha = "a" * 40
        now = weekly.datetime(2026, 11, 15, 12, 0, tzinfo=weekly.timezone.utc)
        deployments = [{"id": 81, "environment": "staging", "sha": sha,
                        "created_at": "2026-11-14T12:00:00Z"}]
        gate = weekly.verify_latest_staging_deployment(deployments, sha, now=now)
        self.assertEqual(gate["deployment_id"], 81)
        status = weekly.verify_staging_deployment_status(
            [{"deployment_url": f"https://api.github.com/repos/{weekly.REPOSITORY}/deployments/81",
              "environment": "staging", "state": "success", "created_at": "2026-11-14T12:10:00Z"}],
            deployment_id=81, candidate_sha=sha)
        self.assertEqual(status["status"], "PASS")

    def test_staging_gate_rejects_moved_or_failed_deployment(self):
        sha = "a" * 40
        now = weekly.datetime(2026, 11, 15, 12, 0, tzinfo=weekly.timezone.utc)
        with self.assertRaisesRegex(weekly.PolicyError, "staging_deployment_stale_or_mismatched"):
            weekly.verify_latest_staging_deployment(
                [{"id": 81, "environment": "staging", "sha": "b" * 40,
                  "created_at": "2026-11-14T12:00:00Z"}], sha, now=now)
        with self.assertRaisesRegex(weekly.PolicyError, "staging_deployment_not_successful"):
            weekly.verify_staging_deployment_status(
                [{"deployment_url": f"https://api.github.com/repos/{weekly.REPOSITORY}/deployments/81",
                  "environment": "staging", "state": "failure", "created_at": "2026-11-14T12:10:00Z"}],
                deployment_id=81, candidate_sha=sha)

    def test_staging_smoke_checks_localized_pages_and_marketing_only_routes(self):
        from urllib.error import HTTPError

        class Headers(dict):
            pass

        class Response:
            status = 200

            def __init__(self, body: bytes):
                self.headers = Headers({"Strict-Transport-Security": "max-age=31536000"})
                self.body = body

            def __enter__(self):
                return self

            def __exit__(self, *args):
                return False

            def read(self, limit: int):
                return self.body[:limit]

        class Opener:
            def open(self, request, timeout):
                path = request.full_url.removeprefix(smoke.BASE)
                if path in smoke.DENIED:
                    raise HTTPError(request.full_url, 404, "Not Found", {}, None)
                locale = "hu" if path.startswith("/hu") else "en"
                return Response(f'<html lang="{locale}"><body>synthetic</body></html>'.encode())

        result = smoke.run_smoke(opener=Opener())
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(len(result["results"]), 6)


if __name__ == "__main__":
    unittest.main()
