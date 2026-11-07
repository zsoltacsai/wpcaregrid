#!/usr/bin/env python3
"""Synthetic exact-HEAD review ledger and Nginx route contract tests."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts import staging_next_deploy_backend as BACKEND


HEAD = "a" * 40


_COMMENT_ID = 100


def comment(login: str, role: str, *, pr=42, head=HEAD, decision="PASS", created="2026-11-07T10:00:00Z"):
    global _COMMENT_ID
    _COMMENT_ID += 1
    return {
        "id": _COMMENT_ID, "user": {"login": login}, "created_at": created,
        "body": ("WPCG_RELEASE_REVIEW_V1\n"
                 f"role={role}\npr={pr}\nhead_sha={head}\ndecision={decision}\n"),
    }


def review(login: str, *, state="APPROVED", head=HEAD, review_id=1, submitted="2026-11-07T10:00:00Z"):
    return {"id": review_id, "user": {"login": login}, "state": state,
            "commit_id": head, "submitted_at": submitted}


class ReleaseEvidenceTests(unittest.TestCase):
    def test_role_records_bind_to_exact_pr_head_and_distinct_reviewers(self):
        comments = [comment("qa-user", "integration_qa"), comment("security-user", "m17_appsec")]
        records = BACKEND._release_review_records(comments, pr_number=42, head_sha=HEAD)
        qa = records["integration_qa"]
        appsec = records["m17_appsec"]
        self.assertEqual((qa[0], qa[1], appsec[0], appsec[1]),
                         ("qa-user", "PASS", "security-user", "PASS"))

    def test_stale_head_or_other_pr_is_not_evidence(self):
        comments = [comment("qa-user", "integration_qa", head="b" * 40),
                    comment("security-user", "m17_appsec", pr=43)]
        self.assertEqual(BACKEND._release_review_records(comments, pr_number=42, head_sha=HEAD), {})

    def test_latest_role_decision_overrides_an_older_pass(self):
        comments = [comment("qa-user", "integration_qa", created="2026-11-07T10:00:00Z"),
                    comment("qa-user", "integration_qa", decision="CHANGES_REQUIRED",
                            created="2026-11-07T11:00:00Z")]
        records = BACKEND._release_review_records(comments, pr_number=42, head_sha=HEAD)
        self.assertEqual(records["integration_qa"], ("qa-user", "CHANGES_REQUIRED"))

    def test_malformed_claim_fails_closed(self):
        with self.assertRaises(BACKEND.DeployRefused):
            BACKEND._release_review_records([{"body": "WPCG_RELEASE_REVIEW_V1\nrole=QA"}],
                                            pr_number=42, head_sha=HEAD)

    def test_review_roles_require_distinct_exact_head_github_approvals(self):
        reviews = [review("qa-user", review_id=1), review("security-user", review_id=2)]
        ledger = [comment("qa-user", "integration_qa"), comment("security-user", "m17_appsec")]
        BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                    head_sha=HEAD, pr_author="release-author")

    def test_same_approved_author_cannot_satisfy_both_roles(self):
        reviews = [review("one-reviewer", review_id=1), review("other-reviewer", review_id=2)]
        ledger = [comment("one-reviewer", "integration_qa"),
                  comment("one-reviewer", "m17_appsec")]
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                        head_sha=HEAD, pr_author="release-author")
        self.assertEqual(str(error.exception), "independent_review_evidence_not_independent")

    def test_changes_requested_or_wrong_head_approval_does_not_pass(self):
        reviews = [review("qa-user", state="CHANGES_REQUESTED", review_id=1),
                   review("security-user", head="b" * 40, review_id=2)]
        ledger = [comment("qa-user", "integration_qa"), comment("security-user", "m17_appsec")]
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                        head_sha=HEAD, pr_author="release-author")
        self.assertEqual(str(error.exception), "independent_review_evidence_missing")

    def test_pr_author_cannot_satisfy_independent_role(self):
        reviews = [review("release-author", review_id=1), review("security-user", review_id=2)]
        ledger = [comment("release-author", "integration_qa"), comment("security-user", "m17_appsec")]
        with self.assertRaises(BACKEND.DeployRefused):
            BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                        head_sha=HEAD, pr_author="release-author")


class NginxRouteTests(unittest.TestCase):
    @staticmethod
    def dump(*, app_socket="unix:/run/php/php8.5-fpm.sock", duplicate=False, extra=True):
        app = """server {
            listen 443 ssl;
            server_name staging-next.wpcaregrid.com;
            location ~ \\.php$ { include snippets/fastcgi-php.conf; }
        }
        """
        if duplicate:
            app += app
        other = "server { listen 443 ssl; server_name unrelated.example; }" if extra else ""
        main = "http { include /etc/nginx/sites-enabled/*.conf; }"
        snippet = f"fastcgi_pass {app_socket};\n"
        return (f"# configuration file /etc/nginx/nginx.conf:\n{main}\n"
                f"# configuration file /etc/nginx/sites-enabled/staging-next.conf:\n{app}{other}\n"
                "# configuration file /etc/nginx/snippets/fastcgi-php.conf:\n"
                f"{snippet}") .encode()

    def test_multiple_server_blocks_are_allowed_and_app_vhost_uses_expected_socket(self):
        BACKEND._verify_staging_next_nginx_route(self.dump(extra=True))

    def test_wrong_php_fpm_socket_is_rejected(self):
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_staging_next_nginx_route(
                self.dump(app_socket="unix:/run/php/php8.4-fpm.sock"))
        self.assertEqual(str(error.exception), "nginx_staging_fpm_route_mismatch")

    def test_ambiguous_duplicate_staging_https_vhost_is_rejected(self):
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_staging_next_nginx_route(self.dump(duplicate=True))
        self.assertEqual(str(error.exception), "nginx_staging_https_vhost_ambiguous")

    def test_missing_include_source_is_rejected(self):
        with self.assertRaises(BACKEND.DeployRefused):
            BACKEND._verify_staging_next_nginx_route(
                b"# configuration file /etc/nginx/nginx.conf:\nhttp { include /etc/nginx/missing.conf; }\n")


if __name__ == "__main__":
    unittest.main(verbosity=2)
