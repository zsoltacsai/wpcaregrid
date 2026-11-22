#!/usr/bin/env python3
"""Deterministic fail-closed tests for production release admission V3."""

from __future__ import annotations

import base64
import hashlib
import json
import re
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
    _verified_report_artifacts,
    render_artifact_correction,
    verify_release_admission,
)
from scripts.release_review_policy_v3_2 import render_record

OWNER = "zsoltacsai"
CANDIDATE = "a" * 40
BASE = "b" * 40
HEAD = "c" * 40
PRIMARY = "d" * 40


def primary_comparison(candidate=CANDIDATE, primary=PRIMARY, *, status="ahead",
                       ahead_by=3, behind_by=0, merge_base=None):
    return {
        "status": status, "ahead_by": ahead_by, "behind_by": behind_by,
        "base_commit": {"sha": candidate},
        "merge_base_commit": {"sha": merge_base or candidate},
    }
REPORT_QA = b"independent QA report\n"
REPORT_APPSEC = b"independent AppSec report\n"


def ledger_record(role, agent, report, comment_id, *, decision="PASS", sha=HEAD, pr=383):
    digest = hashlib.sha256(report).hexdigest()
    return {
        "id": comment_id,
        "created_at": f"2026-11-14T01:00:{comment_id % 60:02d}Z",
        "updated_at": f"2026-11-14T01:00:{comment_id % 60:02d}Z",
        "author_association": "OWNER",
        "user": {"login": OWNER},
        "body": render_record(role=role, pr=pr, head_sha=sha, agent=agent,
                              decision=decision, evidence_sha256=digest),
    }


def artifact(role, agent, report, comment_id, *, sha=HEAD, base=BASE, pr=383, digest=None,
             login=OWNER, association="OWNER"):
    actual = digest or hashlib.sha256(report).hexdigest()
    body = (
        "WPCG_RELEASE_REVIEW_ARTIFACT_V3\n"
        f"role={role}\n"
        f"pr={pr}\n"
        f"head_sha={sha}\n"
        f"base_sha={base}\n"
        f"agent={agent}\n"
        f"report_sha256={actual}\n"
        f"report_base64={base64.b64encode(report).decode('ascii')}\n"
    )
    return {"id": comment_id, "created_at": f"2026-11-14T02:00:{comment_id % 60:02d}Z",
            "updated_at": f"2026-11-14T02:00:{comment_id % 60:02d}Z",
            "author_association": association, "user": {"login": login}, "body": body}


def fixture():
    def jobs(run_id, head_sha):
        return [{"id": index + (run_id * 100), "run_id": run_id,
                 "run_attempt": 1, "head_sha": head_sha, "name": name,
                 "status": "completed", "conclusion": "success"}
                for index, name in enumerate(REQUIRED_CI_JOBS, start=1)]

    pr_jobs = jobs(1, HEAD)
    candidate_jobs = jobs(2, CANDIDATE)
    primary_jobs = jobs(3, PRIMARY)
    pr_run = {
        "id": 1, "run_attempt": 1,
        "head_sha": HEAD, "event": "pull_request", "path": ".github/workflows/ci.yml",
        "head_branch": "feature/release", "head_repository": {"full_name": OWNER + "/wpcaregrid"},
        "status": "completed", "conclusion": "success",
        "pull_requests": [{"number": 383, "base": {
            "ref": "claude/ecstatic-wozniak-oemyuf", "sha": BASE}}],
    }
    candidate_run = {
        "id": 2, "run_attempt": 1,
        "head_sha": CANDIDATE, "event": "push", "path": ".github/workflows/ci.yml",
        "head_branch": "claude/ecstatic-wozniak-oemyuf",
        "status": "completed", "conclusion": "success",
    }
    primary_run = {
        "id": 3, "run_attempt": 1,
        "head_sha": PRIMARY, "event": "push", "path": ".github/workflows/ci.yml",
        "head_branch": "claude/ecstatic-wozniak-oemyuf",
        "status": "completed", "conclusion": "success",
    }
    pr = {
        "number": 383, "state": "closed", "merged": True,
        "merge_commit_sha": CANDIDATE,
        "base": {"ref": "claude/ecstatic-wozniak-oemyuf", "sha": BASE},
        "head": {"sha": HEAD, "ref": "feature/release",
                 "repo": {"full_name": OWNER + "/wpcaregrid"}},
    }
    merge = {"sha": CANDIDATE, "parents": [{"sha": BASE}, {"sha": HEAD}]}
    comments = [
        ledger_record("integration_qa", "qa-agent-independent", REPORT_QA, 1),
        ledger_record("appsec", "appsec-agent-independent", REPORT_APPSEC, 2),
        artifact("integration_qa", "qa-agent-independent", REPORT_QA, 3),
        artifact("appsec", "appsec-agent-independent", REPORT_APPSEC, 4),
    ]
    return {
        "candidate_sha": CANDIDATE, "primary_sha": PRIMARY,
        "primary_comparison": primary_comparison(), "pr_number": 383,
        "expected_pr_head_sha": HEAD, "expected_base_sha": BASE,
        "pr": pr, "merge_commit": merge,
        "pr_ci_run": pr_run, "pr_ci_jobs": pr_jobs,
        "candidate_ci_run": candidate_run, "candidate_ci_jobs": candidate_jobs,
        "primary_ci_run": primary_run, "primary_ci_jobs": primary_jobs,
        "ledger_comments": comments, "repository_owner": OWNER,
    }


def controller_ci_responses(data):
    run_set = [
        (data["pr_ci_run"], data["pr_ci_jobs"]),
        (data["candidate_ci_run"], data["candidate_ci_jobs"]),
        (data["primary_ci_run"], data["primary_ci_jobs"]),
    ]
    return run_set + run_set


def refusal_reason(**changes):
    args = fixture()
    args.update(changes)
    with unittest.TestCase().assertRaises(AdmissionRefused) as caught:
        verify_release_admission(**args)
    return str(caught.exception)


MALFORMED_QA_417 = {
    "id": 6945646697,
    "created_at": "2026-11-17T10:37:47Z",
    "updated_at": "2026-11-17T10:37:47Z",
    "author_association": "OWNER",
    "user": {"login": OWNER},
    "body": (
        "WPCG_RELEASE_REVIEW_ARTIFACT_V3 role=integration_qa pr=417 "
        "head_sha=3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f "
        "base_sha=822f284575306c8a5474c37ac87672df24a70ae6 "
        "agent=codex.qa.pr417.3f0f84a "
        "report_sha256=0ccfc1ae515125cf810e8c78c76e976f67f8282c35724aa21f0a06a3f15f22d1 "
        "report_base64=UFIgIzQxNyBzb3VyY2UgSW50ZWdyYXRpb24gUUEKSEVBRDogM2YwZjg0YWQyNmE1ZmU3YjZmZDAxY2U0YmU3MTFhNDE2ZGRiMjMwZgpCYXNlOiA4MjJmMjg0NTc1MzA2YzhhNTQ3NGMzN2FjODc2NzJkZjI0YTcwYWU2CgpTb3VyY2UgdmVyZGljdDogUEFTUy4gTm8gZGVmZWN0IGZvdW5kIGluIHRoZSBwcm9kdWN0aW9uLWFkbWlzc2lvbiBIT0xEIGVuZm9yY2VtZW50LCByZWNlaXB0IHZlcmlmaWNhdGlvbiwgdGVzdHMsIG9yIGRvY3VtZW50YXRpb24uIFRoaXMgdmVyZGljdCBjb3ZlcnMgc291cmNlIHJldmlldyBvbmx5LiBDSSBydW4gNDQwNjUyOTE2MTMgaXMgc3RpbGwgaW4gcHJvZ3Jlc3MgYW5kIGlzIG5vdCBQQVNTLiBQcm9kdWN0aW9uIHJlY292ZXJ5IHJlbWFpbnMgSE9MRCB1bnRpbCB0aGUgcmVxdWlyZWQgcnVudGltZSBldmlkZW5jZSBpcyBjb2xsZWN0ZWQuIE5vIGxpdmUgZGF0YWJhc2Ugb3IgaG9zdCB3YXMgYWNjZXNzZWQuClJldmlld2VyIG1vZGVsOiBHUFQtNiBMdW5hLCBtZWRpdW0gZWZmb3J0Lg=="
    ),
}
MALFORMED_APPSEC_417 = {
    "id": 6945647897,
    "created_at": "2026-11-17T10:37:50Z",
    "updated_at": "2026-11-17T10:37:50Z",
    "author_association": "OWNER",
    "user": {"login": OWNER},
    "body": (
        "WPCG_RELEASE_REVIEW_ARTIFACT_V3 role=appsec pr=417 "
        "head_sha=3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f "
        "base_sha=822f284575306c8a5474c37ac87672df24a70ae6 "
        "agent=codex.appsec.pr417.3f0f84a "
        "report_sha256=06d186d018c657c1884fb77942388d64fdf0365112f0ec213ccd0d57e92d3ec1 "
        "report_base64=UFIgIzQxNyBBcHBTZWMgZXhhY3QtaGVhZCByZXZpZXcKSEVBRDogM2YwZjg0YWQyNmE1ZmU3YjZmZDAxY2U0YmU3MTFhNDE2ZGRiMjMwZgpCYXNlOiA4MjJmMjg0NTc1MzA2YzhhNTQ3NGMzN2FjODc2NzJkZjI0YTcwYWU2CgpWZXJkaWN0OiBQQVNTIGZvciB0aGUgcHJvZHVjdGlvbi1yZWFkaW5lc3Mgc2VwYXJhdGlvbjsgcHJvZHVjdGlvbiByZWNvdmVyeSByZW1haW5zIEhPTEQuIFRoZSBtaWdyYXRpb24gcmVjZWlwdCBtdXN0IHJldGFpbiBwcm9kdWN0aW9uX2FkbWlzc2lvbi5zdGF0ZT1IT0xEIGFuZCB0aGUgZXhhY3QgdGhyZWUgcmVxdWlyZWQgcnVudGltZSBhdHRlc3RhdGlvbnMuIFRoZSB2ZXJpZmllciByZWplY3RzIGFueSByZWNlaXB0IHRoYXQgY2hhbmdlcyB0aGUgc3RhdGUgdG8gUEFTUzsgdGhlIENMSSBsYWJlbHMgYWNjZXB0ZWQgcmVjZWlwdHMgVkFMSURfQ0xPTkVfUkVIRUFSU0FMX09OTFkgYW5kIHJlcG9ydHMgcHJvZHVjdGlvbiByZWNvdmVyeSBIT0xELiBObyBwcm9tb3Rpb24gcGF0aCB3YXMgZm91bmQuCgpUaGUgcmVtYWluaW5nIHJ1bnRpbWUgYXR0ZXN0YXRpb25zIGFyZTogKDEpIG15c3FsZHVtcCBlbmRwb2ludC10by1pbnN0YW5jZSBpZGVudGl0eTsgKDIpIGlkZW50aXR5IHVzaW5nIHRoZSBhY3R1YWwgcHJvdGVjdGVkIGFwcGxpY2F0aW9uIGNyZWRlbnRpYWxzOyBhbmQgKDMpIGV2aWRlbmNlIHRoYXQgdGhlIGV4YWN0IHZlcmlmaWVkIGNhbmRpZGF0ZSBtaWdyYXRpb24gYnl0ZXMgd2VyZSBleGVjdXRlZC4gU3ludGhldGljL3NvdXJjZSB0ZXN0cyBkbyBub3Qgc2F0aXNmeSB0aGVzZSBnYXRlcy4gQ0kgcnVuIDQ0MDY1MjkxNjEzIGlzIHN0aWxsIGluIHByb2dyZXNzIGFuZCBpcyBub3QgUEFTUy4gTm8gaG9zdCBvciBkYXRhYmFzZSB3YXMgYWNjZXNzZWQuClJldmlld2VyIG1vZGVsOiBHUFQtNi4xLCBtZWRpdW0gZWZmb3J0Lg=="
    ),
}
CORRECTION_QA_417 = {
    "id": 6948174348,
    "created_at": "2026-11-17T11:00:00Z",
    "updated_at": "2026-11-17T11:00:00Z",
    "author_association": "OWNER",
    "user": {"login": OWNER},
    "body": (
        "WPCG_RELEASE_ARTIFACT_CORRECTION_V3\n"
        "target_comment_id=6945646697\n"
        "target_body_sha256=f21e60bcc35288d3b2501a5afb99f19f996e82e1bac22bfa7c6e264834af8b27\n"
        "target_pr=417\n"
        "target_head_sha=3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f\n"
        "target_base_sha=822f284575306c8a5474c37ac87672df24a70ae6\n"
        "target_role=integration_qa\n"
        "disposition=INVALIDATED\n"
        "reason_code=owner_authorized_exact_artifact_invalidation\n"
    ),
}
CORRECTION_APPSEC_417 = {
    "id": 6948176008,
    "created_at": "2026-11-17T11:05:00Z",
    "updated_at": "2026-11-17T11:05:00Z",
    "author_association": "OWNER",
    "user": {"login": OWNER},
    "body": (
        "WPCG_RELEASE_ARTIFACT_CORRECTION_V3\n"
        "target_comment_id=6945647897\n"
        "target_body_sha256=4c6d2f0c7490fdcf26b2cdfe9e66c812b1b4af490fcb8bddd30849d71addeeaa\n"
        "target_pr=417\n"
        "target_head_sha=3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f\n"
        "target_base_sha=822f284575306c8a5474c37ac87672df24a70ae6\n"
        "target_role=appsec\n"
        "disposition=INVALIDATED\n"
        "reason_code=owner_authorized_exact_artifact_invalidation\n"
    ),
}


def historical_artifacts():
    return json.loads((Path(__file__).parent / "fixtures" /
                       "v3-artifact-ledger-historical.json").read_text(encoding="utf-8"))


def artifact_correction(target, *, comment_id=None, body=None, login=OWNER,
                        association="OWNER", created_at="2026-11-16T10:00:00Z"):
    fields = dict(line.split("=", 1) for line in target["body"].splitlines()[1:])
    rendered = render_artifact_correction(
        target_comment_id=target["id"],
        target_body_sha256=hashlib.sha256(target["body"].encode("utf-8")).hexdigest(),
        target_pr=int(fields["pr"]), target_head_sha=fields["head_sha"],
        target_base_sha=fields["base_sha"], target_role=fields["role"],
    )
    return {
        "id": comment_id if comment_id is not None else target["id"] + 1_000_000,
        "created_at": created_at, "updated_at": created_at,
        "author_association": association, "user": {"login": login},
        "body": body if body is not None else rendered,
    }


class ReleaseAdmissionV3Tests(unittest.TestCase):
    def test_exact_candidate_pr_base_reviews_artifacts_and_ci_pass(self):
        result = verify_release_admission(**fixture())
        self.assertEqual(result["result"], "PASS")
        self.assertEqual(result["candidate_sha"], CANDIDATE)
        self.assertEqual(result["protected_primary_sha"], PRIMARY)
        self.assertEqual(result["pr_head_sha"], HEAD)
        self.assertEqual(result["pr_base_sha"], BASE)
        self.assertEqual(result["ci"], {"pr_head": "PASS", "candidate_merge_sha": "PASS",
                                        "protected_primary_sha": "PASS"})
        self.assertEqual(result["integration_qa"]["agent"], "qa-agent-independent")

    def test_immutable_candidate_remains_valid_after_documentation_merge(self):
        result = verify_release_admission(**fixture())
        self.assertEqual(result["candidate_sha"], CANDIDATE)
        self.assertEqual(result["protected_primary_sha"], PRIMARY)

    def test_identical_protected_primary_is_valid_for_candidate(self):
        comparison = primary_comparison(candidate=CANDIDATE, primary=CANDIDATE,
                                        status="identical", ahead_by=0)
        args = fixture()
        args["primary_sha"] = CANDIDATE
        args["primary_comparison"] = comparison
        args["primary_ci_run"] = args["candidate_ci_run"]
        args["primary_ci_jobs"] = args["candidate_ci_jobs"]
        result = verify_release_admission(**args)
        self.assertEqual(result["result"], "PASS")

    def test_candidate_not_in_primary_history_is_refused(self):
        self.assertEqual(refusal_reason(primary_comparison=primary_comparison(
            status="diverged", behind_by=2, merge_base="e" * 40)),
            "candidate_not_on_protected_primary")

    def test_current_protected_primary_requires_its_own_exact_push_ci(self):
        args = fixture()
        args["primary_ci_run"] = {**args["primary_ci_run"], "head_sha": CANDIDATE}
        self.assertEqual(refusal_reason(primary_ci_run=args["primary_ci_run"]),
                         "ci_run_invalid")

    def test_jobs_from_a_different_attempt_cannot_pair_with_green_run(self):
        args = fixture()
        args["candidate_ci_jobs"] = [
            {**job, "run_attempt": 2} for job in args["candidate_ci_jobs"]]
        self.assertEqual(refusal_reason(candidate_ci_jobs=args["candidate_ci_jobs"]),
                         "ci_job_identity_mismatch")

    def test_failed_nonrequired_job_cannot_be_hidden_by_required_successes(self):
        args = fixture()
        args["candidate_ci_jobs"].append({
            "id": 999, "run_id": 2, "run_attempt": 1, "head_sha": CANDIDATE,
            "name": "optional-security-scan", "status": "completed", "conclusion": "failure"})
        self.assertEqual(refusal_reason(candidate_ci_jobs=args["candidate_ci_jobs"]),
                         "ci_job_failed")

    def test_pr_ci_must_bind_exact_base_sha(self):
        args = fixture()
        args["pr_ci_run"] = {**args["pr_ci_run"], "pull_requests": [{
            "number": 383,
            "base": {"ref": "claude/ecstatic-wozniak-oemyuf", "sha": "e" * 40}}]}
        self.assertEqual(refusal_reason(pr_ci_run=args["pr_ci_run"]), "ci_pr_binding_invalid")

    def test_malformed_primary_comparison_is_refused(self):
        self.assertEqual(refusal_reason(primary_comparison={}),
                         "candidate_not_on_protected_primary")

    def test_invalid_candidate_sha_is_refused(self):
        self.assertEqual(refusal_reason(candidate_sha="not-a-sha"),
                         "candidate_not_on_protected_primary")

    def test_pr_head_mismatch_is_refused(self):
        self.assertEqual(refusal_reason(expected_pr_head_sha="d" * 40), "merged_pr_target_invalid")

    def test_merge_base_mismatch_is_refused(self):
        args = fixture()
        args["expected_base_sha"] = "d" * 40
        args["pr"] = {**args["pr"], "base": {"ref": "claude/ecstatic-wozniak-oemyuf",
                                                "sha": "d" * 40}}
        self.assertEqual(refusal_reason(**args), "merged_pr_base_mismatch")

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

    def test_report_artifacts_must_bind_exact_reviewed_base(self):
        comments = fixture()["ledger_comments"]
        comments[3] = artifact("appsec", "appsec-agent-independent", REPORT_APPSEC, 4,
                               base="e" * 40)
        self.assertEqual(refusal_reason(ledger_comments=comments), "review_artifact_missing")

    def test_non_utf8_report_bytes_are_refused_even_with_matching_hash(self):
        invalid = b"report\xff"
        comments = fixture()["ledger_comments"]
        comments[1] = ledger_record("appsec", "appsec-agent-independent", invalid, 2)
        comments[3] = artifact("appsec", "appsec-agent-independent", invalid, 4)
        self.assertEqual(refusal_reason(ledger_comments=comments),
                         "review_artifact_encoding_invalid")

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

    def test_all_six_exact_historical_artifacts_are_invalidated_without_becoming_evidence(self):
        historical = historical_artifacts()
        self.assertEqual(len(historical), 6)
        args = fixture()
        comments = args["ledger_comments"] + historical
        comments.extend(artifact_correction(item, comment_id=9_900_000_000 + index)
                        for index, item in enumerate(historical))
        args["ledger_comments"] = comments
        self.assertEqual(verify_release_admission(**args)["result"], "PASS")
        verified = _verified_report_artifacts(comments, pr_number=383, head_sha=HEAD,
                                              base_sha=BASE, owner=OWNER)
        self.assertEqual(set(verified), {"integration_qa", "appsec"})
        self.assertEqual(verified["integration_qa"]["agent"], "qa-agent-independent")
        self.assertEqual(verified["appsec"]["agent"], "appsec-agent-independent")

    def test_each_allowlisted_original_body_matches_its_pinned_digest_and_schema_failure(self):
        historical = historical_artifacts()
        self.assertEqual({item["id"] for item in historical},
                         {6901547471, 6901849333, 6901985822,
                          6901986071, 6902000274, 6902000507})
        for item in historical:
            with self.subTest(comment_id=item["id"]):
                self.assertEqual(item["user"]["login"], OWNER)
                self.assertEqual(item["author_association"], "OWNER")
                self.assertIsNone(re.fullmatch(
                    r"WPCG_RELEASE_REVIEW_ARTIFACT_V3\n"
                    r"role=(integration_qa|appsec)\npr=[1-9][0-9]{0,8}\n"
                    r"head_sha=[0-9a-f]{40}\nbase_sha=[0-9a-f]{40}\n"
                    r"agent=[a-z0-9][a-z0-9._-]{2,63}\n"
                    r"report_sha256=[0-9a-f]{64}\n"
                    r"report_base64=[A-Za-z0-9+/]+={0,2}\n?\Z", item["body"]))
                correction = artifact_correction(item, comment_id=9_900_000_000 + item["id"])
                comments = [item, correction]
                self.assertEqual(len(_verified_report_artifacts(
                    comments + fixture()["ledger_comments"], pr_number=383,
                    head_sha=HEAD, base_sha=BASE, owner=OWNER)), 2)

    def test_malformed_artifact_without_its_exact_correction_is_refused(self):
        comments = fixture()["ledger_comments"] + historical_artifacts()[:1]
        with self.assertRaisesRegex(AdmissionRefused, "review_artifact_invalid"):
            _verified_report_artifacts(comments, pr_number=383, head_sha=HEAD,
                                       base_sha=BASE, owner=OWNER)

    def test_artifact_correction_rejects_modified_body(self):
        target = historical_artifacts()[0]
        changed = dict(target, body=target["body"] + "x")
        comments = [changed, artifact_correction(target)] + fixture()["ledger_comments"]
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_target_invalid"):
            _verified_report_artifacts(comments, pr_number=383, head_sha=HEAD,
                                       base_sha=BASE, owner=OWNER)

    def test_artifact_correction_rejects_wrong_author_and_wrong_target_id(self):
        target = historical_artifacts()[0]
        comments = [target, artifact_correction(target, login="not-owner")]
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_invalid"):
            _verified_report_artifacts(comments + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)
        wrong_id = artifact_correction(target, body=artifact_correction(target)["body"].replace(
            f"target_comment_id={target['id']}\n", f"target_comment_id={target['id'] + 1}\n"))
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_target_invalid"):
            _verified_report_artifacts([target, wrong_id] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

    def test_artifact_correction_rejects_wrong_pr_head_base_and_role(self):
        target = historical_artifacts()[0]
        good = artifact_correction(target)["body"]
        variants = (
            ("pr", f"target_pr={int(good.split('target_pr=')[1].splitlines()[0]) + 1}\n"),
            ("head", f"target_head_sha={'f' * 40}\n"),
            ("base", f"target_base_sha={'e' * 40}\n"),
            ("role", "target_role=appsec\n"),
        )
        for label, replacement in variants:
            with self.subTest(field=label):
                lines = good.splitlines(keepends=True)
                index = {"pr": 3, "head": 4, "base": 5, "role": 6}[label]
                prefix = lines[index].split("=", 1)[0] + "="
                changed = "\n".join(line for line in good.splitlines() if not line.startswith(prefix))
                insertion = {"pr": 3, "head": 4, "base": 5, "role": 6}[label]
                parts = changed.splitlines()
                parts.insert(insertion, replacement.rstrip("\n"))
                correction = artifact_correction(target, body="\n".join(parts) + "\n")
                with self.assertRaisesRegex(AdmissionRefused,
                                            "artifact_correction_(invalid|subject_mismatch)"):
                    _verified_report_artifacts([target, correction] + fixture()["ledger_comments"],
                                               pr_number=383, head_sha=HEAD, base_sha=BASE,
                                               owner=OWNER)

    def test_artifact_correction_rejects_duplicate_and_stale_corrections(self):
        target = historical_artifacts()[0]
        first = artifact_correction(target, comment_id=9_900_000_001)
        duplicate = artifact_correction(target, comment_id=9_900_000_002,
                                        created_at="2026-11-16T10:01:00Z")
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_ambiguous"):
            _verified_report_artifacts([target, first, duplicate] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)
        stale = artifact_correction(target, created_at="2026-11-15T20:00:00Z")
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_not_append_only"):
            _verified_report_artifacts([target, stale] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

    def test_corrected_historical_artifacts_do_not_replace_current_fresh_artifacts(self):
        comments = historical_artifacts()
        comments.extend(artifact_correction(item, comment_id=9_900_000_100 + index)
                        for index, item in enumerate(historical_artifacts()))
        with self.assertRaisesRegex(AdmissionRefused, "review_artifact_missing"):
            _verified_report_artifacts(comments, pr_number=409,
                                       head_sha="6ce39d23473bc6b55930429905678fee6b4dbbe8",
                                       base_sha="ff9f86cea3a591415c64714acd2db297c83e0702",
                                       owner=OWNER)

    def test_owner_authorized_exact_artifact_invalidation_records_pass(self):
        comments = [MALFORMED_QA_417, CORRECTION_QA_417,
                    MALFORMED_APPSEC_417, CORRECTION_APPSEC_417] + fixture()["ledger_comments"]
        args = fixture()
        args["ledger_comments"] = comments
        result = verify_release_admission(**args)
        self.assertEqual(result["result"], "PASS")

    def test_owner_authorized_record_corrections_and_infra_review_pass(self):
        infra_record = render_record(role="infrastructure", pr=426, head_sha="9" * 40,
                                     agent="hagrid-infra-test", decision="PASS",
                                     evidence_sha256="1" * 64)
        infra_comment = {"id": 6978459031, "created_at": "2026-11-18T12:23:34Z",
                         "author_association": "OWNER", "user": {"login": OWNER},
                         "body": infra_record}
        args = fixture()
        args["ledger_comments"] = [infra_comment] + args["ledger_comments"]
        result = verify_release_admission(**args)
        self.assertEqual(result["result"], "PASS")

    def test_owner_authorized_exact_artifact_invalidation_unpinned_comment_id_refused(self):
        unpinned = dict(CORRECTION_QA_417, id=9_900_000_999)
        comments = [MALFORMED_QA_417, unpinned] + fixture()["ledger_comments"]
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_invalid"):
            _verified_report_artifacts(comments, pr_number=383, head_sha=HEAD,
                                       base_sha=BASE, owner=OWNER)

    def test_owner_authorized_exact_artifact_invalidation_target_mismatch_refused(self):
        wrong_target_id = dict(CORRECTION_QA_417, body=CORRECTION_QA_417["body"].replace(
            "target_comment_id=6945646697", "target_comment_id=6945646698"))
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_target_invalid"):
            _verified_report_artifacts([MALFORMED_QA_417, wrong_target_id] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

        changed_target = dict(MALFORMED_QA_417, body=MALFORMED_QA_417["body"] + " ")
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_target_invalid"):
            _verified_report_artifacts([changed_target, CORRECTION_QA_417] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

    def test_owner_authorized_exact_artifact_invalidation_subject_mismatch_refused(self):
        fields = (
            ("target_pr=417", "target_pr=418"),
            ("target_head_sha=3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f",
             "target_head_sha=3f0f84ad26a5fe7b6fd01ce4be711a416ddb230e"),
            ("target_base_sha=822f284575306c8a5474c37ac87672df24a70ae6",
             "target_base_sha=822f284575306c8a5474c37ac87672df24a70ae5"),
            ("target_role=integration_qa", "target_role=appsec"),
        )
        for original, replaced in fields:
            with self.subTest(replacement=replaced):
                tampered = dict(CORRECTION_QA_417,
                                body=CORRECTION_QA_417["body"].replace(original, replaced))
                with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_subject_mismatch"):
                    _verified_report_artifacts([MALFORMED_QA_417, tampered] + fixture()["ledger_comments"],
                                               pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

    def test_owner_authorized_exact_artifact_invalidation_non_owner_and_not_append_only_refused(self):
        not_owner_author = dict(CORRECTION_QA_417, user={"login": "not-owner"})
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_invalid"):
            _verified_report_artifacts([MALFORMED_QA_417, not_owner_author] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

        not_owner_target = dict(MALFORMED_QA_417, user={"login": "not-owner"})
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_target_invalid"):
            _verified_report_artifacts([not_owner_target, CORRECTION_QA_417] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

        stale_time = dict(CORRECTION_QA_417, created_at="2026-11-17T10:37:40Z")
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_not_append_only"):
            _verified_report_artifacts([MALFORMED_QA_417, stale_time] + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

    def test_owner_authorized_exact_artifact_invalidation_duplicate_refused(self):
        duplicate = dict(CORRECTION_QA_417, id=9_900_000_002, created_at="2026-11-17T11:01:00Z")
        with self.assertRaisesRegex(AdmissionRefused, "artifact_correction_invalid"):
            _verified_report_artifacts([MALFORMED_QA_417, CORRECTION_QA_417, duplicate]
                                       + fixture()["ledger_comments"],
                                       pr_number=383, head_sha=HEAD, base_sha=BASE, owner=OWNER)

    def test_owner_authorized_exact_artifact_invalidation_invalidated_artifacts_do_not_act_as_valid_reviews(self):
        comments = [MALFORMED_QA_417, CORRECTION_QA_417,
                    MALFORMED_APPSEC_417, CORRECTION_APPSEC_417]
        with self.assertRaisesRegex(AdmissionRefused, "review_artifact_missing"):
            _verified_report_artifacts(comments, pr_number=417,
                                       head_sha="3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f",
                                       base_sha="822f284575306c8a5474c37ac87672df24a70ae6",
                                       owner=OWNER)

    def test_pr_ci_stale_or_failed_is_refused(self):
        args = fixture()
        args["pr_ci_run"] = {**args["pr_ci_run"], "head_sha": "d" * 40}
        self.assertEqual(refusal_reason(pr_ci_run=args["pr_ci_run"]), "ci_run_invalid")
        args = fixture()
        args["primary_ci_run"] = {**args["primary_ci_run"], "conclusion": "failure"}
        self.assertEqual(refusal_reason(primary_ci_run=args["primary_ci_run"]), "ci_run_invalid")

    def test_current_ci_matrix_exact_jobs_pass(self):
        expected_required = (
            "Quality (lint, style, dependencies, build, secrets)",
            "Connector tests in WordPress (PHP 8.4, sqlite)",
            "Connector tests in WordPress (PHP 8.4, mysql)",
            "Connector tests in WordPress (PHP 8.4, mariadb)",
            "Server tests (PHP 8.5, mysql)",
            "Server SQLite regression gate (PHP 8.5)",
        )
        self.assertEqual(REQUIRED_CI_JOBS, expected_required)
        self.assertNotIn("Server tests (PHP 8.4, mysql)", REQUIRED_CI_JOBS)
        self.assertNotIn("Server SQLite regression gate (PHP 8.4)", REQUIRED_CI_JOBS)
        args = fixture()
        self.assertEqual(verify_release_admission(**args)["result"], "PASS")

    def test_each_required_ci_job_failure_or_omission_is_refused(self):
        for index, name in enumerate(REQUIRED_CI_JOBS):
            with self.subTest(omitted=name):
                args = fixture()
                args["candidate_ci_jobs"] = [
                    job for job in args["candidate_ci_jobs"] if job["name"] != name
                ]
                self.assertEqual(refusal_reason(candidate_ci_jobs=args["candidate_ci_jobs"]),
                                 "required_ci_check_failed")
            with self.subTest(failed=name):
                args = fixture()
                for job in args["candidate_ci_jobs"]:
                    if job["name"] == name:
                        job["conclusion"] = "failure"
                self.assertEqual(refusal_reason(candidate_ci_jobs=args["candidate_ci_jobs"]),
                                 "required_ci_check_failed")

    def test_retired_php84_server_lane_not_required(self):
        # Additional unrequired jobs (like extra matrix runs or retired lanes) do not block admission when passing
        args = fixture()
        retired_job = {
            "id": 9999, "run_id": 2, "run_attempt": 1,
            "head_sha": CANDIDATE, "name": "Server tests (PHP 8.4, mysql)",
            "status": "completed", "conclusion": "success"
        }
        args["candidate_ci_jobs"].append(retired_job)
        self.assertEqual(verify_release_admission(**args)["result"], "PASS")

    def test_missing_failed_or_duplicate_required_job_is_refused(self):
        args = fixture()
        args["primary_ci_jobs"] = args["primary_ci_jobs"][:-1]
        self.assertEqual(refusal_reason(primary_ci_jobs=args["primary_ci_jobs"]),
                         "required_ci_check_failed")
        args = fixture()
        args["pr_ci_jobs"][0] = {**args["pr_ci_jobs"][0], "conclusion": "cancelled"}
        self.assertEqual(refusal_reason(pr_ci_jobs=args["pr_ci_jobs"]), "required_ci_check_failed")
        args = fixture()
        duplicate_name = dict(args["primary_ci_jobs"][0])
        duplicate_name["id"] += 1000
        args["primary_ci_jobs"].append(duplicate_name)
        self.assertEqual(refusal_reason(primary_ci_jobs=args["primary_ci_jobs"]),
                         "required_ci_check_failed")

    def test_pr_ci_must_bind_exact_pr_and_base(self):
        args = fixture()
        args["pr_ci_run"] = {**args["pr_ci_run"],
                             "pull_requests": [{"number": 382,
                                                "base": {"ref": "claude/ecstatic-wozniak-oemyuf"}}]}
        self.assertEqual(refusal_reason(pr_ci_run=args["pr_ci_run"]), "ci_pr_binding_invalid")
        args = fixture()
        args["pr"] = {**args["pr"], "base": {"ref": "claude/ecstatic-wozniak-oemyuf",
                                                "sha": "e" * 40}}
        self.assertEqual(refusal_reason(pr=args["pr"]), "merged_pr_target_invalid")

    def test_pr_ci_without_merged_pull_requests_envelope_binds_head_repo_and_ref(self):
        args = fixture()
        args["pr_ci_run"] = {**args["pr_ci_run"], "pull_requests": []}
        self.assertEqual(verify_release_admission(**args)["result"], "PASS")
        args = fixture()
        args["pr_ci_run"] = {**args["pr_ci_run"],
                             "head_repository": {"full_name": "attacker/wpcaregrid"}}
        self.assertEqual(refusal_reason(**{"pr_ci_run": args["pr_ci_run"]}),
                         "ci_pr_binding_invalid")

    def test_push_ci_must_be_from_protected_primary_branch(self):
        args = fixture()
        args["candidate_ci_run"] = {**args["candidate_ci_run"],
                                    "head_branch": "feature/release"}
        self.assertEqual(refusal_reason(candidate_ci_run=args["candidate_ci_run"]),
                         "ci_branch_binding_invalid")


class ReadOnlyControllerTests(unittest.TestCase):
    def test_controller_fetches_protected_primary_and_returns_read_only_admission(self):
        data = fixture()

        def request(path):
            if path.startswith("/repos/zsoltacsai/wpcaregrid/branches/"):
                return {"protected": True, "commit": {"sha": PRIMARY}}
            if path.endswith(f"/compare/{CANDIDATE}...{PRIMARY}"):
                return data["primary_comparison"]
            if path.endswith("/pulls/383"):
                return data["pr"]
            if path.endswith(f"/commits/{CANDIDATE}"):
                return data["merge_commit"]
            if path == "/repos/zsoltacsai/wpcaregrid":
                return {"owner": {"login": OWNER}}
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request), \
             patch.object(CONTROLLER, "_workflow_run",
                          side_effect=controller_ci_responses(data)), \
             patch.object(CONTROLLER, "_ledger_comments", return_value=data["ledger_comments"]):
            result = CONTROLLER.verify(CANDIDATE, 383, HEAD, BASE)
        self.assertEqual(result["result"], "PASS")
        self.assertEqual(result["candidate_sha"], CANDIDATE)
        self.assertEqual(result["protected_primary_sha"], PRIMARY)

    def test_controller_rejects_unprotected_primary_before_other_evidence_calls(self):
        with patch.object(CONTROLLER, "_request_json", return_value={
            "protected": False, "commit": {"sha": PRIMARY}}), \
             patch.object(CONTROLLER, "_list") as list_request:
            with self.assertRaises(AdmissionRefused) as caught:
                CONTROLLER.verify(CANDIDATE, 383, HEAD, BASE)
        self.assertEqual(str(caught.exception), "primary_branch_not_protected")
        list_request.assert_not_called()

    def test_controller_refuses_primary_moving_during_evidence_read(self):
        data = fixture()
        branch_reads = iter((PRIMARY, "e" * 40))

        def request(path):
            if "/branches/" in path:
                return {"protected": True, "commit": {"sha": next(branch_reads)}}
            if path.endswith(f"/compare/{CANDIDATE}...{PRIMARY}"):
                return data["primary_comparison"]
            if path.endswith("/pulls/383"):
                return data["pr"]
            if path.endswith(f"/commits/{CANDIDATE}"):
                return data["merge_commit"]
            if path == "/repos/zsoltacsai/wpcaregrid":
                return {"owner": {"login": OWNER}}
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request), \
             patch.object(CONTROLLER, "_workflow_run",
                          side_effect=controller_ci_responses(data)), \
             patch.object(CONTROLLER, "_ledger_comments", return_value=data["ledger_comments"]):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER.verify(CANDIDATE, 383, HEAD, BASE)
        self.assertEqual(str(caught.exception), "primary_changed_during_read")

    def test_controller_refuses_ledger_revocation_during_evidence_read(self):
        data = fixture()

        def request(path):
            if "/branches/" in path:
                return {"protected": True, "commit": {"sha": PRIMARY}}
            if path.endswith(f"/compare/{CANDIDATE}...{PRIMARY}"):
                return data["primary_comparison"]
            if path.endswith("/pulls/383"):
                return data["pr"]
            if path.endswith(f"/commits/{CANDIDATE}"):
                return data["merge_commit"]
            if path == "/repos/zsoltacsai/wpcaregrid":
                return {"owner": {"login": OWNER}}
            raise AssertionError(path)

        revoked = data["ledger_comments"] + [
            ledger_record("appsec", "appsec-agent-independent", REPORT_APPSEC, 10,
                          decision="BLOCKED")]
        with patch.object(CONTROLLER, "_request_json", side_effect=request), \
             patch.object(CONTROLLER, "_workflow_run",
                          side_effect=controller_ci_responses(data)), \
             patch.object(CONTROLLER, "_ledger_comments", side_effect=[data["ledger_comments"], revoked]):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER.verify(CANDIDATE, 383, HEAD, BASE)
        self.assertEqual(str(caught.exception), "ledger_changed_during_read")

    def test_controller_refuses_ci_change_after_admission_evaluation(self):
        data = fixture()

        def request(path):
            if "/branches/" in path:
                return {"protected": True, "commit": {"sha": PRIMARY}}
            if path.endswith(f"/compare/{CANDIDATE}...{PRIMARY}"):
                return data["primary_comparison"]
            if path.endswith("/pulls/383"):
                return data["pr"]
            if path.endswith(f"/commits/{CANDIDATE}"):
                return data["merge_commit"]
            if path == "/repos/zsoltacsai/wpcaregrid":
                return {"owner": {"login": OWNER}}
            raise AssertionError(path)

        responses = controller_ci_responses(data)
        responses[4] = ({**data["candidate_ci_run"], "conclusion": "failure"},
                        data["candidate_ci_jobs"])
        with patch.object(CONTROLLER, "_request_json", side_effect=request), \
             patch.object(CONTROLLER, "_workflow_run", side_effect=responses), \
             patch.object(CONTROLLER, "_ledger_comments", return_value=data["ledger_comments"]):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER.verify(CANDIDATE, 383, HEAD, BASE)
        self.assertEqual(str(caught.exception), "ci_changed_during_evidence_read")

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

    def test_request_json_adds_optional_bearer_token_without_exposing_it(self):
        with patch.dict(CONTROLLER.os.environ, {"WPCG_GITHUB_TOKEN": "ghs_secret_token"}):
            with patch.object(CONTROLLER.urllib.request, "build_opener") as opener:
                response = opener.return_value.open.return_value.__enter__.return_value
                response.status = 200
                response.read.return_value = b'{"ok":true}'
                self.assertEqual(CONTROLLER._request_json("/repos/zsoltacsai/wpcaregrid"),
                                 {"ok": True})
                request = opener.return_value.open.call_args.args[0]
                self.assertEqual(request.get_header("Authorization"), "Bearer ghs_secret_token")
        with patch.dict(CONTROLLER.os.environ, {"WPCG_GITHUB_TOKEN": "bad token"}):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER._request_json("/repos/zsoltacsai/wpcaregrid")
        self.assertEqual(str(caught.exception), "api_token_invalid")

    def test_ledger_pages_use_bounded_payloads_and_match_issue_comment_count(self):
        comments = [{"id": index} for index in range(1, 27)]
        calls = []

        def request(path):
            calls.append(path)
            if path.endswith("/issues/174"):
                return {"comments": len(comments)}
            if "page=1" in path:
                return comments[:25]
            if "page=2" in path:
                return comments[25:]
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request):
            self.assertEqual(CONTROLLER._ledger_comments(), comments)
        self.assertIn("per_page=25&page=1", calls[1])
        with patch.object(CONTROLLER, "_request_json", side_effect=[
                {"comments": 26}, comments[:25], []]):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER._ledger_comments()
        self.assertEqual(str(caught.exception), "api_list_incomplete")

    def test_ledger_snapshot_rejects_non_object_entries(self):
        with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
            CONTROLLER._ledger_snapshot([None])
        self.assertEqual(str(caught.exception), "ledger_snapshot_invalid")

    def test_workflow_run_parses_github_jobs_envelope_without_mocking_workflow_run(self):
        data = fixture()
        jobs = data["pr_ci_jobs"]
        run = {"id": 123, "run_attempt": 1,
               "head_sha": HEAD, "event": "pull_request",
               "path": ".github/workflows/ci.yml", "created_at": "2026-11-14T01:00:00Z",
               "status": "completed", "conclusion": "success",
               "head_branch": "feature/release",
               "head_repository": {"full_name": OWNER + "/wpcaregrid"},
               "pull_requests": []}
        jobs = [{"id": 501 + index, "run_id": 123, "run_attempt": 1,
                 "head_sha": HEAD, "name": name, "status": "completed",
                 "conclusion": "success"} for index, name in enumerate(REQUIRED_CI_JOBS)]

        def request(path):
            if "/actions/runs?" in path:
                return {"total_count": 1, "workflow_runs": [run]}
            if path == "/repos/zsoltacsai/wpcaregrid/actions/runs/123/attempts/1/jobs?per_page=100&page=1":
                return {"total_count": len(jobs), "jobs": jobs}
            if path == "/repos/zsoltacsai/wpcaregrid/actions/runs/123/attempts/1":
                return run
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request):
            selected, actual_jobs = CONTROLLER._workflow_run(
                HEAD, event="pull_request", pr_number=383,
                pr_identity=("feature/release", OWNER + "/wpcaregrid"))
        self.assertEqual(selected, run)
        self.assertEqual(actual_jobs, jobs)

    def test_workflow_run_refuses_attempt_change_during_job_read(self):
        run = {"id": 123, "run_attempt": 1, "head_sha": HEAD,
               "event": "pull_request", "path": ".github/workflows/ci.yml",
               "created_at": "2026-11-14T01:00:00Z", "status": "completed",
               "conclusion": "success", "head_branch": "feature/release",
               "head_repository": {"full_name": OWNER + "/wpcaregrid"},
               "pull_requests": [{"number": 383,
                                   "base": {"ref": "claude/ecstatic-wozniak-oemyuf",
                                            "sha": BASE}}]}
        jobs = [{"id": 501 + index, "run_id": 123, "run_attempt": 1,
                 "head_sha": HEAD, "name": name, "status": "completed",
                 "conclusion": "success"} for index, name in enumerate(REQUIRED_CI_JOBS)]
        changed = {**run, "run_attempt": 2, "status": "in_progress", "conclusion": None}

        def request(path):
            if "/actions/runs?" in path:
                return {"total_count": 1, "workflow_runs": [run]}
            if path.endswith("/attempts/1/jobs?per_page=100&page=1"):
                return {"total_count": len(jobs), "jobs": jobs}
            if path.endswith("/attempts/1"):
                return changed
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER._workflow_run(HEAD, event="pull_request", pr_number=383)
        self.assertEqual(str(caught.exception), "ci_attempt_changed_during_read")

    def test_workflow_run_refuses_newer_attempt_appearing_during_collection(self):
        run = {"id": 123, "run_attempt": 1, "head_sha": HEAD,
               "event": "pull_request", "path": ".github/workflows/ci.yml",
               "created_at": "2026-11-14T01:00:00Z", "status": "completed",
               "conclusion": "success",
               "pull_requests": [{"number": 383,
                                   "base": {"ref": "claude/ecstatic-wozniak-oemyuf",
                                            "sha": BASE}}]}
        jobs = [{"id": 501 + index, "run_id": 123, "run_attempt": 1,
                 "head_sha": HEAD, "name": name, "status": "completed",
                 "conclusion": "success"} for index, name in enumerate(REQUIRED_CI_JOBS)]
        list_reads = 0

        def request(path):
            nonlocal list_reads
            if "/actions/runs?" in path:
                list_reads += 1
                selected = run if list_reads == 1 else {**run, "run_attempt": 2}
                return {"total_count": 1, "workflow_runs": [selected]}
            if path.endswith("/attempts/1/jobs?per_page=100&page=1"):
                return {"total_count": len(jobs), "jobs": jobs}
            if path.endswith("/attempts/1"):
                return run
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=request):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER._workflow_run(HEAD, event="pull_request", pr_number=383)
        self.assertEqual(str(caught.exception), "ci_run_changed_during_read")

    def test_workflow_jobs_paginates_and_rejects_incomplete_envelope(self):
        jobs = [{"id": index, "run_id": 123, "run_attempt": 2,
                 "head_sha": HEAD, "name": f"job-{index}"} for index in range(1, 102)]
        calls = []

        def paged(path):
            calls.append(path)
            if path.endswith("page=1"):
                return {"total_count": 101, "jobs": jobs[:100]}
            if path.endswith("page=2"):
                return {"total_count": 101, "jobs": jobs[100:]}
            raise AssertionError(path)

        with patch.object(CONTROLLER, "_request_json", side_effect=paged):
            self.assertEqual(CONTROLLER._workflow_jobs(123, 2, HEAD), jobs)
        self.assertEqual(len(calls), 2)

        with patch.object(CONTROLLER, "_request_json", return_value={
                "total_count": 2, "jobs": jobs[:1]}):
            with self.assertRaises(CONTROLLER.EvidenceUnavailable) as caught:
                CONTROLLER._workflow_jobs(123, 2, HEAD)
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
