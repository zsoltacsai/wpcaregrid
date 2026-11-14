#!/usr/bin/env python3
"""Deterministic fail-closed tests for production release admission V3."""

from __future__ import annotations

import base64
import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import production_release_controller_v3 as CONTROLLER
from scripts.release_admission_v3 import (
    AdmissionRefused,
    REQUIRED_CI_JOBS,
    verify_release_admission,
)
from scripts.release_review_policy_v3 import render_record

OWNER = "zsoltacsai"
CANDIDATE = "a" * 40
BASE = "b" * 40
HEAD = "c" * 40
REPORT_QA = b"independent QA report\n"
REPORT_APPSEC = b"independent AppSec report\n"


def ledger_record(role, agent, report, comment_id, *, decision="PASS", sha=HEAD, pr=383):
    digest = hashlib.sha256(report).hexdigest()
    return {
        "id": comment_id,
        "created_at": f"2026-11-14T01:00:{comment_id % 60:02d}Z",
        "author_association": "OWNER",
        "user": {"login": OWNER},
        "body": render_record(role=role, pr=pr, head_sha=sha, agent=agent,
                              decision=decision, evidence_sha256=digest),
    }


def artifact(role, agent, report, comment_id, *, sha=HEAD, pr=383, digest=None,
             login=OWNER, association="OWNER"):
    actual = digest or hashlib.sha256(report).hexdigest()
    body = (
        "WPCG_RELEASE_REVIEW_ARTIFACT_V3\n"
        f"role={role}\n"
        f"pr={pr}\n"
        f"head_sha={sha}\n"
        f"agent={agent}\n"
        f"report_sha256={actual}\n"
        f"report_base64={base64.b64encode(report).decode('ascii')}\n"
    )
    return {"id": comment_id, "created_at": f"2026-11-14T02:00:{comment_id % 60:02d}Z",
            "author_association": association, "user": {"login": login}, "body": body}


def fixture():
    pr_jobs = [{"id": index, "name": name, "status": "completed", "conclusion": "success"}
               for index, name in enumerate(REQUIRED_CI_JOBS, start=1)]
    primary_jobs = [dict(job) for job in pr_jobs]
    pr_run = {
        "head_sha": HEAD, "event": "pull_request", "path": ".github/workflows/ci.yml",
        "status": "completed", "conclusion": "success",
        "pull_requests": [{"number": 383, "base": {"ref": "claude/ecstatic-wozniak-oemyuf"}}],
    }
    primary_run = {
        "head_sha": CANDIDATE, "event": "push", "path": ".github/workflows/ci.yml",
        "status": "completed", "conclusion": "success",
    }
    pr = {
        "number": 383, "state": "closed", "merged": True,
        "merge_commit_sha": CANDIDATE,
        "base": {"ref": "claude/ecstatic-wozniak-oemyuf"},
        "head": {"sha": HEAD},
    }
    merge = {"sha": CANDIDATE, "parents": [{"sha": BASE}, {"sha": HEAD}]}
    comments = [
        ledger_record("integration_qa", "qa-agent-independent", REPORT_QA, 1),
        ledger_record("appsec", "appsec-agent-independent", REPORT_APPSEC, 2),
        artifact("integration_qa", "qa-agent-independent", REPORT_QA, 3),
        artifact("appsec", "appsec-agent-independent", REPORT_APPSEC, 4),
    ]
    return {
        "candidate_sha": CANDIDATE, "primary_sha": CANDIDATE, "pr_number": 383,
        "expected_pr_head_sha": HEAD, "expected_base_sha": BASE,
        "pr": pr, "merge_commit": merge,
        "pr_ci_run": pr_run, "pr_ci_jobs": pr_jobs,
        "primary_ci_run": primary_run, "primary_ci_jobs": primary_jobs,
        "ledger_comments": comments, "repository_owner": OWNER,
    }


def refusal_reason(**changes):
    args = fixture()
    args.update(changes)
    with unittest.TestCase().assertRaises(AdmissionRefused) as caught:
        verify_release_admission(**args)
    return str(caught.exception)


class ReleaseAdmissionV3Tests(unittest.TestCase):
    def test_exact_candidate_pr_base_reviews_artifacts_and_ci_pass(self):
        result = verify_release_admission(**fixture())
        self.assertEqual(result["result"], "PASS")
        self.assertEqual(result["candidate_sha"], CANDIDATE)
        self.assertEqual(result["pr_head_sha"], HEAD)
        self.assertEqual(result["pr_base_sha"], BASE)
        self.assertEqual(result["ci"], {"pr_head": "PASS", "primary_merge_sha": "PASS"})
        self.assertEqual(result["integration_qa"]["agent"], "qa-agent-independent")

    def test_candidate_mismatch_is_refused(self):
        self.assertEqual(refusal_reason(primary_sha="d" * 40), "candidate_not_current_primary")

    def test_invalid_candidate_sha_is_refused(self):
        self.assertEqual(refusal_reason(candidate_sha="not-a-sha"), "candidate_not_current_primary")

    def test_pr_head_mismatch_is_refused(self):
        self.assertEqual(refusal_reason(expected_pr_head_sha="d" * 40), "merged_pr_target_invalid")

    def test_merge_base_mismatch_is_refused(self):
        self.assertEqual(refusal_reason(expected_base_sha="d" * 40), "merged_pr_base_mismatch")

    def test_pr_must_be_merged_to_protected_primary_name(self):
        args = fixture()
        args["pr"] = {**args["pr"], "merged": False}
        self.assertEqual(refusal_reason(pr=args["pr"]), "merged_pr_identity_invalid")
        args = fixture()
        args["pr"] = {**args["pr"], "base": {"ref": "other-branch"}}
        self.assertEqual(refusal_reason(pr=args["pr"]), "merged_pr_target_invalid")

    def test_missing_review_role_is_refused(self):
        args = fixture()
        args["ledger_comments"] = args["ledger_comments"][:2:2] + args["ledger_comments"][2:3]
        self.assertEqual(refusal_reason(ledger_comments=args["ledger_comments"]),
                         "review_evidence_missing")

    def test_review_for_other_sha_is_refused(self):
        args = fixture()
        args["ledger_comments"] = [dict(comment) for comment in args["ledger_comments"]]
        for comment in args["ledger_comments"]:
            if "WPCG_RELEASE_REVIEW_V3\n" in comment["body"]:
                comment["body"] = comment["body"].replace(f"head_sha={HEAD}", f"head_sha={'d' * 40}")
            if "WPCG_RELEASE_REVIEW_ARTIFACT_V3\n" in comment["body"]:
                comment["body"] = comment["body"].replace(f"head_sha={HEAD}", f"head_sha={'d' * 40}")
        self.assertEqual(refusal_reason(ledger_comments=args["ledger_comments"]),
                         "review_evidence_missing")

    def test_latest_nonpass_review_is_refused(self):
        comments = fixture()["ledger_comments"]
        comments.append(ledger_record("appsec", "appsec-agent-independent", REPORT_APPSEC, 10,
                                      decision="BLOCKED"))
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_not_pass")

    def test_same_agent_for_both_roles_is_refused(self):
        comments = fixture()["ledger_comments"]
        comments[1] = ledger_record("appsec", "qa-agent-independent", REPORT_APPSEC, 2)
        comments[3] = artifact("appsec", "qa-agent-independent", REPORT_APPSEC, 4)
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_roles_not_separate")

    def test_malformed_owner_v3_record_fails_closed(self):
        comments = fixture()["ledger_comments"]
        comments.append({"id": 5, "created_at": "2026-11-14T03:00:00Z",
                         "author_association": "OWNER", "user": {"login": OWNER},
                         "body": "WPCG_RELEASE_REVIEW_V3\nrole=appsec\nBROKEN"})
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_record_invalid")

    def test_report_hash_mismatch_is_refused(self):
        comments = fixture()["ledger_comments"]
        comments[2] = artifact("integration_qa", "qa-agent-independent", REPORT_QA, 3,
                               digest="0" * 64)
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_artifact_hash_mismatch")

    def test_report_artifact_agent_or_record_hash_mismatch_is_refused(self):
        comments = fixture()["ledger_comments"]
        comments[2] = artifact("integration_qa", "different-agent", REPORT_QA, 3)
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_artifact_record_mismatch")

    def test_missing_artifact_is_refused(self):
        comments = fixture()["ledger_comments"][:2]
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_artifact_missing")

    def test_foreign_artifact_is_ignored(self):
        comments = fixture()["ledger_comments"]
        comments.append(artifact("appsec", "other", b"other report", 8,
                                 sha="d" * 40, pr=384))
        self.assertEqual(verify_release_admission(**{**fixture(), "ledger_comments": comments})["result"],
                         "PASS")

    def test_malformed_non_owner_artifact_cannot_veto_release(self):
        comments = fixture()["ledger_comments"]
        comments.append({"id": 99, "created_at": "2026-11-14T03:00:00Z",
                         "author_association": "NONE", "user": {"login": "untrusted"},
                         "body": "WPCG_RELEASE_REVIEW_ARTIFACT_V3\nmalformed"})
        self.assertEqual(verify_release_admission(**{**fixture(), "ledger_comments": comments})["result"],
                         "PASS")

    def test_malformed_owner_artifact_still_fails_closed(self):
        comments = fixture()["ledger_comments"]
        comments.append({"id": 99, "created_at": "2026-11-14T03:00:00Z",
                         "author_association": "OWNER", "user": {"login": OWNER},
                         "body": "WPCG_RELEASE_REVIEW_ARTIFACT_V3\nmalformed"})
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_artifact_invalid")

    def test_pr_ci_stale_or_failed_is_refused(self):
        args = fixture()
        args["pr_ci_run"] = {**args["pr_ci_run"], "head_sha": "d" * 40}
        self.assertEqual(refusal_reason(pr_ci_run=args["pr_ci_run"]), "ci_run_invalid")
        args = fixture()
        args["primary_ci_run"] = {**args["primary_ci_run"], "conclusion": "failure"}
        self.assertEqual(refusal_reason(primary_ci_run=args["primary_ci_run"]), "ci_run_invalid")

    def test_missing_failed_or_duplicate_required_job_is_refused(self):
        args = fixture()
        args["primary_ci_jobs"] = args["primary_ci_jobs"][:-1]
        self.assertEqual(refusal_reason(primary_ci_jobs=args["primary_ci_jobs"]),
                         "required_ci_check_failed")
        args = fixture()
        args["pr_ci_jobs"][0] = {**args["pr_ci_jobs"][0], "conclusion": "cancelled"}
        self.assertEqual(refusal_reason(pr_ci_jobs=args["pr_ci_jobs"]), "required_ci_check_failed")
        args = fixture()
        args["primary_ci_jobs"].append(dict(args["primary_ci_jobs"][0]))
        self.assertEqual(refusal_reason(primary_ci_jobs=args["primary_ci_jobs"]),
                         "required_ci_check_failed")

    def test_pr_ci_must_bind_exact_pr_and_base(self):
        args = fixture()
        args["pr_ci_run"] = {**args["pr_ci_run"],
                             "pull_requests": [{"number": 382,
                                                "base": {"ref": "claude/ecstatic-wozniak-oemyuf"}}]}
        self.assertEqual(refusal_reason(pr_ci_run=args["pr_ci_run"]), "ci_pr_binding_invalid")


class ReadOnlyControllerTests(unittest.TestCase):
    def test_controller_fetches_protected_primary_and_returns_read_only_admission(self):
        data = fixture()

        def request(path):
            if path.startswith("/repos/zsoltacsai/wpcaregrid/branches/"):
                return {"protected": True, "commit": {"sha": CANDIDATE}}
            if path.endswith("/pulls/383"):
                return data["pr"]
            if path.endswith(f"/commits/{CANDIDATE}"):
                return data["merge_commit"]
            if path == "/repos/zsoltacsai/wpcaregrid":
                return {"owner": {"login": OWNER}}
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request), \
             patch.object(CONTROLLER, "_workflow_run", side_effect=[
                 (data["pr_ci_run"], data["pr_ci_jobs"]),
                 (data["primary_ci_run"], data["primary_ci_jobs"])]), \
             patch.object(CONTROLLER, "_list", return_value=data["ledger_comments"]):
            result = CONTROLLER.verify(CANDIDATE, 383, HEAD, BASE)
        self.assertEqual(result["result"], "PASS")
        self.assertEqual(result["candidate_sha"], CANDIDATE)

    def test_controller_rejects_unprotected_primary_before_other_evidence_calls(self):
        with patch.object(CONTROLLER, "_request_json", return_value={
            "protected": False, "commit": {"sha": CANDIDATE}}), \
             patch.object(CONTROLLER, "_list") as list_request:
            with self.assertRaises(AdmissionRefused) as caught:
                CONTROLLER.verify(CANDIDATE, 383, HEAD, BASE)
        self.assertEqual(str(caught.exception), "primary_branch_not_protected")
        list_request.assert_not_called()

    def test_repository_root_api_path_is_allowed_and_unrelated_path_rejected(self):
        with patch.object(CONTROLLER.urllib.request, "build_opener") as opener:
            response = opener.return_value.open.return_value.__enter__.return_value
            response.status = 200
            response.read.return_value = b'{"owner":{"login":"zsoltacsai"}}'
            self.assertEqual(CONTROLLER._request_json("/repos/zsoltacsai/wpcaregrid"),
                             {"owner": {"login": "zsoltacsai"}})
        with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
            CONTROLLER._request_json("/repos/zsoltacsai/other")
        self.assertEqual(str(caught.exception), "api_path_invalid")

    def test_workflow_run_parses_github_jobs_envelope_without_mocking_workflow_run(self):
        data = fixture()
        jobs = data["pr_ci_jobs"]
        run = {"id": 123, "head_sha": HEAD, "event": "pull_request",
               "path": ".github/workflows/ci.yml", "created_at": "2026-11-14T01:00:00Z",
               "pull_requests": [{"number": 383,
                                   "base": {"ref": "claude/ecstatic-wozniak-oemyuf"}}]}

        def request(path):
            if "/actions/runs?" in path:
                return {"total_count": 1, "workflow_runs": [run]}
            if path == "/repos/zsoltacsai/wpcaregrid/actions/runs/123/jobs?per_page=100&page=1":
                return {"total_count": len(jobs), "jobs": jobs}
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request):
            selected, actual_jobs = CONTROLLER._workflow_run(HEAD, event="pull_request", pr_number=383)
        self.assertEqual(selected, run)
        self.assertEqual(actual_jobs, jobs)

    def test_workflow_jobs_paginates_and_rejects_incomplete_envelope(self):
        jobs = [{"id": index, "name": f"job-{index}"} for index in range(1, 102)]
        calls = []

        def paged(path):
            calls.append(path)
            if path.endswith("page=1"):
                return {"total_count": 101, "jobs": jobs[:100]}
            if path.endswith("page=2"):
                return {"total_count": 101, "jobs": jobs[100:]}
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=paged):
            self.assertEqual(CONTROLLER._workflow_jobs(123), jobs)
        self.assertEqual(len(calls), 2)

        with patch.object(CONTROLLER, "_request_json", return_value={
                "total_count": 2, "jobs": jobs[:1]}):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER._workflow_jobs(123)
        self.assertEqual(str(caught.exception), "ci_jobs_incomplete")

    def test_isolated_documented_entrypoint_imports_and_returns_json_hold(self):
        script = Path(CONTROLLER.__file__).resolve()
        with tempfile.TemporaryDirectory() as cwd:
            result = subprocess.run(
                [sys.executable, "-I", "-B", str(script), "invalid"], cwd=cwd,
                text=True, capture_output=True, check=False, timeout=10)
        self.assertEqual(result.returncode, 3, result.stderr)
        self.assertEqual(json.loads(result.stdout),
                         {"result": "HOLD", "reason": "operation_not_allowed"})

    def test_cli_rejects_non_verification_operation(self):
        with patch.object(CONTROLLER, "verify") as verify:
            self.assertEqual(CONTROLLER.main(["deploy", CANDIDATE, "383", HEAD, BASE]), 3)
        verify.assert_not_called()


if __name__ == "__main__":
    unittest.main()
