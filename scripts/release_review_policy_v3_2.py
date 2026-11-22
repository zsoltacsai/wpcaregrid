#!/usr/bin/env python3
"""Release-review contract V3.2: solo-Owner, AI-agent QA/AppSec evidence.

Successor to release_review_policy_v3_1. Preserves fail-closed semantics for
admission while admitting historical and infrastructure reviews in the ledger.
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
    r"role=(integration_qa|appsec|infrastructure)\n"
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
    r"target_role=(integration_qa|appsec|infrastructure)\n"
    r"disposition=INVALIDATED\n"
    r"reason_code=(legacy_agent_identifier_schema|owner_authorized_exact_record_invalidation)\n?\Z"
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
    # Exact malformed review records independently verified from the live
    # Owner-authored #174 ledger on 2026-11-15. These entries authorize only
    # append-only invalidation of these immutable comment bodies; they do not
    # relax the agent identifier grammar or create review evidence.
    6901547246: {
        "body_sha256": "de69e4b8281ed0634ac0502643c0a658688782d1b9d14a7dbfc482c2d1d0a8e7",
        "pr": 408,
        "head_sha": "a3776603e8ea8135811b739dd6194a6ddbd73ac1",
        "role": "integration_qa",
        "agent": "/root/qa_pr407_409",
    },
    6901849074: {
        "body_sha256": "0f768bc6cafd1b3152c563660d7b5aef6cb991fa6f5992238c7d4e983fa296c6",
        "pr": 408,
        "head_sha": "5f0a818c0eac04f618fff3d9977cd378b2cc6dda",
        "role": "integration_qa",
        "agent": "/root/qa_pr407_409",
    },
    6901986280: {
        "body_sha256": "32eb1040dc582e5a06ea881caf0015d04223fba693929847cef72ae19eba1dd7",
        "pr": 409,
        "head_sha": "19c951f94082b03a8f0880039e59410cede60cf0",
        "role": "integration_qa",
        "agent": "/root/qa_pr409_rebase",
    },
    6901986526: {
        "body_sha256": "3562867db51cfbe2b204ef8c89fe5012eb0b976aa0a8ff5cfaf9b535cb69962f",
        "pr": 409,
        "head_sha": "19c951f94082b03a8f0880039e59410cede60cf0",
        "role": "appsec",
        "agent": "/root/appsec_pr409_rebase",
    },
    6902000708: {
        "body_sha256": "b561528aafde1db923192a75fd9bbd0e00fd96852cd56e859e6e9641dfc82a02",
        "pr": 409,
        "head_sha": "19c951f94082b03a8f0880039e59410cede60cf0",
        "role": "integration_qa",
        "agent": "/root/qa_pr409_rebase",
    },
    6902000894: {
        "body_sha256": "aa38f7fa128561ae93686196ba101920325dba3e4409cbe36376a13f0e694a5e",
        "pr": 409,
        "head_sha": "19c951f94082b03a8f0880039e59410cede60cf0",
        "role": "appsec",
        "agent": "/root/appsec_pr409_rebase",
    },
}
# Owner-authorized, exact-scope invalidations for the malformed GO-15 #174
# entries. Targets are bound by immutable GitHub comment id, exact body digest,
# PR, HEAD, role and agent. This does not grant a general malformed-record
# exception. Corrections must still be Owner-authored and appended after the
# original target.
_EXACT_OWNER_INVALIDATION_ALLOWLIST = {
    6945647262: {
        "body_sha256": "5ff342365ea396c1a7dc0e1cdbba919ec6765837c87fd9f63462665180949f9a",
        "pr": 417,
        "head_sha": "3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f",
        "role": "integration_qa",
        "agent": "codex.qa.pr417.3f0f84a",
    },
    6945648524: {
        "body_sha256": "efbf3be019215080a10198906b717f21915aeb245b307067f02d47bd79d539f9",
        "pr": 417,
        "head_sha": "3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f",
        "role": "appsec",
        "agent": "codex.appsec.pr417.3f0f84a",
    },
}
# One malformed Owner correction comment was published during the authorized
# recovery attempt. Pin its exact bytes and its intended original target so it
# can never be mistaken for a correction or broaden the two-record target list.
# The original target remains blocking until one valid correction is appended.
_MALFORMED_CORRECTION_QUARANTINE = {
    6880748416: {
        "body_sha256": "60283180a007514373b50d90bc49400fefbdf91495d28435777640b1293a750d",
        "target_comment_id": 6834079554,
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
                      target_pr: int, target_head_sha: str, target_role: str,
                      reason_code: str = "legacy_agent_identifier_schema") -> str:
    """Render a legacy or exact-allowlisted append-only invalidation record."""
    body = (f"{CORRECTION_PREFIX}\ntarget_comment_id={target_comment_id}\n"
            f"target_body_sha256={target_body_sha256}\ntarget_pr={target_pr}\n"
            f"target_head_sha={target_head_sha}\ntarget_role={target_role}\n"
            f"disposition=INVALIDATED\nreason_code={reason_code}\n")
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


def _body_sha256(body: object) -> str | None:
    """Hash a UTF-8 comment body, treating invalid Unicode as unavailable evidence."""
    if not isinstance(body, str):
        return None
    try:
        return hashlib.sha256(body.encode("utf-8")).hexdigest()
    except UnicodeEncodeError:
        return None


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
            quarantine = _MALFORMED_CORRECTION_QUARANTINE.get(item["id"])
            malformed_body_digest = _body_sha256(body)
            if (quarantine is not None
                    and malformed_body_digest == quarantine["body_sha256"]):
                target = by_id.get(quarantine["target_comment_id"])
                allowed_target = _LEGACY_CORRECTION_ALLOWLIST.get(
                    quarantine["target_comment_id"])
                if (isinstance(target, Mapping) and _owner_comment(target, owner)
                        and allowed_target is not None
                        and isinstance(target.get("body"), str)
                        and _body_sha256(target["body"]) == allowed_target["body_sha256"]
                        and item["id"] > target["id"]
                        and item["created_at"] > target["created_at"]):
                    # This pins only the known malformed submission. It does
                    # not authorize or perform its intended invalidation.
                    continue
            raise ReviewRefused("review_correction_invalid")
        raw_id, digest, raw_pr, target_sha, role, reason_code = match.groups()
        target_id, target_pr = int(raw_id), int(raw_pr)
        target = by_id.get(target_id)
        allowed = _LEGACY_CORRECTION_ALLOWLIST.get(target_id)
        exact_allowed = _EXACT_OWNER_INVALIDATION_ALLOWLIST.get(target_id)
        if (not isinstance(target, Mapping) or not _owner_comment(target, owner)
                or not isinstance(target.get("body"), str)
                or _body_sha256(target["body"]) != digest):
            raise ReviewRefused("review_correction_target_invalid")
        target_allowlist = allowed or exact_allowed
        if target_allowlist is None or digest != target_allowlist["body_sha256"]:
            raise ReviewRefused("review_correction_target_invalid")
        if (item["id"] <= target_id or item["created_at"] <= target["created_at"]):
            raise ReviewRefused("review_correction_not_append_only")
        if exact_allowed is not None:
            if reason_code != "owner_authorized_exact_record_invalidation":
                raise ReviewRefused("review_correction_subject_mismatch")
            # Exact comment-id+body-hash binding is the authorization. Check
            # parsed target fields too so the map cannot drift from its record.
            match_target = _RECORD.fullmatch(target["body"])
            if match_target is None:
                raise ReviewRefused("review_correction_target_invalid")
            target_role, target_pr_raw, target_sha_raw, target_agent, *_ = match_target.groups()
            fields = (target_role, int(target_pr_raw), target_sha_raw, target_agent)
        else:
            if reason_code != "legacy_agent_identifier_schema":
                raise ReviewRefused("review_correction_subject_mismatch")
            fields = _correction_target_fields(target["body"])
        if fields != (target_allowlist["role"], target_allowlist["pr"],
                      target_allowlist["head_sha"], target_allowlist["agent"]):
            raise ReviewRefused("review_correction_target_invalid")
        if (role, target_pr, target_sha) != (target_allowlist["role"], target_allowlist["pr"],
                                              target_allowlist["head_sha"]):
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
                    and correction["digest"] == _body_sha256(body)
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
