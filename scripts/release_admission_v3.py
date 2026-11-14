#!/usr/bin/env python3
"""Fail-closed V3 admission checks for a production release candidate.

This module performs read-only evidence validation. It does not stage, deploy,
or modify an installed release. The versioned production helper remains the
final authorization boundary and must adopt this contract in a separately
reviewed successor before this module can be used for deployment.
"""

from __future__ import annotations

import base64
import hashlib
import re
import sys
from pathlib import Path
from typing import Iterable, Mapping

# ``python -I`` deliberately omits the working directory from sys.path.  When
# this file is run beside the controller, load the policy from this same
# protected source directory; normal package imports remain available to tests.
if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from release_review_policy_v3 import ReviewRefused, verify_agent_release_reviews
else:
    from .release_review_policy_v3 import ReviewRefused, verify_agent_release_reviews

PRIMARY_BRANCH = "claude/ecstatic-wozniak-oemyuf"
REQUIRED_CI_JOBS = (
    "Quality (lint, style, dependencies, build, secrets)",
    "Server tests (PHP 8.4, mysql)",
    "Connector tests in WordPress (PHP 8.4, sqlite)",
    "Connector tests in WordPress (PHP 8.4, mysql)",
    "Connector tests in WordPress (PHP 8.4, mariadb)",
    "Server SQLite regression gate (PHP 8.4)",
    "Server tests (PHP 8.5, mysql)",
)
MAX_REPORT_BYTES = 45 * 1024
_SHA = re.compile(r"[0-9a-f]{40}\Z")
_ARTIFACT_PREFIX = "WPCG_RELEASE_REVIEW_ARTIFACT_V3"
_ARTIFACT = re.compile(
    r"WPCG_RELEASE_REVIEW_ARTIFACT_V3\n"
    r"role=(integration_qa|appsec)\n"
    r"pr=([1-9][0-9]{0,8})\n"
    r"head_sha=([0-9a-f]{40})\n"
    r"base_sha=([0-9a-f]{40})\n"
    r"agent=([a-z0-9][a-z0-9._-]{2,63})\n"
    r"report_sha256=([0-9a-f]{64})\n"
    r"report_base64=([A-Za-z0-9+/]+={0,2})\n?\Z"
)


class AdmissionRefused(ValueError):
    """Stable, non-sensitive reason a release candidate was refused."""


def _require(condition: bool, reason: str) -> None:
    if not condition:
        raise AdmissionRefused(reason)


def _sha(value: object) -> bool:
    return isinstance(value, str) and _SHA.fullmatch(value) is not None


def _owner_comment(comment: object, owner: str) -> bool:
    if not isinstance(comment, Mapping):
        return False
    user = comment.get("user")
    return (isinstance(user, Mapping) and user.get("login") == owner
            and comment.get("author_association") == "OWNER"
            and isinstance(comment.get("created_at"), str)
            and type(comment.get("id")) is int and comment["id"] > 0)


def _verified_report_artifacts(comments: Iterable[object], *, pr_number: int,
                                head_sha: str, base_sha: str,
                                owner: str) -> dict[str, dict[str, object]]:
    latest: dict[str, dict[str, object]] = {}
    for comment in comments:
        if not isinstance(comment, Mapping):
            continue
        body = comment.get("body")
        if not isinstance(body, str) or not body.startswith(_ARTIFACT_PREFIX):
            continue
        # Non-owner comments are never evidence and must not be able to veto
        # an otherwise valid release by posting artifact-looking text.
        if not _owner_comment(comment, owner):
            continue
        match = _ARTIFACT.fullmatch(body)
        _require(match is not None, "review_artifact_invalid")
        role, record_pr, record_sha, record_base, agent, digest, encoded = match.groups()
        if int(record_pr) != pr_number or record_sha != head_sha or record_base != base_sha:
            continue
        try:
            raw = base64.b64decode(encoded, validate=True)
        except (ValueError, base64.binascii.Error):
            raise AdmissionRefused("review_artifact_invalid") from None
        _require(0 < len(raw) <= MAX_REPORT_BYTES, "review_artifact_size_invalid")
        _require(hashlib.sha256(raw).hexdigest() == digest, "review_artifact_hash_mismatch")
        try:
            report_text = raw.decode("utf-8")
        except UnicodeDecodeError:
            raise AdmissionRefused("review_artifact_encoding_invalid") from None
        _require("\x00" not in report_text, "review_artifact_encoding_invalid")
        timestamp = comment["created_at"]
        comment_id = comment["id"]
        candidate = {"role": role, "pr": pr_number, "head_sha": head_sha,
                     "base_sha": base_sha,
                     "agent": agent, "report_sha256": digest, "report_bytes": raw,
                     "created_at": timestamp, "id": comment_id}
        previous = latest.get(role)
        if previous is None or (timestamp, comment_id) > (previous["created_at"], previous["id"]):
            latest[role] = candidate
    _require(set(latest) == {"integration_qa", "appsec"}, "review_artifact_missing")
    return latest


def _verify_ci_run(run: object, jobs: object, *, commit_sha: str, event: str,
                   pr_number: int | None = None,
                   pr_base_sha: str | None = None,
                   pr_head_ref: str | None = None,
                   pr_head_repository: str | None = None,
                   expected_branch: str | None = None) -> None:
    _require(isinstance(run, Mapping) and run.get("head_sha") == commit_sha
             and run.get("event") == event and run.get("path") == ".github/workflows/ci.yml"
             and run.get("status") == "completed" and run.get("conclusion") == "success",
             "ci_run_invalid")
    run_id = run.get("id")
    attempt = run.get("run_attempt")
    _require(type(run_id) is int and run_id > 0
             and type(attempt) is int and attempt > 0, "ci_run_identity_invalid")
    if pr_number is not None:
        pull_requests = run.get("pull_requests")
        _require(isinstance(pull_requests, list), "ci_pr_binding_invalid")
        if pull_requests:
            _require(any(
                isinstance(item, Mapping) and item.get("number") == pr_number
                and isinstance(item.get("base"), Mapping)
                and item["base"].get("ref") == PRIMARY_BRANCH
                and (pr_base_sha is None or item["base"].get("sha") == pr_base_sha)
                for item in pull_requests), "ci_pr_binding_invalid")
        head_repository = run.get("head_repository")
        _require(isinstance(pr_head_ref, str) and pr_head_ref
                 and isinstance(pr_head_repository, str) and pr_head_repository
                 and run.get("head_branch") == pr_head_ref
                 and isinstance(head_repository, Mapping)
                 and head_repository.get("full_name") == pr_head_repository,
                 "ci_pr_binding_invalid")
    elif expected_branch is not None:
        _require(run.get("head_branch") == expected_branch, "ci_branch_binding_invalid")
    _require(isinstance(jobs, list), "ci_jobs_invalid")
    grouped: dict[str, list[object]] = {}
    job_ids = set()
    for job in jobs:
        _require(isinstance(job, Mapping) and isinstance(job.get("name"), str)
                 and job.get("run_id") == run_id and job.get("run_attempt") == attempt
                 and job.get("head_sha") == commit_sha, "ci_job_identity_mismatch")
        job_id = job.get("id")
        _require(type(job_id) is int and job_id > 0 and job_id not in job_ids,
                 "ci_jobs_invalid")
        job_ids.add(job_id)
        grouped.setdefault(job["name"], []).append(job)
    for name in REQUIRED_CI_JOBS:
        instances = grouped.get(name, [])
        _require(len(instances) == 1 and instances[0].get("status") == "completed"
                 and instances[0].get("conclusion") == "success", "required_ci_check_failed")
    required_names = set(REQUIRED_CI_JOBS)
    for job in jobs:
        if job["name"] not in required_names:
            _require(job.get("status") == "completed"
                     and job.get("conclusion") in {"success", "skipped", "neutral"},
                     "ci_job_failed")


def _candidate_is_on_protected_primary(candidate_sha: str, primary_sha: str,
                                      comparison: object) -> bool:
    """Require GitHub's base=candidate/head=primary comparison to prove ancestry."""
    if not isinstance(comparison, Mapping):
        return False
    base = comparison.get("base_commit")
    merge_base = comparison.get("merge_base_commit")
    ahead_by = comparison.get("ahead_by")
    behind_by = comparison.get("behind_by")
    status = comparison.get("status")
    return (
        isinstance(base, Mapping) and base.get("sha") == candidate_sha
        and isinstance(merge_base, Mapping) and merge_base.get("sha") == candidate_sha
        and type(ahead_by) is int and ahead_by >= 0
        and type(behind_by) is int and behind_by == 0
        and status == ("identical" if candidate_sha == primary_sha else "ahead")
        and ((candidate_sha == primary_sha and ahead_by == 0)
             or (candidate_sha != primary_sha and ahead_by > 0))
    )


def verify_release_admission(*, candidate_sha: str, primary_sha: str,
                             primary_comparison: object, pr_number: int,
                             expected_pr_head_sha: str, expected_base_sha: str,
                             pr: object, merge_commit: object, pr_ci_run: object,
                             pr_ci_jobs: object, candidate_ci_run: object,
                             candidate_ci_jobs: object, primary_ci_run: object,
                             primary_ci_jobs: object, ledger_comments: Iterable[object],
                             repository_owner: str) -> dict[str, object]:
    """Validate exact merged PR, V3 report bytes, and required CI snapshots.

    All inputs are snapshots fetched by the caller. Any absent, malformed,
    stale, or conflicting evidence raises ``AdmissionRefused``.
    """
    _require(_sha(candidate_sha) and _sha(primary_sha)
             and _candidate_is_on_protected_primary(candidate_sha, primary_sha,
                                                    primary_comparison),
             "candidate_not_on_protected_primary")
    _require(type(pr_number) is int and pr_number > 0 and repository_owner
             and _sha(expected_pr_head_sha) and _sha(expected_base_sha),
             "release_subject_invalid")
    _require(isinstance(pr, Mapping) and pr.get("number") == pr_number
             and pr.get("state") == "closed" and pr.get("merged") is True
             and pr.get("merge_commit_sha") == candidate_sha,
             "merged_pr_identity_invalid")
    base = pr.get("base")
    head = pr.get("head")
    _require(isinstance(base, Mapping) and base.get("ref") == PRIMARY_BRANCH
             and base.get("sha") == expected_base_sha
             and isinstance(head, Mapping) and head.get("sha") == expected_pr_head_sha,
             "merged_pr_target_invalid")
    _require(isinstance(merge_commit, Mapping) and merge_commit.get("sha") == candidate_sha,
             "merge_commit_identity_invalid")
    parents = merge_commit.get("parents")
    _require(isinstance(parents, list) and parents and all(
        isinstance(parent, Mapping) and _sha(parent.get("sha")) for parent in parents),
        "merge_commit_parents_invalid")
    _require(parents[0]["sha"] == expected_base_sha, "merged_pr_base_mismatch")
    # Merge commits bind the reviewed PR head as a parent. Squash/rebase merges
    # bind the exact PR head through GitHub's merged PR metadata instead.
    _require(len(parents) == 1 or any(parent["sha"] == head["sha"] for parent in parents[1:]),
             "merged_pr_head_mismatch")

    _verify_ci_run(pr_ci_run, pr_ci_jobs, commit_sha=head["sha"],
                   event="pull_request", pr_number=pr_number,
                   pr_base_sha=expected_base_sha,
                   pr_head_ref=(head.get("ref") if isinstance(head, Mapping) else None),
                   pr_head_repository=((head.get("repo") or {}).get("full_name")
                                       if isinstance(head, Mapping)
                                       and isinstance(head.get("repo"), Mapping) else None))
    _verify_ci_run(candidate_ci_run, candidate_ci_jobs,
                   commit_sha=candidate_sha, event="push",
                   expected_branch=PRIMARY_BRANCH)
    _verify_ci_run(primary_ci_run, primary_ci_jobs, commit_sha=primary_sha, event="push",
                   expected_branch=PRIMARY_BRANCH)

    try:
        records = verify_agent_release_reviews(
            ledger_comments, pr_number=pr_number, head_sha=head["sha"],
            repository_owner=repository_owner)
    except ReviewRefused as error:
        raise AdmissionRefused(str(error)) from None
    artifacts = _verified_report_artifacts(
        ledger_comments, pr_number=pr_number, head_sha=head["sha"],
        base_sha=expected_base_sha, owner=repository_owner)
    for role, record in records.items():
        artifact = artifacts.get(role)
        _require(isinstance(artifact, Mapping) and artifact["agent"] == record["agent"]
                 and artifact["report_sha256"] == record["evidence_sha256"],
                 "review_artifact_record_mismatch")
    return {
        "result": "PASS",
        "candidate_sha": candidate_sha,
        "protected_primary_sha": primary_sha,
        "pr_number": pr_number,
        "pr_head_sha": expected_pr_head_sha,
        "pr_base_sha": expected_base_sha,
        "integration_qa": records["integration_qa"],
        "appsec": records["appsec"],
        "ci": {"pr_head": "PASS", "candidate_merge_sha": "PASS",
               "protected_primary_sha": "PASS"},
    }
