#!/usr/bin/env python3
"""Exact-scope append-only artifact corrections for release admission V3.1."""

from __future__ import annotations

import base64
import hashlib
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import release_admission_v3_1 as admission

OWNER = "zsoltacsai"
HEAD = "3f0f84ad26a5fe7b6fd01ce4be711a416ddb230f"
BASE = "822f284575306c8a5474c37ac87672df24a70ae6"
FIXTURE = Path(__file__).parent / "fixtures" / "go15-malformed-v3-ledger.json"


def records():
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def owner_comment(comment_id, body, created_at, *, login=OWNER, association="OWNER"):
    return {"id": comment_id, "body": body, "created_at": created_at,
            "author_association": association, "user": {"login": login}}


def artifact_correction(target, correction_id, *, target_id=None, digest=None,
                        pr=417, head=HEAD, base=BASE, role=None,
                        reason="owner_authorized_exact_artifact_invalidation",
                        login=OWNER, created_at="2030-01-01T00:00:00Z"):
    actual_id = target_id if target_id is not None else target["id"]
    exact_role = role or ("integration_qa" if target["id"] == 6945646697 else "appsec")
    body = admission.render_artifact_correction(
        target_comment_id=actual_id,
        target_body_sha256=digest or hashlib.sha256(target["body"].encode()).hexdigest(),
        target_pr=pr, target_head_sha=head, target_base_sha=base,
        target_role=exact_role, reason_code=reason)
    return owner_comment(correction_id, body, created_at, login=login)


def fresh_artifact(role, comment_id, created_at):
    agent = f"fresh.{role}.pr417"
    report = f"Fresh exact-head {role} source review; no change to the invalidated evidence."
    raw = report.encode()
    body = (f"WPCG_RELEASE_REVIEW_ARTIFACT_V3\nrole={role}\npr=417\n"
            f"head_sha={HEAD}\nbase_sha={BASE}\nagent={agent}\n"
            f"report_sha256={hashlib.sha256(raw).hexdigest()}\n"
            f"report_base64={base64.b64encode(raw).decode()}\n")
    return owner_comment(comment_id, body, created_at)


class ExactOwnerArtifactInvalidationTests(unittest.TestCase):
    def test_fixture_binds_exact_owner_artifact_comments_and_body_hashes(self):
        artifacts = [x for x in records() if x["kind"] == "artifact"]
        self.assertEqual([x["id"] for x in artifacts], [6945646697, 6945647897])
        for item in artifacts:
            self.assertEqual(item["user"]["login"], OWNER)
            self.assertEqual(item["author_association"], "OWNER")
            self.assertEqual(hashlib.sha256(item["body"].encode()).hexdigest(),
                             item["body_sha256"])
            self.assertEqual(item["body"].count(" ") > 0, True)

    def test_exact_artifacts_are_invalidated_and_not_decoded_as_evidence(self):
        artifacts = [x for x in records() if x["kind"] == "artifact"]
        corrections = [artifact_correction(item, 7000000000 + item["id"])
                       for item in artifacts]
        comments = records() + corrections
        with self.assertRaisesRegex(admission.AdmissionRefused, "review_artifact_missing"):
            admission._verified_report_artifacts(
                comments, pr_number=417, head_sha=HEAD, base_sha=BASE, owner=OWNER)
        fresh = [fresh_artifact("integration_qa", 8000000001, "2030-01-01T00:00:01Z"),
                 fresh_artifact("appsec", 8000000002, "2030-01-01T00:00:02Z")]
        verified = admission._verified_report_artifacts(
            comments + fresh, pr_number=417, head_sha=HEAD, base_sha=BASE, owner=OWNER)
        self.assertEqual({k: v["agent"] for k, v in verified.items()},
                         {"integration_qa": "fresh.integration_qa.pr417",
                          "appsec": "fresh.appsec.pr417"})

    def test_wrong_id_hash_subject_author_and_time_refuse(self):
        target = next(x for x in records() if x["id"] == 6945646697)
        bad = [
            artifact_correction(target, 9000000001, target_id=target["id"] + 1),
            artifact_correction(target, 9000000002, digest="0" * 64),
            artifact_correction(target, 9000000003, pr=418),
            artifact_correction(target, 9000000004, head="f" * 40),
            artifact_correction(target, 9000000005, base="e" * 40),
            artifact_correction(target, 9000000006, role="appsec"),
            artifact_correction(target, 9000000007, login="not-owner"),
            artifact_correction(target, 9000000008, created_at="2026-11-16T00:00:00Z"),
            artifact_correction(target, 9000000009, reason="legacy_artifact_schema_invalid"),
        ]
        for item in bad:
            with self.subTest(id=item["id"]), self.assertRaises(admission.AdmissionRefused):
                admission._verified_report_artifacts(
                    [target, item], pr_number=417, head_sha=HEAD, base_sha=BASE,
                    owner=OWNER)

    def test_unrelated_malformed_owner_artifact_remains_fail_closed(self):
        artifacts = [x for x in records() if x["kind"] == "artifact"]
        corrections = [artifact_correction(item, 9100000000 + item["id"])
                       for item in artifacts]
        unrelated = owner_comment(9200000001,
                                  "WPCG_RELEASE_REVIEW_ARTIFACT_V3\\nmalformed",
                                  "2030-01-01T00:00:00Z")
        with self.assertRaisesRegex(admission.AdmissionRefused, "review_artifact_invalid"):
            admission._verified_report_artifacts(
                records() + corrections + [unrelated], pr_number=417,
                head_sha=HEAD, base_sha=BASE, owner=OWNER)

    def test_duplicate_exact_artifact_correction_refuses(self):
        target = next(x for x in records() if x["id"] == 6945647897)
        first = artifact_correction(target, 9300000001)
        second = artifact_correction(target, 9300000002,
                                     created_at="2030-01-01T00:01:00Z")
        with self.assertRaisesRegex(admission.AdmissionRefused,
                                    "artifact_correction_ambiguous"):
            admission._verified_report_artifacts(
                [target, first, second], pr_number=417, head_sha=HEAD,
                base_sha=BASE, owner=OWNER)


if __name__ == "__main__":
    unittest.main()
