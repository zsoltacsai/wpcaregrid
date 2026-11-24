#!/usr/bin/env python3
"""Offline policy tests for weekly dependency and release management."""

from __future__ import annotations

import importlib.util
import hashlib
import json
import subprocess
import sys
import tempfile
import tracemalloc
import unittest
from datetime import datetime, timezone, timedelta
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


LEDGER_SHA = "a" * 40
LEDGER_HEAD = "b" * 40
LEDGER_BASE = "c" * 40
LEDGER_FIXTURE = ROOT / "scripts/tests/fixtures/v3-artifact-ledger-historical.json"


def _ledger_prs():
    return [{"number": 57, "merge_commit_sha": LEDGER_SHA,
             "merged_at": "2026-11-16T00:00:00Z",
             "base": {"ref": weekly.PRIMARY_BRANCH, "sha": LEDGER_BASE},
             "head": {"sha": LEDGER_HEAD}}]


def _ledger_filler(comment_id: int, size: int):
    return {"id": comment_id, "body": "x" * size,
            "created_at": "2026-11-01T00:00:00Z",
            "author_association": "OWNER", "user": {"login": "zsoltacsai"}}


def _ledger_artifact(comment_id: int, role: str, agent: str):
    body = (f"{weekly.REVIEW_ARTIFACT_PREFIX}\nrole={role}\npr=57\n"
            f"head_sha={LEDGER_HEAD}\nbase_sha={LEDGER_BASE}\nagent={agent}\n"
            f"report_sha256={'d' * 64}\nreport_base64=eA==\n")
    return {"id": comment_id, "body": body,
            "created_at": "2026-11-16T12:00:00Z",
            "author_association": "OWNER", "user": {"login": "zsoltacsai"}}


def _ledger_corrections(historical):
    rendered = []
    for index, target in enumerate(historical):
        body_digest = hashlib.sha256(target["body"].encode("utf-8")).hexdigest()
        body = weekly._release_admission.render_artifact_correction(
            target_comment_id=target["id"], target_body_sha256=body_digest,
            target_pr=int(target["body"].split("pr=", 1)[1].splitlines()[0]),
            target_head_sha=target["body"].split("head_sha=", 1)[1].splitlines()[0],
            target_base_sha=target["body"].split("base_sha=", 1)[1].splitlines()[0],
            target_role=target["body"].split("role=", 1)[1].splitlines()[0])
        rendered.append({"id": 9_900_000_000 + index, "body": body,
                         "created_at": "2026-11-16T13:00:00Z",
                         "author_association": "OWNER", "user": {"login": "zsoltacsai"}})
    return rendered


def _ledger_pages(comments, page_size: int = 100):
    return [comments[i:i + page_size] for i in range(0, len(comments), page_size)] or [[]]


def _write_ledger(path: Path, pages):
    path.write_text(json.dumps(pages, separators=(",", ":")), encoding="utf-8")
    return path


class LedgerStreamingTest(unittest.TestCase):
    """Bounded page-by-page processing of the append-only #174 ledger."""

    def setUp(self):
        self._temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self._temporary.cleanup)
        self.tmp = Path(self._temporary.name)

    def ledger(self, name: str = "review-ledger.json") -> Path:
        return self.tmp / name

    def test_full_size_ledger_matches_the_list_parser(self):
        historical = json.loads(LEDGER_FIXTURE.read_text(encoding="utf-8"))
        corrections = _ledger_corrections(historical)
        fresh = [_ledger_artifact(5_000_001, "integration_qa", "hermione-qa"),
                 _ledger_artifact(5_000_002, "appsec", "moody-appsec")]
        relevant = historical + corrections + fresh
        fixed = len(json.dumps(relevant, separators=(",", ":")))
        filler_size = 6_000
        count = max(0, (9_324_175 - fixed) // (filler_size + 200))
        comments = [_ledger_filler(1_000_000 + index, filler_size) for index in range(count)]
        comments += relevant
        pages = _ledger_pages(comments)
        path = _write_ledger(self.ledger(), pages)
        size = path.stat().st_size
        self.assertGreaterEqual(size, 9_100_000)
        self.assertLessEqual(size, 9_700_000)
        scan = weekly._scan_ledger(path)
        self.assertEqual(scan.comment_count, len(comments))
        self.assertEqual(scan.page_count, len(pages))
        self.assertEqual(scan.byte_count, size)
        self.assertEqual(len(scan.sha256), 64)
        self.assertEqual(len(scan.comments), len(relevant))
        self.assertEqual(weekly.merged_pr_subject(_ledger_prs(), scan, LEDGER_SHA),
                         weekly.merged_pr_subject(_ledger_prs(), pages, LEDGER_SHA))

    def test_historical_correction_and_artifact_cases_match_the_list_parser(self):
        historical = json.loads(LEDGER_FIXTURE.read_text(encoding="utf-8"))
        corrections = _ledger_corrections(historical)
        fresh = [_ledger_artifact(5_000_001, "integration_qa", "hermione-qa"),
                 _ledger_artifact(5_000_002, "appsec", "moody-appsec")]
        bogus = {"id": 7_700_000_001, "created_at": "2026-11-16T13:00:00Z",
                 "author_association": "OWNER", "user": {"login": "zsoltacsai"},
                 "body": weekly._release_admission.render_artifact_correction(
                     target_comment_id=12345, target_body_sha256="e" * 64, target_pr=57,
                     target_head_sha=LEDGER_HEAD, target_base_sha=LEDGER_BASE,
                     target_role="integration_qa")}
        cases = {
            "corrected": historical + corrections + fresh,
            "missing-correction": historical + corrections[:-1] + fresh,
            "uncorrected": historical + fresh,
            "foreign-target": historical + corrections + [bogus] + fresh,
        }
        expected_results = {}
        for name, comments in cases.items():
            pages = _ledger_pages(comments)
            path = _write_ledger(self.tmp / f"{name}.json", pages)
            scan = weekly._scan_ledger(path)
            try:
                expected = weekly.merged_pr_subject(_ledger_prs(), pages, LEDGER_SHA)
            except weekly.PolicyError as error:
                expected = str(error)
            try:
                actual = weekly.merged_pr_subject(_ledger_prs(), scan, LEDGER_SHA)
            except weekly.PolicyError as error:
                actual = str(error)
            with self.subTest(case=name):
                self.assertEqual(actual, expected)
            expected_results[name] = expected
        self.assertEqual(expected_results["corrected"],
                         {"pr_number": 57, "head_sha": LEDGER_HEAD, "base_sha": LEDGER_BASE})
        self.assertEqual(expected_results["uncorrected"], "review_artifact_invalid")
        self.assertEqual(expected_results["missing-correction"], "review_artifact_invalid")
        self.assertEqual(expected_results["foreign-target"], "artifact_correction_target_invalid")

    def test_relevant_filter_follows_the_admission_prefixes(self):
        self.assertEqual(weekly.REVIEW_ARTIFACT_PREFIX,
                         weekly._release_admission._ARTIFACT_PREFIX)
        comments = [_ledger_filler(1, 4)]
        comments.append({"id": 2, "body": "WPCG_RELEASE_ARTIFACT_CORRECTION_V3 partial",
                         "created_at": "2026-11-16T13:00:00Z",
                         "author_association": "OWNER", "user": {"login": "zsoltacsai"}})
        scan = weekly._scan_ledger(_write_ledger(self.ledger(), _ledger_pages(comments)))
        self.assertEqual([comment["id"] for comment in scan.comments], [2])

    def test_cli_requires_matching_issue_comment_count(self):
        prs = self.tmp / "merged-prs.json"
        prs.write_text(json.dumps(_ledger_prs()), encoding="utf-8")
        comments = [_ledger_filler(1, 10),
                    _ledger_artifact(2, "integration_qa", "hermione-qa"),
                    _ledger_artifact(3, "appsec", "moody-appsec")]
        ledger = _write_ledger(self.ledger(), _ledger_pages(comments))
        metadata = self.tmp / "review-ledger-meta.json"
        output = self.tmp / "review-subject.json"
        arguments = ("review-subject", "--candidate-sha", LEDGER_SHA,
                     "--pull-requests", str(prs), "--ledger-comments", str(ledger),
                     "--ledger-metadata", str(metadata), "--output", str(output))
        command = [sys.executable, str(ROOT / "scripts/weekly_release_management.py"), *arguments]

        metadata.write_text(json.dumps({"number": 174, "comments": len(comments)}), encoding="utf-8")
        accepted = subprocess.run(command, capture_output=True, text=True)
        self.assertEqual(accepted.returncode, 0, accepted.stderr)
        self.assertEqual(json.loads(output.read_text(encoding="utf-8"))["pr_number"], 57)
        self.assertIn("CANDIDATE_LEDGER_SCAN name=review-ledger passes=2 bytes=", accepted.stderr)
        self.assertIn(f"comments={len(comments)}", accepted.stderr)
        self.assertNotIn(str(self.tmp), accepted.stderr)
        self.assertNotIn(".json", accepted.stderr)

        metadata.write_text(json.dumps({"number": 174, "comments": len(comments) + 1}),
                            encoding="utf-8")
        mismatch = subprocess.run(command, capture_output=True, text=True)
        self.assertEqual(mismatch.returncode, 2)
        self.assertIn("ledger_count_mismatch", mismatch.stderr)
        self.assertNotIn(str(self.tmp), mismatch.stderr)

        metadata.write_text(json.dumps({"number": 174}), encoding="utf-8")
        missing = subprocess.run(command, capture_output=True, text=True)
        self.assertEqual(missing.returncode, 2)
        self.assertIn("ledger_metadata_invalid", missing.stderr)

    def test_truncated_ledger_holds(self):
        pages = _ledger_pages([_ledger_filler(1, 50), _ledger_filler(2, 50)])
        path = self.ledger()
        _write_ledger(path, pages)
        path.write_text(path.read_text(encoding="utf-8")[:-25], encoding="utf-8")
        with self.assertRaisesRegex(weekly.PolicyError, "ledger_json_invalid"):
            weekly._scan_ledger(path)

    def test_invalid_json_holds(self):
        path = self.ledger()
        path.write_text("[[{\"id\": 1,,}]]", encoding="utf-8")
        with self.assertRaisesRegex(weekly.PolicyError, "ledger_json_invalid"):
            weekly._scan_ledger(path)

    def test_missing_ledger_holds(self):
        with self.assertRaisesRegex(weekly.PolicyError, "input_unavailable"):
            weekly._scan_ledger(self.tmp / "absent.json")

    def test_short_intermediate_page_holds(self):
        pages = [[_ledger_filler(index, 8) for index in range(100)],
                 [_ledger_filler(1000 + index, 8) for index in range(50)],
                 [_ledger_filler(2000 + index, 8) for index in range(10)]]
        path = _write_ledger(self.ledger(), pages)
        with self.assertRaisesRegex(weekly.PolicyError, "ledger_page_incomplete"):
            weekly._scan_ledger(path)

    def test_repeated_page_holds(self):
        page = [_ledger_filler(index, 8) for index in range(100)]
        path = _write_ledger(self.ledger(), [page, page])
        with self.assertRaisesRegex(weekly.PolicyError, "ledger_page_repeated"):
            weekly._scan_ledger(path)

    def test_duplicate_comment_identifier_holds(self):
        pages = [[_ledger_filler(1, 8), _ledger_filler(2, 8)],
                 [_ledger_filler(3, 8), _ledger_filler(1, 8)]]
        path = _write_ledger(self.ledger(), pages)
        with self.assertRaisesRegex(weekly.PolicyError, "artifact_correction_ambiguous"):
            weekly._scan_ledger(path)

    def test_non_object_comment_holds(self):
        path = _write_ledger(self.ledger(), [[_ledger_filler(1, 8), "not-a-comment"]])
        with self.assertRaisesRegex(weekly.PolicyError, "api_list_invalid"):
            weekly._scan_ledger(path)

    def test_oversized_page_holds(self):
        path = _write_ledger(self.ledger(),
                             [[_ledger_filler(index, 40) for index in range(100)]])
        with patch.object(weekly, "MAX_LEDGER_PAGE_BYTES", 512):
            with self.assertRaisesRegex(weekly.PolicyError, "ledger_page_too_large"):
                weekly._scan_ledger(path)

    def test_oversized_comment_holds(self):
        path = _write_ledger(self.ledger(), [[_ledger_filler(1, 400)]])
        with patch.object(weekly, "MAX_LEDGER_COMMENT_BYTES", 64):
            with self.assertRaisesRegex(weekly.PolicyError, "ledger_comment_too_large"):
                weekly._scan_ledger(path)

    def test_comment_and_page_count_caps_hold(self):
        path = _write_ledger(self.ledger(),
                             [[_ledger_filler(index, 8) for index in range(2)],
                              [_ledger_filler(100 + index, 8) for index in range(2)]])
        with patch.object(weekly, "MAX_LEDGER_COMMENTS", 3):
            with self.assertRaisesRegex(weekly.PolicyError, "ledger_comments_too_many"):
                weekly._scan_ledger(path)
        with patch.object(weekly, "MAX_LEDGER_PAGES", 1):
            with self.assertRaisesRegex(weekly.PolicyError, "ledger_pages_too_many"):
                weekly._scan_ledger(path)

    def test_whole_file_cap_holds(self):
        path = _write_ledger(self.ledger(), [_ledger_pages([_ledger_filler(1, 8)])[0]])
        with patch.object(weekly, "MAX_LEDGER_JSON_BYTES", 16):
            with self.assertRaisesRegex(weekly.PolicyError, "ledger_too_large"):
                weekly._scan_ledger(path)

    def test_two_passes_must_agree_on_the_identity(self):
        path = _write_ledger(self.ledger(), _ledger_pages([_ledger_filler(1, 8),
                                                           _ledger_artifact(2, "appsec", "moody-appsec")]))
        first = weekly._scan_ledger(path)
        self.assertEqual(weekly._scan_ledger_stable(path).sha256, first.sha256)
        drifted = {
            "sha256": weekly._LedgerScan(first.comments, first.comment_count,
                                         first.page_count, first.byte_count, "f" * 64),
            "comment_count": weekly._LedgerScan(first.comments, first.comment_count + 1,
                                                first.page_count, first.byte_count, first.sha256),
            "page_count": weekly._LedgerScan(first.comments, first.comment_count,
                                             first.page_count + 1, first.byte_count, first.sha256),
            "byte_count": weekly._LedgerScan(first.comments, first.comment_count,
                                             first.page_count, first.byte_count + 1, first.sha256),
        }
        for name, second in drifted.items():
            with self.subTest(pass_difference=name):
                with patch.object(weekly, "_scan_ledger", side_effect=(first, second)):
                    with self.assertRaisesRegex(weekly.PolicyError, "ledger_unstable"):
                        weekly._scan_ledger_stable(path)

    def test_cli_reads_the_ledger_twice(self):
        prs = self.tmp / "merged-prs.json"
        prs.write_text(json.dumps(_ledger_prs()), encoding="utf-8")
        ledger = _write_ledger(self.ledger(), _ledger_pages(
            [_ledger_filler(1, 8), _ledger_artifact(2, "integration_qa", "hermione-qa"),
             _ledger_artifact(3, "appsec", "moody-appsec")]))
        metadata = self.tmp / "review-ledger-meta.json"
        metadata.write_text(json.dumps({"number": 174, "comments": 3}), encoding="utf-8")
        output = self.tmp / "review-subject.json"
        calls = []
        real_scan = weekly._scan_ledger

        def counted(path):
            calls.append(path)
            return real_scan(path)

        with patch.object(weekly, "_scan_ledger", side_effect=counted):
            code = weekly.main(("review-subject", "--candidate-sha", LEDGER_SHA,
                                "--pull-requests", str(prs), "--ledger-comments", str(ledger),
                                "--ledger-metadata", str(metadata), "--output", str(output)))
        self.assertEqual(code, 0)
        self.assertEqual(len(calls), 2)
        self.assertTrue(all(path == ledger for path in calls))
        self.assertEqual(json.loads(output.read_text(encoding="utf-8"))["pr_number"], 57)

    def test_unterminated_value_holds_with_bounded_memory(self):
        path = self.ledger()
        path.write_text('[[' + '"' + "x" * 4_000, encoding="utf-8")
        with patch.object(weekly, "MAX_LEDGER_PAGE_BYTES", 1_024):
            with self.assertRaisesRegex(weekly.PolicyError, "ledger_page_too_large"):
                weekly._scan_ledger(path)

    def test_bare_scalar_page_holds(self):
        path = self.ledger()
        path.write_text("[123456789]", encoding="utf-8")
        with self.assertRaisesRegex(weekly.PolicyError, "ledger_json_invalid"):
            weekly._scan_ledger(path)

    def test_generic_json_bound_stays_for_other_inputs(self):
        path = _write_ledger(self.ledger(), _ledger_pages([_ledger_filler(1, 200)]))
        with patch.object(weekly, "MAX_JSON_BYTES", 64):
            with self.assertRaisesRegex(weekly.PolicyError, "input_too_large"):
                weekly._read_json(path)
            # Only the ledger has its own bounds; the generic one is untouched.
            self.assertEqual(weekly._scan_ledger(path).comment_count, 1)

    def test_memory_is_bounded_by_page_not_by_file_size(self):
        small = _write_ledger(self.tmp / "small.json",
                              _ledger_pages([_ledger_filler(10_000 + index, 2_000)
                                             for index in range(800)]))
        large = _write_ledger(self.tmp / "large.json",
                              _ledger_pages([_ledger_filler(20_000 + index, 2_000)
                                             for index in range(3_200)]))

        def peak(path: Path) -> int:
            tracemalloc.start()
            try:
                weekly._scan_ledger(path)
                return tracemalloc.get_traced_memory()[1]
            finally:
                tracemalloc.stop()

        small_peak = peak(small)
        large_peak = peak(large)
        self.assertGreater(large.stat().st_size, 3 * small.stat().st_size)
        self.assertLess(large_peak, 3 * small_peak)
        self.assertLess(large_peak, 40_000_000)

    def _sample_ci_runs(self, sha="a" * 40, run_id=1001, conclusion="success", branch=None, event="push"):
        return {"workflow_runs": [{
            "id": run_id, "name": "CI", "head_sha": sha,
            "head_branch": branch or weekly.PRIMARY_BRANCH,
            "event": event, "status": "completed", "conclusion": conclusion,
            "created_at": "2026-11-22T20:00:00Z", "updated_at": "2026-11-22T20:30:00Z"
        }]}

    def _sample_ci_jobs(self, job_id=2001, conclusion="success", omit_step=None, failed_step=None):
        steps = []
        step_num = 1
        for key, name in weekly.CI_STAGING_REQUIRED_STEPS.items():
            if key == omit_step:
                continue
            step_conclusion = "failure" if key == failed_step else "success"
            steps.append({
                "number": step_num, "name": name,
                "status": "completed", "conclusion": step_conclusion
            })
            step_num += 1
        return {"jobs": [{
            "id": job_id, "name": "Server tests (PHP 8.5, mysql)",
            "status": "completed", "conclusion": conclusion, "steps": steps
        }]}

    def test_build_ci_staging_equivalent_receipt_positive(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha, run_id=5555)
        jobs = self._sample_ci_jobs(job_id=6666)
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        self.assertEqual(receipt["schema"], weekly.CI_STAGING_RECEIPT_SCHEMA)
        self.assertEqual(receipt["candidate_sha"], sha)
        self.assertEqual(receipt["ci_workflow_run_id"], 5555)
        self.assertEqual(receipt["job"]["id"], 6666)
        self.assertEqual(receipt["synthetic_only_scope"], True)
        self.assertEqual(receipt["production_data_used"], False)
        self.assertEqual(receipt["runtimes"]["php_version"], "8.5")
        self.assertEqual(receipt["runtimes"]["mysql_version"], "8.4")
        self.assertEqual(len(receipt["required_steps"]), len(weekly.CI_STAGING_REQUIRED_STEPS))
        self.assertTrue(weekly.SHA256_RE.fullmatch(receipt["receipt_digest_sha256"]))

    def test_build_ci_staging_equivalent_receipt_rejects_missing_exact_run(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha="b" * 40)
        jobs = self._sample_ci_jobs()
        with self.assertRaisesRegex(weekly.PolicyError, "exact_ci_run_missing_or_not_successful"):
            weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)

    def test_build_ci_staging_equivalent_receipt_rejects_failed_server_job(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs(conclusion="failure")
        with self.assertRaisesRegex(weekly.PolicyError, "ci_server_tests_job_missing_or_not_successful"):
            weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)

    def test_build_ci_staging_equivalent_receipt_rejects_missing_step(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs(omit_step="http_smoke_marketing_only")
        with self.assertRaisesRegex(weekly.PolicyError, "ci_step_http_smoke_marketing_only_missing_or_failed"):
            weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)

    def test_build_ci_staging_equivalent_receipt_rejects_failed_step(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs(failed_step="migration_recovery_rehearsal")
        with self.assertRaisesRegex(weekly.PolicyError, "ci_step_migration_recovery_rehearsal_missing_or_failed"):
            weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)

    def test_validate_ci_staging_equivalent_receipt_positive(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        result = weekly.validate_ci_staging_equivalent_receipt(receipt, sha)
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["candidate_sha"], sha)
        self.assertEqual(result["schema"], weekly.CI_STAGING_RECEIPT_SCHEMA)

    def test_validate_ci_staging_equivalent_receipt_rejects_tampered_digest(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        receipt["ci_workflow_run_id"] = 99999
        with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_digest_invalid"):
            weekly.validate_ci_staging_equivalent_receipt(receipt, sha)

    def test_validate_ci_staging_equivalent_receipt_rejects_sha_mismatch(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_sha_mismatch"):
            weekly.validate_ci_staging_equivalent_receipt(receipt, "b" * 40)

    def test_validate_ci_staging_equivalent_receipt_rejects_unsupported_schema(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        receipt["schema"] = "wpcg-unsupported-schema-v99"
        receipt["receipt_digest_sha256"] = weekly.compute_ci_staging_receipt_digest(receipt)
        with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_schema_unsupported"):
            weekly.validate_ci_staging_equivalent_receipt(receipt, sha)

    def test_validate_ci_staging_equivalent_receipt_rejects_scope_violation(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        receipt["production_data_used"] = True
        receipt["receipt_digest_sha256"] = weekly.compute_ci_staging_receipt_digest(receipt)
        with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_scope_invalid"):
            weekly.validate_ci_staging_equivalent_receipt(receipt, sha)

    def test_validate_ci_staging_equivalent_receipt_rejects_runtime_violation(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        receipt["runtimes"]["php_version"] = "8.4"
        receipt["receipt_digest_sha256"] = weekly.compute_ci_staging_receipt_digest(receipt)
        with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_runtime_invalid"):
            weekly.validate_ci_staging_equivalent_receipt(receipt, sha)

    def test_validate_ci_staging_equivalent_receipt_rejects_expired(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        # Age the just-created receipt itself so the expiry test stays deterministic
        # regardless of when CI runs it.
        now = datetime.fromisoformat(receipt["created_at"]) + timedelta(days=9)
        with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_expired"):
            weekly.validate_ci_staging_equivalent_receipt(receipt, sha, now=now)

    def test_validate_ci_staging_equivalent_receipt_rejects_future_skew(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        receipt_time = datetime.fromisoformat(receipt["created_at"])
        past_time = receipt_time - timedelta(minutes=10)
        with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_future_skew"):
            weekly.validate_ci_staging_equivalent_receipt(receipt, sha, now=past_time)

    def test_candidate_manifest_with_ci_staging_receipt(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha, run_id=123)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        with patch.object(weekly, "_git", side_effect=[sha, sha]):
            result = weekly.candidate_manifest(sha, "100", "123", ci_staging_receipt=receipt)
        self.assertEqual(result["candidate_sha"], sha)
        self.assertEqual(result["staging_status"], "STAGING_EQUIVALENT_CI_VERIFIED")
        self.assertEqual(result["freeze_status"], "FROZEN")
        self.assertEqual(result["production_status"], "DISABLED")
        self.assertEqual(result["ci_staging_run_id"], 123)
        self.assertEqual(result["ci_staging_receipt_digest_sha256"], receipt["receipt_digest_sha256"])

    def test_candidate_manifest_rejects_tampered_ci_staging_receipt(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha, run_id=123)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        receipt["candidate_sha"] = "c" * 40
        with patch.object(weekly, "_git", side_effect=[sha, sha]):
            with self.assertRaisesRegex(weekly.PolicyError, "ci_staging_receipt_sha_mismatch"):
                weekly.candidate_manifest(sha, "100", "123", ci_staging_receipt=receipt)

    def test_verify_latest_staging_deployment_with_ci_receipt(self):
        sha = "a" * 40
        runs = self._sample_ci_runs(sha=sha, run_id=888)
        jobs = self._sample_ci_jobs()
        receipt = weekly.build_ci_staging_equivalent_receipt(runs, jobs, sha)
        res = weekly.verify_latest_staging_deployment(None, sha, ci_receipt=receipt)
        self.assertEqual(res["status"], "PASS")
        self.assertEqual(res["environment"], "staging-equivalent-ci")
        self.assertEqual(res["ci_workflow_run_id"], 888)
        self.assertEqual(res["receipt_digest_sha256"], receipt["receipt_digest_sha256"])

    def test_production_gate_remains_held_even_with_staging_smoke_match(self):
        sha = "a" * 40
        gate = weekly.production_gate(first_launch_sha="", recurring_enabled="false",
                                      candidate_sha=sha, staging_sha=sha,
                                      staging_smoke_sha=sha, recovery_receipt=None,
                                      deployment_lock_clear="false", incident_clear="false")
        self.assertEqual(gate["status"], "HOLD")
        self.assertEqual(gate["deployment_executed"], False)
        self.assertFalse(gate["checks"]["first_launch_completed"])
        self.assertFalse(gate["checks"]["recurring_policy_activated"])
        self.assertFalse(gate["checks"]["recovery_receipt_exact_sha"])


if __name__ == "__main__":
    unittest.main()
