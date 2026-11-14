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
import hashlib
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
CORRECTION_PREFIX = "WPCG_RELEASE_CORRECTION_V3"
_CORRECTION = re.compile(
    r"WPCG_RELEASE_CORRECTION_V3\n"
    r"target_comment_id=([1-9][0-9]{0,19})\n"
    r"target_body_sha256=([0-9a-f]{64})\n"
    r"target_pr=([1-9][0-9]{0,8})\n"
    r"target_head_sha=([0-9a-f]{40})\n"
    r"target_role=(integration_qa|appsec)\n"
    r"disposition=INVALIDATED\n"
    r"reason_code=(legacy_agent_identifier_schema)\n?\Z"
)
_LEGACY_AGENT_RECORD = re.compile(
    r"WPCG_RELEASE_REVIEW_V3\n"
    r"role=(integration_qa|appsec)\n"
    r"pr=([1-9][0-9]{0,8})\n"
    r"head_sha=([0-9a-f]{40})\n"
    r"agent=([^\n]{1,128})\n"
    r"reviewer_kind=(ai_agent)\n"
    r"decision=(PASS|CHANGES_REQUIRED|BLOCKED)\n"
    r"evidence_sha256=([0-9a-f]{64})\n?\Z"
)
_AGENT = re.compile(r"[a-z0-9][a-z0-9._-]{2,63}\Z")
_LEGACY_CORRECTION_ALLOWLIST = {
    6834079554: {
        "body_sha256": "5be3d840822759a7815917851e2f73707890173979074b8bd7b752f5bc82e94e",
        "pr": 377,
        "head_sha": "4196dab735b2f73dd2b207afa960596741eda8c5",
        "role": "integration_qa",
        "agent": "Hermione",
    },
    6834080385: {
        "body_sha256": "1f77060825e45c86fe693ce5290d96dd4e14877f8854fa378006c399353608fb",
        "pr": 377,
        "head_sha": "4196dab735b2f73dd2b207afa960596741eda8c5",
        "role": "appsec",
        "agent": "Mad-Eye Moody",
    },
}


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


def render_correction(*, target_comment_id: int, target_body_sha256: str,
                      target_pr: int, target_head_sha: str, target_role: str) -> str:
    """Render the narrow append-only invalidation record for legacy agent labels."""
    body = (f"{CORRECTION_PREFIX}\ntarget_comment_id={target_comment_id}\n"
            f"target_body_sha256={target_body_sha256}\ntarget_pr={target_pr}\n"
            f"target_head_sha={target_head_sha}\ntarget_role={target_role}\n"
            "disposition=INVALIDATED\nreason_code=legacy_agent_identifier_schema\n")
    if _CORRECTION.fullmatch(body) is None:
        raise ReviewRefused("review_correction_invalid")
    return body


def _owner_comment(item: object, owner: str) -> bool:
    if not isinstance(item, Mapping):
        return False
    user = item.get("user")
    return (isinstance(user, Mapping) and user.get("login") == owner
            and item.get("author_association") == "OWNER"
            and isinstance(item.get("created_at"), str) and bool(item.get("created_at"))
            and type(item.get("id")) is int and item["id"] > 0)


def _correction_target_fields(body: object):
    if not isinstance(body, str):
        return None
    match = _LEGACY_AGENT_RECORD.fullmatch(body)
    if match is None:
        return None
    role, pr, sha, agent, reviewer_kind, decision, digest = match.groups()
    if _AGENT.fullmatch(agent) is not None:
        return None
    return role, int(pr), sha, agent


def _authorized_corrections(items: list[object], *, owner: str) -> dict[int, dict[str, object]]:
    by_id: dict[int, object] = {}
    for item in items:
        if not isinstance(item, Mapping) or type(item.get("id")) is not int:
            continue
        comment_id = item["id"]
        if comment_id in by_id:
            raise ReviewRefused("review_evidence_ambiguous")
        by_id[comment_id] = item
    corrections: dict[int, dict[str, object]] = {}
    for item in items:
        if not isinstance(item, Mapping):
            continue
        body = item.get("body")
        if not isinstance(body, str) or not body.startswith(CORRECTION_PREFIX):
            continue
        user = item.get("user")
        login = user.get("login") if isinstance(user, Mapping) else None
        if login != owner:
            continue
        if not _owner_comment(item, owner):
            raise ReviewRefused("review_correction_invalid")
        match = _CORRECTION.fullmatch(body)
        if match is None:
            raise ReviewRefused("review_correction_invalid")
        raw_id, digest, raw_pr, target_sha, role, disposition = match.groups()
        target_id, target_pr = int(raw_id), int(raw_pr)
        target = by_id.get(target_id)
        allowed = _LEGACY_CORRECTION_ALLOWLIST.get(target_id)
        if (not isinstance(target, Mapping) or not _owner_comment(target, owner)
                or not isinstance(target.get("body"), str)
                or hashlib.sha256(target["body"].encode("utf-8")).hexdigest() != digest):
            raise ReviewRefused("review_correction_target_invalid")
        if allowed is None or digest != allowed["body_sha256"]:
            raise ReviewRefused("review_correction_target_invalid")
        if (item["id"] <= target_id or item["created_at"] <= target["created_at"]):
            raise ReviewRefused("review_correction_not_append_only")
        fields = _correction_target_fields(target["body"])
        if (fields is None or fields != (allowed["role"], allowed["pr"],
                                         allowed["head_sha"], allowed["agent"])):
            raise ReviewRefused("review_correction_target_invalid")
        if (role, target_pr, target_sha) != (allowed["role"], allowed["pr"],
                                              allowed["head_sha"]):
            raise ReviewRefused("review_correction_subject_mismatch")
        if target_id in corrections:
            raise ReviewRefused("review_correction_ambiguous")
        corrections[target_id] = {
            "digest": digest, "pr": target_pr, "head_sha": target_sha, "role": role,
            "created_at": item["created_at"], "id": item["id"],
        }
    return corrections


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

    items = list(comments)
    corrections = _authorized_corrections(items, owner=repository_owner)
    latest: dict[str, dict[str, object]] = {}
    for item in items:
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
            correction = corrections.get(comment_id) if type(comment_id) is int else None
            target_fields = _correction_target_fields(body)
            if (correction is not None and target_fields is not None
                    and correction["digest"] == hashlib.sha256(body.encode("utf-8")).hexdigest()
                    and (correction["pr"], correction["head_sha"], correction["role"])
                    == (target_fields[1], target_fields[2], target_fields[0])):
                # A valid correction is an explicit Owner invalidation, never a
                # replacement PASS. Fresh well-formed evidence is still needed.
                continue
            raise ReviewRefused("review_record_invalid")
        role, record_pr, record_sha, agent, kind, decision, digest = match.groups()
        if int(record_pr) != pr_number or record_sha != head_sha:
            continue  # evidence for another PR or another head is never reused
        record = {"role": role, "pr": pr_number, "head_sha": head_sha, "agent": agent,
                  "reviewer_kind": kind, "decision": decision, "evidence_sha256": digest,
                  "created_at": created, "id": comment_id}
        if role not in latest or _sort_key(record) > _sort_key(latest[role]):
            latest[role] = record

    # Invalidation is itself the latest non-PASS decision for that exact
    # subject/role. It must not expose an older PASS that the malformed record
    # had previously eclipsed. A genuinely fresh later PASS may supersede it.
    for correction in corrections.values():
        if (correction["pr"] != pr_number or correction["head_sha"] != head_sha):
            continue
        invalidated = {
            "role": correction["role"], "pr": correction["pr"],
            "head_sha": correction["head_sha"], "decision": "INVALIDATED",
            "agent": "", "reviewer_kind": REVIEWER_KIND,
            "evidence_sha256": "", "created_at": correction["created_at"],
            "id": correction["id"],
        }
        role = correction["role"]
        if role not in latest or _sort_key(invalidated) > _sort_key(latest[role]):
            latest[role] = invalidated

    for role in ROLES:
        if role not in latest:
            raise ReviewRefused("review_evidence_missing")
    for role in ROLES:
        if latest[role]["decision"] != "PASS":
            raise ReviewRefused("review_not_pass")
    if latest["integration_qa"]["agent"] == latest["appsec"]["agent"]:
        raise ReviewRefused("review_roles_not_separate")
    return {role: latest[role] for role in ROLES}
