#!/usr/bin/env python3
"""Synthetic exact-HEAD review ledger and Nginx route contract tests."""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts import staging_next_deploy_backend_1_4_6 as LEGACY_BACKEND
from scripts import staging_next_deploy_backend_1_4_7 as BACKEND


HEAD = "a" * 40


_COMMENT_ID = 100


def comment(role: str, reviewer: str, review_id: int, *, author="repo-owner", association="OWNER",
            pr=42, head=HEAD, decision="PASS", created="2026-11-07T10:00:00Z"):
    global _COMMENT_ID
    _COMMENT_ID += 1
    return {
        "id": _COMMENT_ID, "user": {"login": author}, "author_association": association,
        "created_at": created,
        "body": ("WPCG_RELEASE_REVIEW_V2\n"
                 f"role={role}\npr={pr}\nhead_sha={head}\nreviewer={reviewer}\n"
                 f"review_id={review_id}\ndecision={decision}\n"),
    }


def review(login: str, *, state="APPROVED", head=HEAD, review_id=1, submitted="2026-11-07T10:00:00Z"):
    return {"id": review_id, "user": {"login": login}, "state": state,
            "commit_id": head, "submitted_at": submitted}


class ReleaseEvidenceTests(unittest.TestCase):
    def test_role_records_bind_to_exact_pr_head_and_distinct_reviewers(self):
        comments = [comment("integration_qa", "qa-user", 1),
                    comment("m17_appsec", "security-user", 2)]
        records = BACKEND._release_review_records(comments, pr_number=42, head_sha=HEAD,
                                                  repository_owner="repo-owner")
        qa = records["integration_qa"]
        appsec = records["m17_appsec"]
        self.assertEqual((qa[0], qa[1], qa[2], appsec[0], appsec[1], appsec[2]),
                         ("qa-user", "PASS", 1, "security-user", "PASS", 2))

    def test_stale_head_or_other_pr_is_not_evidence(self):
        comments = [comment("integration_qa", "qa-user", 1, head="b" * 40),
                    comment("m17_appsec", "security-user", 2, pr=43)]
        self.assertEqual(BACKEND._release_review_records(comments, pr_number=42, head_sha=HEAD,
                                                         repository_owner="repo-owner"), {})

    def test_latest_role_decision_overrides_an_older_pass(self):
        comments = [comment("integration_qa", "qa-user", 1, created="2026-11-07T10:00:00Z"),
                    comment("integration_qa", "qa-user", 1, decision="CHANGES_REQUIRED",
                            created="2026-11-07T11:00:00Z")]
        records = BACKEND._release_review_records(comments, pr_number=42, head_sha=HEAD,
                                                  repository_owner="repo-owner")
        self.assertEqual(records["integration_qa"],
                         ("qa-user", "CHANGES_REQUIRED", 1, "2026-11-07T11:00:00Z"))

    def test_malformed_claim_fails_closed(self):
        with self.assertRaises(BACKEND.DeployRefused):
            BACKEND._release_review_records([{"body": "WPCG_RELEASE_REVIEW_V2\nrole=QA"}],
                                            pr_number=42, head_sha=HEAD,
                                            repository_owner="repo-owner")

    def test_role_claim_from_non_owner_is_rejected(self):
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._release_review_records(
                [comment("integration_qa", "attacker", 1, author="attacker", association="CONTRIBUTOR")],
                pr_number=42, head_sha=HEAD, repository_owner="repo-owner")
        self.assertEqual(str(error.exception), "release_review_ledger_record_invalid")

    def test_review_roles_require_distinct_exact_head_github_approvals(self):
        reviews = [review("qa-user", review_id=1), review("security-user", review_id=2)]
        ledger = [comment("integration_qa", "qa-user", 1), comment("m17_appsec", "security-user", 2)]
        BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                    head_sha=HEAD, pr_author="release-author",
                                                    repository_owner="repo-owner")

    def test_same_approved_author_cannot_satisfy_both_roles(self):
        reviews = [review("one-reviewer", review_id=1), review("other-reviewer", review_id=2)]
        ledger = [comment("integration_qa", "one-reviewer", 1),
                  comment("m17_appsec", "one-reviewer", 2)]
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                        head_sha=HEAD, pr_author="release-author",
                                                        repository_owner="repo-owner")
        self.assertEqual(str(error.exception), "independent_review_evidence_not_independent")

    def test_changes_requested_or_wrong_head_approval_does_not_pass(self):
        reviews = [review("qa-user", state="CHANGES_REQUESTED", review_id=1),
                   review("security-user", head="b" * 40, review_id=2)]
        ledger = [comment("integration_qa", "qa-user", 1),
                  comment("m17_appsec", "security-user", 2)]
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                        head_sha=HEAD, pr_author="release-author",
                                                        repository_owner="repo-owner")
        self.assertEqual(str(error.exception), "independent_review_evidence_missing")

    def test_pr_author_cannot_satisfy_independent_role(self):
        reviews = [review("release-author", review_id=1), review("security-user", review_id=2)]
        ledger = [comment("integration_qa", "release-author", 1),
                  comment("m17_appsec", "security-user", 2)]
        with self.assertRaises(BACKEND.DeployRefused):
            BACKEND._verify_independent_release_reviews(reviews, ledger, pr_number=42,
                                                        head_sha=HEAD, pr_author="release-author",
                                                        repository_owner="repo-owner")

    def test_owner_attestation_must_reference_actual_exact_head_approval(self):
        reviews = [review("qa-user", review_id=1), review("security-user", review_id=2)]
        ledger = [comment("integration_qa", "qa-user", 2),
                  comment("m17_appsec", "security-user", 2)]
        with self.assertRaises(BACKEND.DeployRefused):
            BACKEND._verify_independent_release_reviews(
                reviews, ledger, pr_number=42, head_sha=HEAD, pr_author="release-author",
                repository_owner="repo-owner")

    def test_owner_attestation_must_follow_the_referenced_review(self):
        reviews = [review("qa-user", review_id=1), review("security-user", review_id=2)]
        ledger = [comment("integration_qa", "qa-user", 1, created="2026-11-07T09:00:00Z"),
                  comment("m17_appsec", "security-user", 2)]
        with self.assertRaises(BACKEND.DeployRefused):
            BACKEND._verify_independent_release_reviews(
                reviews, ledger, pr_number=42, head_sha=HEAD, pr_author="release-author",
                repository_owner="repo-owner")


class NginxRouteTests(unittest.TestCase):
    @staticmethod
    def dump(*, app_socket="unix:/run/php/php8.5-fpm.sock", duplicate=False, extra=True,
             certbot_include=False, conflicting_certbot_source=False):
        app = """server {
            listen 443 ssl;
            server_name staging-next.wpcaregrid.com;
            CERTBOT_INCLUDE
            location ~ \\.php$ { include snippets/fastcgi-php.conf; }
        }
        """
        app = app.replace("CERTBOT_INCLUDE", (
            "include /etc/letsencrypt/options-ssl-nginx.conf;" if certbot_include else ""
        ))
        if duplicate:
            app += app
        http = """server {
            listen 80;
            server_name staging-next.wpcaregrid.com;
            return 301 https://$host$request_uri;
        }
        """
        app += http
        other = "server { listen 443 ssl; server_name unrelated.example; }" if extra else ""
        main = "http { include /etc/nginx/sites-enabled/*.conf; }"
        snippet = f"fastcgi_pass {app_socket};\n"
        dump = (f"# configuration file /etc/nginx/nginx.conf:\n{main}\n"
                f"# configuration file /etc/nginx/sites-enabled/staging-next.conf:\n{app}{other}\n")
        if certbot_include:
            certbot = "ssl_protocols TLSv1.2 TLSv1.3;\n"
            dump += f"# configuration file /etc/letsencrypt/options-ssl-nginx.conf:\n{certbot}"
            dump += "# configuration file /etc/letsencrypt/options-ssl-nginx.conf:\n"
            dump += "ssl_protocols TLSv1.2 TLSv1.3;\n" if not conflicting_certbot_source else "ssl_protocols TLSv1.2;\n"
        dump += ("# configuration file /etc/nginx/snippets/fastcgi-php.conf:\n"
                 f"{snippet}")
        return dump.encode()

    def test_included_https_vhost_is_counted_once_with_separate_http_vhost(self):
        # nginx -T contains both the root include expansion and each included
        # file's own source section. The HTTPS vhost must not be counted twice.
        BACKEND._verify_staging_next_nginx_route(self.dump(extra=True))


    def test_live_letsencrypt_external_include_and_repeated_nginx_t_source_regression(self):
        dump = self.dump(certbot_include=True)
        with self.assertRaises(LEGACY_BACKEND.DeployRefused) as error:
            LEGACY_BACKEND._verify_staging_next_nginx_route(dump)
        self.assertEqual(str(error.exception), "nginx_config_source_identity_invalid")
        BACKEND._verify_staging_next_nginx_route(dump)

    def test_repeated_allowed_source_must_have_identical_bytes(self):
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_staging_next_nginx_route(
                self.dump(certbot_include=True, conflicting_certbot_source=True))
        self.assertEqual(str(error.exception), "nginx_config_source_identity_conflict")

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
