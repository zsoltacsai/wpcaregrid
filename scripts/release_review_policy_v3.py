#!/usr/bin/env python3
"""Release-review contract V3: solo-Owner, AI-agent QA/AppSec evidence.

Owner decision 2026-11-09 (see docs/RELEASE_REVIEW_POLICY.md): a source release
no longer needs a second GitHub user or GitHub ``APPROVED`` reviews. It needs
two separately recorded AI-agent verdicts, Integration & QA and AppSec, each
bound to the exact PR head commit.

Evidence is an issue-comment record posted through the repository Owner's
account on the release ledger. The record states honestly that the reviewer is
an AI agent. This module is pure policy: it takes already-fetched GitHub
comment objects and returns or refuses. It never reads GitHub reviews, never
contacts the network, and does not replace CI, hash, target, path, privilege or
runtime-isolation checks, which remain with the caller. A PASS grants no host
permission: privileged host operations still need separate Owner approval.
"""

from __future__ import annotations

import re
from typing import Iterable, Mapping

RECORD_PREFIX = "WPCG_RELEASE_REVIEW_V3"
ROLES = ("integration_qa", "appsec")
DECISIONS = ("PASS", "CHANGES_REQUIRED", "BLOCKED")
REVIEWER_KIND = "ai_agent"

_RECORD = re.compile(
    r"WPCG_RELEASE_REVIEW_V3\n"
    r"role=(integration_qa|appsec)\n"
    r"pr=([1-9][0-9]{0,8})\n"
    r"head_sha=([0-9a-f]{40})\n"
    r"agent=([a-z0-9][a-z0-9._-]{2,63})\n"
    r"reviewer_kind=(ai_agent)\n"
    r"decision=(PASS|CHANGES_REQUIRED|BLOCKED)\n"
    r"evidence_sha256=([0-9a-f]{64})\n?\Z"
)


class ReviewRefused(ValueError):
    """Stable, non-sensitive reason the release review evidence was rejected."""


def render_record(*, role: str, pr: int, head_sha: str, agent: str, decision: str,
                  evidence_sha256: str) -> str:
    """Build a record body; rejects anything the verifier would reject."""
    body = (f"{RECORD_PREFIX}\nrole={role}\npr={pr}\nhead_sha={head_sha}\nagent={agent}\n"
            f"reviewer_kind={REVIEWER_KIND}\ndecision={decision}\nevidence_sha256={evidence_sha256}\n")
    if type(pr) is not int or _RECORD.fullmatch(body) is None:
        raise ReviewRefused("review_record_invalid")
    return body


def _sort_key(item: Mapping[str, object]):
    return (item["created_at"], item["id"])


def verify_agent_release_reviews(
    comments: Iterable[object],
    *,
    pr_number: int,
    head_sha: str,
    repository_owner: str,
) -> dict[str, dict[str, object]]:
    """Require a PASS record per role for exactly this PR and head commit.

    Returns the accepted record per role. Raises ``ReviewRefused`` when evidence
    is missing, stale (different head or PR), malformed, not a PASS in its
    latest form, or when both roles share one agent identity.
    """
    if (type(pr_number) is not int or pr_number <= 0 or not isinstance(head_sha, str)
            or re.fullmatch(r"[0-9a-f]{40}", head_sha) is None
            or not isinstance(repository_owner, str) or not repository_owner):
        raise ReviewRefused("review_subject_invalid")
    if isinstance(comments, (str, bytes, Mapping)) or not isinstance(comments, Iterable):
        raise ReviewRefused("review_evidence_invalid")

    latest: dict[str, dict[str, object]] = {}
    for item in comments:
        if not isinstance(item, Mapping):
            continue
        body = item.get("body")
        if not isinstance(body, str) or not body.startswith(RECORD_PREFIX):
            continue
        user = item.get("user")
        login = user.get("login") if isinstance(user, Mapping) else None
        if login != repository_owner:
            # Records by anyone but the repository owner never count (and cannot
            # be used to disturb the ledger).
            continue
        created, comment_id = item.get("created_at"), item.get("id")
        match = _RECORD.fullmatch(body)
        if (match is None or item.get("author_association") != "OWNER"
                or not isinstance(created, str) or not created
                or type(comment_id) is not int or comment_id <= 0):
            raise ReviewRefused("review_record_invalid")
        role, record_pr, record_sha, agent, kind, decision, digest = match.groups()
        if int(record_pr) != pr_number or record_sha != head_sha:
            continue  # evidence for another PR or another head is never reused
        record = {"role": role, "pr": pr_number, "head_sha": head_sha, "agent": agent,
                  "reviewer_kind": kind, "decision": decision, "evidence_sha256": digest,
                  "created_at": created, "id": comment_id}
        if role not in latest or _sort_key(record) > _sort_key(latest[role]):
            latest[role] = record

    for role in ROLES:
        if role not in latest:
            raise ReviewRefused("review_evidence_missing")
    for role in ROLES:
        if latest[role]["decision"] != "PASS":
            raise ReviewRefused("review_not_pass")
    if latest["integration_qa"]["agent"] == latest["appsec"]["agent"]:
        raise ReviewRefused("review_roles_not_separate")
    return {role: latest[role] for role in ROLES}
