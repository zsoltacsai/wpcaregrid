#!/usr/bin/env python3
"""Protected-merge, exact-head CI and Nginx route contract tests."""

from __future__ import annotations

import sys
import unittest
from types import SimpleNamespace
from unittest.mock import patch
from urllib.parse import parse_qs, urlsplit
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from scripts import staging_next_deploy_backend_1_4_6 as LEGACY_BACKEND
from scripts import staging_next_deploy_backend_1_4_7 as BACKEND


MERGED_SHA = "a" * 40
PR_HEAD_SHA = "b" * 40


class ReleaseAuthorizationEvidenceTests(unittest.TestCase):
    def api(self, *, omit_job=None, omit_pr=False):
        calls = []
        repo = BACKEND.GITHUB_REPOSITORY
        branch = BACKEND.PRIMARY_BRANCH

        def github_json(path):
            calls.append(path)
            if path == f"/repos/{repo}/commits/{MERGED_SHA}/pulls?per_page=100":
                if omit_pr:
                    return []
                return [{"number": 42, "merge_commit_sha": MERGED_SHA,
                         "base": {"ref": branch}}]
            if path == f"/repos/{repo}/pulls/42":
                return {"number": 42, "merged_at": "2026-11-07T12:00:00Z",
                        "base": {"ref": branch}, "head": {"sha": PR_HEAD_SHA}}
            parsed = urlsplit(path)
            if parsed.path == f"/repos/{repo}/actions/runs":
                query = parse_qs(parsed.query)
                head = query["head_sha"][0]
                event = query["event"][0]
                run_id = 101 if event == "pull_request" else 202
                run = {"id": run_id, "head_sha": head, "event": event,
                       "path": ".github/workflows/ci.yml", "status": "completed",
                       "conclusion": "success", "created_at": "2026-11-07T12:10:00Z",
                       "head_branch": branch,
                       "pull_requests": [{"number": 42, "base": {"ref": branch}}]}
                return {"total_count": 1, "workflow_runs": [run]}
            if parsed.path.startswith(f"/repos/{repo}/actions/runs/") \
                    and parsed.path.endswith("/jobs"):
                jobs = [{"name": name, "status": "completed", "conclusion": "success"}
                        for name in BACKEND.REQUIRED_CI_JOBS if name != omit_job]
                return {"total_count": len(jobs), "jobs": jobs}
            raise AssertionError(f"unexpected GitHub API request: {path}")

        return calls, github_json

    def test_authorization_requires_exact_pr_head_and_merge_sha_ci_without_review_api(self):
        calls, github_json = self.api()
        with patch.object(BACKEND, "_github_json", side_effect=github_json):
            BACKEND._verify_primary_ci(SimpleNamespace(test_only=False), MERGED_SHA)
        self.assertEqual(sum("/actions/runs/" in path and "/jobs?" in path for path in calls), 2)
        self.assertFalse(any("/reviews" in path or "issues/174" in path for path in calls))

    def test_missing_required_ci_job_still_holds_release(self):
        missing = BACKEND.REQUIRED_CI_JOBS[0]
        calls, github_json = self.api(omit_job=missing)
        with patch.object(BACKEND, "_github_json", side_effect=github_json):
            with self.assertRaises(BACKEND.DeployRefused) as error:
                BACKEND._verify_primary_ci(SimpleNamespace(test_only=False), MERGED_SHA)
        self.assertEqual(str(error.exception), "required_ci_check_failed")
        self.assertFalse(any("/reviews" in path or "issues/174" in path for path in calls))

    def test_missing_protected_primary_merge_evidence_still_holds_release(self):
        calls, github_json = self.api(omit_pr=True)
        with patch.object(BACKEND, "_github_json", side_effect=github_json):
            with self.assertRaises(BACKEND.DeployRefused) as error:
                BACKEND._verify_primary_ci(SimpleNamespace(test_only=False), MERGED_SHA)
        self.assertEqual(str(error.exception), "merged_pr_evidence_missing_or_ambiguous")


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


    def test_unlisted_external_source_remains_rejected(self):
        dump = self.dump(certbot_include=True).replace(
            b"/etc/letsencrypt/options-ssl-nginx.conf",
            b"/etc/letsencrypt/other.conf")
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_staging_next_nginx_route(dump)
        self.assertEqual(str(error.exception), "nginx_config_source_identity_invalid")

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
