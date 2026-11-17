#!/usr/bin/env python3
"""Exact-scope append-only correction tests for the V3.1 ledger policy."""

from __future__ import annotations

import copy
import hashlib
import json
import unittest
from pathlib import Path

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import release_review_policy_v3_1 as policy

OWNER = "zsoltacsai"
HEAD = "3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f"
FIXTURE = Path(__file__).parent / "fixtures" / "go15-malformed-v3-ledger.json"


def records():
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def owner_comment(comment_id, body, created_at, *, login=OWNER, association="OWNER"):
    return {"id": comment_id, "body": body, "created_at": created_at,
            "author_association": association, "user": {"login": login}}


def correction(target, correction_id, *, target_id=None, digest=None,
               pr=417, head=HEAD, role=None, login=OWNER,
               created_at="2030-01-01T00:00:00Z"):
    actual_id = target_id if target_id is not None else target["id"]
    body = policy.render_correction(
        target_comment_id=actual_id,
        target_body_sha256=digest or hashlib.sha256(target["body"].encode()).hexdigest(),
        target_pr=pr, target_head_sha=head,
        target_role=role or ("integration_qa" if target["id"] == 6945647262 else "appsec"),
        reason_code="owner_authorized_exact_record_invalidation")
    return owner_comment(correction_id, body, created_at, login=login)


class ExactOwnerInvalidationTests(unittest.TestCase):
    def test_fixture_binds_exact_four_original_records(self):
        items = records()
        self.assertEqual([x["id"] for x in items],
                         [6945646697, 6945647262, 6945647897, 6945648524])
        for item in items:
            self.assertEqual(item["user"]["login"], OWNER)
            self.assertEqual(item["author_association"], "OWNER")
            self.assertEqual(hashlib.sha256(item["body"].encode()).hexdigest(),
                             item["body_sha256"])
            self.assertEqual(item["kind"], "artifact" if item["id"] in
                             {6945646697, 6945647897} else "review")

    def test_four_exact_records_are_invalidated_and_never_count_as_pass(self):
        items = records()
        corrections = [correction(x, 7000000000 + x["id"])
                       for x in items if x["kind"] == "review"]
        self.assertEqual(len(corrections), 2)
        invalidated = items + corrections
        for comment in items:
            if comment["kind"] == "review":
                comment["body_sha256"] = hashlib.sha256(comment["body"].encode()).hexdigest()
        # With no fresh reviews, an invalidated prior PASS cannot satisfy the gate.
        with self.assertRaisesRegex(policy.ReviewRefused, "review_not_pass"):
            policy.verify_agent_release_reviews(
                invalidated, pr_number=417, head_sha=HEAD, repository_owner=OWNER)

        fresh = []
        for offset, role in enumerate(("integration_qa", "appsec"), 1):
            agent = f"fresh.{role}.pr417"
            digest = hashlib.sha256(f"fresh {role}".encode()).hexdigest()
            fresh.append(owner_comment(
                8000000000 + offset,
                policy.render_record(role=role, pr=417, head_sha=HEAD, agent=agent,
                                     decision="PASS", evidence_sha256=digest),
                f"2030-01-01T00:00:0{offset}Z"))
        result = policy.verify_agent_release_reviews(
            invalidated + fresh, pr_number=417, head_sha=HEAD, repository_owner=OWNER)
        self.assertEqual({k: v["agent"] for k, v in result.items()},
                         {"integration_qa": "fresh.integration_qa.pr417",
                          "appsec": "fresh.appsec.pr417"})

    def test_wrong_id_hash_subject_author_or_time_cannot_invalidate(self):
        target = next(x for x in records() if x["id"] == 6945647262)
        cases = [
            (correction(target, 9000000001, target_id=target["id"] + 1),
             "review_correction_target_invalid"),
            (correction(target, 9000000002, digest="0" * 64),
             "review_correction_target_invalid"),
            (correction(target, 9000000003, pr=418),
             "review_correction_subject_mismatch"),
            (correction(target, 9000000004, head="f" * 40),
             "review_correction_subject_mismatch"),
            (correction(target, 9000000005, role="appsec"),
             "review_correction_subject_mismatch"),
            (correction(target, 9000000006, created_at="2026-11-16T00:00:00Z"),
             "review_correction_not_append_only"),
        ]
        for item, reason in cases:
            with self.subTest(id=item["id"]), self.assertRaisesRegex(policy.ReviewRefused, reason):
                policy._authorized_corrections([target, item], owner=OWNER)
        non_owner = correction(target, 9000000007, login="not-owner")
        self.assertNotIn(target["id"], policy._authorized_corrections(
            [target, non_owner], owner=OWNER))

    def test_unrelated_malformed_owner_review_remains_fail_closed(self):
        items = records()
        corrections = [correction(x, 9100000000 + x["id"])
                       for x in items if x["kind"] == "review"]
        unrelated = owner_comment(9200000001,
                                  "WPCG_RELEASE_REVIEW_V3\\nmalformed",
                                  "2030-01-01T00:00:00Z")
        with self.assertRaisesRegex(policy.ReviewRefused, "review_record_invalid"):
            policy.verify_agent_release_reviews(
                items + corrections + [unrelated], pr_number=417,
                head_sha=HEAD, repository_owner=OWNER)

    def test_duplicate_exact_correction_refuses(self):
        target = next(x for x in records() if x["id"] == 6945648524)
        first = correction(target, 9300000001)
        second = correction(target, 9300000002, created_at="2030-01-01T00:01:00Z")
        with self.assertRaisesRegex(policy.ReviewRefused, "review_correction_ambiguous"):
            policy.verify_agent_release_reviews(
                [target, first, second], pr_number=417, head_sha=HEAD,
                repository_owner=OWNER)


if __name__ == "__main__":
    unittest.main()
