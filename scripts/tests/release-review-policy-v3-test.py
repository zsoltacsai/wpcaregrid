#!/usr/bin/env python3
"""Synthetic tests for the solo-Owner AI-agent release review contract (V3)."""

from __future__ import annotations

import importlib.util
import hashlib
import inspect
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("release_review_policy_v3", ROOT / "scripts" / "release_review_policy_v3.py")
R = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = R
SPEC.loader.exec_module(R)

OWNER = "zsoltacsai"
SHA = "a" * 40
NEW = "b" * 40
DIGEST = "c" * 64
_ids = iter(range(1, 10_000))


def record(role="integration_qa", *, pr=329, sha=SHA, agent=None, decision="PASS", digest=DIGEST,
           created="2026-11-09T01:00:00Z", login=OWNER, association="OWNER", body=None):
    agent = agent or ("agent-qa-01" if role == "integration_qa" else "agent-appsec-01")
    return {
        "id": next(_ids), "created_at": created, "author_association": association,
        "user": {"login": login},
        "body": body if body is not None else R.render_record(
            role=role, pr=pr, head_sha=sha, agent=agent, decision=decision, evidence_sha256=digest),
    }


def verify(comments, *, pr=329, sha=SHA, owner=OWNER):
    return R.verify_agent_release_reviews(comments, pr_number=pr, head_sha=sha, repository_owner=owner)


def refusal(comments, **kw):
    with unittest.TestCase().assertRaises(R.ReviewRefused) as ctx:
        verify(comments, **kw)
    return str(ctx.exception)


def legacy_malformed(comment_id, role, agent):
    body = (f"{R.RECORD_PREFIX}\nrole={role}\npr=377\nhead_sha={NEW}\nagent={agent}\n"
            f"reviewer_kind=ai_agent\ndecision=PASS\nevidence_sha256={DIGEST}")
    return {"id": comment_id, "created_at": "2026-11-12T19:42:46Z",
            "author_association": "OWNER", "user": {"login": OWNER}, "body": body}


def correction(target, correction_id, *, digest=None, pr=377, sha=NEW, role=None,
               login=OWNER, association="OWNER", created="2026-11-14T12:00:00Z"):
    body = R.render_correction(
        target_comment_id=target["id"],
        target_body_sha256=digest or hashlib.sha256(target["body"].encode()).hexdigest(),
        target_pr=pr, target_head_sha=sha, target_role=role or
        ("integration_qa" if "role=integration_qa" in target["body"] else "appsec"))
    return {"id": correction_id, "created_at": created,
            "author_association": association, "user": {"login": login}, "body": body}


def actual_pr377_legacy_records():
    return [
        {"id": 6834079554, "created_at": "2026-11-12T19:42:46Z",
         "author_association": "OWNER", "user": {"login": OWNER},
         "body": "WPCG_RELEASE_REVIEW_V3\nrole=integration_qa\npr=377\n"
                 "head_sha=4196dab735b2f73dd2b207afa960596741eda8c5\n"
                 "agent=Hermione\nreviewer_kind=ai_agent\ndecision=PASS\n"
                 "evidence_sha256=7ba0b9d471cd4a771f25f80763cffa40e94f7715069beaac7fd95731111accb5"},
        {"id": 6834080385, "created_at": "2026-11-12T19:42:48Z",
         "author_association": "OWNER", "user": {"login": OWNER},
         "body": "WPCG_RELEASE_REVIEW_V3\nrole=appsec\npr=377\n"
                 "head_sha=4196dab735b2f73dd2b207afa960596741eda8c5\n"
                 "agent=Mad-Eye Moody\nreviewer_kind=ai_agent\ndecision=PASS\n"
                 "evidence_sha256=6cfd2b49cc3ffdc6f012a6469bf4cc54637dd89dc6b739b2cebaabe0c477ffe4"},
    ]


def actual_pr383_records():
    return [
        {"id": 6865700799, "created_at": "2026-11-13T21:45:57Z",
         "author_association": "OWNER", "user": {"login": OWNER},
         "body": "WPCG_RELEASE_REVIEW_V3\nrole=appsec\npr=383\n"
                 "head_sha=aecc24e679de7537385d2625bbf6ef9455a89303\n"
                 "agent=mad-eye-moody-appsec-gpt-6-astra\nreviewer_kind=ai_agent\n"
                 "decision=PASS\nevidence_sha256=e45a6d40c66ed643d277b88261af237d336829e5bb76896837e34e960a073c5d"},
        {"id": 6868980658, "created_at": "2026-11-14T01:01:36Z",
         "author_association": "OWNER", "user": {"login": OWNER},
         "body": "WPCG_RELEASE_REVIEW_V3\nrole=integration_qa\npr=383\n"
                 "head_sha=aecc24e679de7537385d2625bbf6ef9455a89303\n"
                 "agent=codex-gpt-6-luna-pr383-integration-qa\nreviewer_kind=ai_agent\n"
                 "decision=PASS\nevidence_sha256=93ddca4b09fbf52c8c7f65fc13221950280c561b05e17fc988aebdca685e504b"},
    ]


class AgentReviewTests(unittest.TestCase):
    def test_both_roles_pass_without_any_github_review_or_second_user(self):
        result = verify([record("integration_qa"), record("appsec")])
        self.assertEqual(set(result), {"integration_qa", "appsec"})
        self.assertEqual(result["appsec"]["reviewer_kind"], "ai_agent")
        # The contract takes no GitHub review objects at all.
        self.assertEqual(list(inspect.signature(R.verify_agent_release_reviews).parameters),
                         ["comments", "pr_number", "head_sha", "repository_owner"])

    def test_missing_role_is_refused(self):
        self.assertEqual(refusal([record("integration_qa")]), "review_evidence_missing")
        self.assertEqual(refusal([record("appsec")]), "review_evidence_missing")
        self.assertEqual(refusal([]), "review_evidence_missing")

    def test_old_pass_cannot_be_reused_for_a_new_head(self):
        old = [record("integration_qa"), record("appsec")]
        self.assertEqual(refusal(old, sha=NEW), "review_evidence_missing")

    def test_pass_for_another_pr_is_not_reused(self):
        other = [record("integration_qa", pr=330), record("appsec", pr=330)]
        self.assertEqual(refusal(other, pr=329), "review_evidence_missing")

    def test_one_role_refreshed_but_other_stale_is_refused(self):
        mixed = [record("integration_qa", sha=NEW), record("appsec", sha=SHA)]
        self.assertEqual(refusal(mixed, sha=NEW), "review_evidence_missing")

    def test_latest_non_pass_decision_wins(self):
        for decision in ("CHANGES_REQUIRED", "BLOCKED"):
            comments = [record("integration_qa"), record("appsec"),
                        record("appsec", decision=decision, created="2026-11-09T02:00:00Z")]
            self.assertEqual(refusal(comments), "review_not_pass")

    def test_later_pass_supersedes_earlier_change_request(self):
        comments = [record("integration_qa"), record("appsec", decision="CHANGES_REQUIRED"),
                    record("appsec", created="2026-11-09T03:00:00Z")]
        self.assertEqual(verify(comments)["appsec"]["decision"], "PASS")

    def test_same_timestamp_is_ordered_by_comment_id(self):
        first = record("appsec", decision="CHANGES_REQUIRED")
        second = record("appsec")
        self.assertGreater(second["id"], first["id"])
        self.assertEqual(verify([record("integration_qa"), second, first])["appsec"]["decision"], "PASS")

    def test_roles_must_be_separate_agents(self):
        comments = [record("integration_qa", agent="same-agent"), record("appsec", agent="same-agent")]
        self.assertEqual(refusal(comments), "review_roles_not_separate")

    def test_records_by_other_users_never_count_and_cannot_disturb(self):
        spoof = [record("integration_qa", login="mallory", association="NONE"),
                 record("appsec", login="mallory", association="NONE")]
        self.assertEqual(refusal(spoof), "review_evidence_missing")
        junk = record(login="mallory", association="NONE", body=R.RECORD_PREFIX + "\nnonsense")
        self.assertEqual(len(verify([junk, record("integration_qa"), record("appsec")])), 2)

    def test_owner_login_without_owner_association_is_refused(self):
        bad = record("integration_qa", association="COLLABORATOR")
        self.assertEqual(refusal([bad, record("appsec")]), "review_record_invalid")

    def test_malformed_owner_records_fail_closed(self):
        bodies = [
            R.RECORD_PREFIX + "\n",
            R.render_record(role="appsec", pr=329, head_sha=SHA, agent="agent-appsec-01",
                            decision="PASS", evidence_sha256=DIGEST).replace("ai_agent", "human"),
            R.render_record(role="appsec", pr=329, head_sha=SHA, agent="agent-appsec-01",
                            decision="PASS", evidence_sha256=DIGEST).replace("PASS", "APPROVED"),
            R.render_record(role="appsec", pr=329, head_sha=SHA, agent="agent-appsec-01",
                            decision="PASS", evidence_sha256=DIGEST) + "extra=1\n",
            R.render_record(role="appsec", pr=329, head_sha=SHA, agent="agent-appsec-01",
                            decision="PASS", evidence_sha256=DIGEST).replace(SHA, SHA[:39]),
            R.render_record(role="appsec", pr=329, head_sha=SHA, agent="agent-appsec-01",
                            decision="PASS", evidence_sha256=DIGEST).replace(DIGEST, "G" * 64),
        ]
        for body in bodies:
            self.assertEqual(refusal([record(body=body)]), "review_record_invalid", body)

    def test_historical_agent_label_records_need_exact_append_only_invalidation(self):
        old_qa, old_appsec = actual_pr377_legacy_records()
        current_appsec, current_qa = actual_pr383_records()
        comments = [old_qa, old_appsec, current_appsec, current_qa]
        self.assertEqual(refusal(comments, pr=383, sha=SHA), "review_record_invalid")
        comments.extend([correction(old_qa, 7_000_000_001, sha="4196dab735b2f73dd2b207afa960596741eda8c5"),
                         correction(old_appsec, 7_000_000_002, sha="4196dab735b2f73dd2b207afa960596741eda8c5")])
        accepted = verify(comments, pr=383,
                          sha="aecc24e679de7537385d2625bbf6ef9455a89303")
        self.assertEqual(set(accepted), {"integration_qa", "appsec"})
        self.assertEqual(accepted["appsec"]["evidence_sha256"],
                         "e45a6d40c66ed643d277b88261af237d336829e5bb76896837e34e960a073c5d")
        self.assertEqual(accepted["integration_qa"]["evidence_sha256"],
                         "93ddca4b09fbf52c8c7f65fc13221950280c561b05e17fc988aebdca685e504b")
        # Invalidation cannot turn the old claims into evidence for their own subject.
        self.assertEqual(refusal(comments, pr=377, sha=NEW), "review_evidence_missing")

    def test_correction_is_exact_owner_bound_and_cannot_rewrite_history(self):
        old = legacy_malformed(7003, "integration_qa", "Hermione")
        cases = [
            correction(old, 7101, digest="0" * 64),
            correction(old, 7102, pr=378),
            correction(old, 7103, sha=SHA),
            correction(old, 7104, role="appsec"),
            correction(old, 7108, created="2026-11-01T00:00:00Z"),
        ]
        for invalid in cases:
            self.assertIn(refusal([old, invalid]), {
                "review_correction_target_invalid", "review_correction_subject_mismatch",
                "review_correction_not_append_only"})
        outsider = correction(old, 7105, login="mallory", association="NONE")
        self.assertEqual(refusal([old, outsider]), "review_record_invalid")
        malformed_owner = correction(old, 7109, association="COLLABORATOR")
        self.assertEqual(refusal([old, malformed_owner]), "review_correction_invalid")
        valid = record("integration_qa")
        forged = correction(valid, 7106)
        self.assertEqual(refusal([valid, forged, record("appsec")]), "review_correction_target_invalid")

    def test_duplicate_corrections_are_ambiguous_and_fail_closed(self):
        old = legacy_malformed(7007, "integration_qa", "Hermione")
        first = correction(old, 7107)
        second = correction(old, 7108)
        self.assertEqual(refusal([old, first, second]), "review_correction_ambiguous")
        self.assertEqual(refusal([old, dict(old), first]), "review_evidence_ambiguous")

    def test_render_rejects_what_the_verifier_would_reject(self):
        good = dict(role="appsec", pr=1, head_sha=SHA, agent="agent-appsec-01", decision="PASS",
                    evidence_sha256=DIGEST)
        R.render_record(**good)
        for change in ({"role": "m17_appsec"}, {"decision": "APPROVED"}, {"head_sha": "abc"},
                       {"agent": "A"}, {"agent": "a\nb"}, {"evidence_sha256": "1"}, {"pr": "1"}, {"pr": 0}):
            with self.assertRaises(R.ReviewRefused, msg=change):
                R.render_record(**{**good, **change})

    def test_invalid_subject_and_evidence_container_are_refused(self):
        for kwargs in ({"pr": 0}, {"pr": "329"}, {"sha": "xyz"}, {"owner": ""}):
            self.assertEqual(refusal([], **kwargs), "review_subject_invalid")
        for comments in (None, "text", {"a": 1}, 5):
            with self.assertRaises(R.ReviewRefused):
                verify(comments)

    def test_non_mapping_and_non_string_comments_are_ignored(self):
        junk = [None, 5, "x", {"body": 5}, {"body": None}, {}]
        self.assertEqual(refusal(junk), "review_evidence_missing")

    def test_v2_records_do_not_satisfy_v3(self):
        v2 = record(body="WPCG_RELEASE_REVIEW_V2\nrole=integration_qa\npr=329\nhead_sha=" + SHA
                         + "\nreviewer=x\nreview_id=1\ndecision=PASS\n")
        self.assertEqual(refusal([v2]), "review_evidence_missing")

    def test_module_is_pure_policy(self):
        text = (ROOT / "scripts" / "release_review_policy_v3.py").read_text(encoding="utf-8")
        for forbidden in ("import subprocess", "import socket", "urllib", "requests", "os.system", "open("):
            self.assertNotIn(forbidden, text)


if __name__ == "__main__":
    unittest.main()
