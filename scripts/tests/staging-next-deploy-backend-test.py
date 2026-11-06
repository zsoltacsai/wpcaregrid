#!/usr/bin/env python3
"""Linux integration tests for the real filesystem deploy transaction."""

from __future__ import annotations

import hashlib
import base64
import http.server
import io
import json
import os
import socketserver
import subprocess
import sys
import tarfile
import tempfile
import threading
import unittest
from unittest import mock
from dataclasses import replace
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from scripts.staging_next_deploy_backend import (
    PROFILE, PRIMARY_BRANCH, StagingProfile, _canonical_files_hash, deploy_release, stage_artifact,
)
from scripts import staging_next_deploy_backend as BACKEND


def file_hashes(entries):
    return {name: hashlib.sha256(data).hexdigest() for name, data in entries.items()}


class FixtureHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        route = self.path.split("?", 1)[0]
        expected_auth = "Basic " + base64.b64encode(b"e2e-owner:synthetic-only-password").decode("ascii")
        if self.headers.get("Authorization") != expected_auth:
            self.send_response(401)
            self.send_header("WWW-Authenticate", 'Basic realm="staging-next-test"')
            self.end_headers()
            self.wfile.write(b"unauthorized")
            return
        status = {"/up": 200, "/hu": 200, "/en": 200}.get(route, 404)
        if os.environ.get("WPCG_FIXTURE_FAIL") == "1" and status == 200:
            status = 503
        self.send_response(status)
        self.end_headers()
        self.wfile.write(b"ok" if status == 200 else b"not found")

    def log_message(self, *_args):
        pass


class IsolatedHTTPServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True


class StagingDeployBackendTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.php_process = None
        if os.environ.get("WPCG_PHP_MYSQL_E2E") == "1":
            cls.failure_flag = Path("/root") / f"wpcg-php-fixture-fail-{os.getpid()}"
            cls.failure_flag.unlink(missing_ok=True)
            import socket
            with socket.socket() as probe:
                probe.bind(("127.0.0.1", 0))
                port = probe.getsockname()[1]
            env = os.environ.copy()
            env["WPCG_FIXTURE_FAIL_FILE"] = str(cls.failure_flag)
            cls.php_process = subprocess.Popen(
                ["php", "-S", f"127.0.0.1:{port}", "scripts/tests/staging-next-php-mysql-router.php"],
                env={**env, "WPCG_TEST_HTTP_AUTH_USER": "e2e-owner",
                     "WPCG_TEST_HTTP_AUTH_PASSWORD": "synthetic-only-password"},
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            origin = f"http://127.0.0.1:{port}"
            import time
            for _ in range(30):
                if cls.php_process.poll() is not None:
                    raise RuntimeError("PHP acceptance fixture did not start")
                try:
                    import urllib.request
                    request = urllib.request.Request(origin + "/up", headers={
                        "Authorization": "Basic " + base64.b64encode(
                            b"e2e-owner:synthetic-only-password").decode("ascii")})
                    with urllib.request.urlopen(request, timeout=1) as response:
                        if response.status == 200:
                            break
                except Exception:
                    time.sleep(0.25)
            else:
                cls.php_process.terminate()
                raise RuntimeError("PHP/MySQL acceptance fixture did not become ready")
        else:
            cls.http = IsolatedHTTPServer(("127.0.0.1", 0), FixtureHandler)
            cls.http_thread = threading.Thread(target=cls.http.serve_forever, daemon=True)
            cls.http_thread.start()
            origin = f"http://127.0.0.1:{cls.http.server_port}"
        cls.acceptance = ((origin + "/up", 200), (origin + "/hu", 200),
                          (origin + "/en", 200), (origin + "/checkout", 404))

    @classmethod
    def tearDownClass(cls):
        if cls.php_process is not None:
            cls.php_process.terminate()
            try:
                cls.php_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                cls.php_process.kill()
                cls.php_process.wait(timeout=5)
            cls.failure_flag.unlink(missing_ok=True)
        else:
            cls.http.shutdown()
            cls.http.server_close()

    def setUp(self):
        if os.geteuid() != 0:
            self.skipTest("filesystem ownership integration needs the disposable container root")
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-deploy-test-", dir="/root")
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        os.chmod(root, 0o755)
        self.git_repo = root / "source"
        self.git_repo.mkdir()
        self._git(["init", "--quiet"])
        self._git(["checkout", "--quiet", "-b", PRIMARY_BRANCH])
        self._git(["config", "user.name", "WP CareGrid Test"])
        self._git(["config", "user.email", "wpcg-test@example.invalid"])
        self.releases = root / "releases"
        self.incoming = root / "incoming"
        self.inbox = root / "inbox"
        self.state = root / "state"
        self.receipts = root / "receipts"
        self.app_parent = root / "app"
        self.shared = root / "shared"
        self.shared_env = self.shared / ".env"
        self.shared_storage = self.shared / "storage"
        self.shared_vendor = self.shared / "vendor"
        for directory, mode in ((self.releases, 0o755), (self.incoming, 0o700), (self.inbox, 0o755),
                                (self.state, 0o700), (self.receipts, 0o700),
                                (self.app_parent, 0o755), (self.shared, 0o755)):
            directory.mkdir(mode=mode)
            os.chmod(directory, mode)
        os.chown(self.incoming, 1000, 1000)
        self.shared_env.write_text("APP_KEY=base64:synthetic\n")
        os.chmod(self.shared_env, 0o640)
        self.shared_storage.mkdir(mode=0o775)
        os.chmod(self.shared_storage, 0o2770)
        self.shared_vendor.mkdir(mode=0o755)
        (self.shared_vendor / "autoload.php").write_text("<?php // shared test autoloader\n")
        os.chmod(self.shared_vendor / "autoload.php", 0o644)
        self.basic_auth_file = root / "basic-auth.json"
        self.basic_auth_file.write_text(json.dumps({
            "username": "e2e-owner", "password": "synthetic-only-password"}))
        os.chmod(self.basic_auth_file, 0o600)
        self.link = self.app_parent / "current"
        self.base_files = self._runtime_files("old")
        self._write_source_tree(self.base_files)
        self.base_sha = self._git(["rev-parse", "HEAD"]).stdout.decode().strip()
        self.base_path = self.releases / self.base_sha
        self.base_path.mkdir(mode=0o755)
        self._write_tree(self.base_path, self.base_files)
        os.symlink(str(self.shared_env), self.base_path / ".env")
        os.symlink(str(self.shared_storage), self.base_path / "storage")
        os.symlink(str(self.shared_vendor), self.base_path / "vendor")
        base_manifest = {
            "schema": 1, "profile": PROFILE, "application_sha": self.base_sha,
            "base_application_sha": self.base_sha, "php_minimum": "8.5",
            "database_scope": "unchanged", "files_sha256": _canonical_files_hash(file_hashes(self.base_files)),
            "artifact_sha256": "c" * 64, "helper_version": BACKEND.HELPER_VERSION,
            "helper_sha256": "e" * 64, "helper_contract": BACKEND.HELPER_CONTRACT,
            "frontend_build": self._frontend_build_metadata(self.base_files),
        }
        (self.base_path / "release-manifest.json").write_text(json.dumps(base_manifest, separators=(",", ":")))
        os.chmod(self.base_path / "release-manifest.json", 0o644)
        self.link.symlink_to(self.base_path)
        self.profile = StagingProfile(
            name=PROFILE, application_link=self.link, releases_dir=self.releases, inbox_dir=self.inbox,
            incoming_dir=self.incoming,
            state_dir=self.state, receipts_dir=self.receipts, allowed_uid=1000,
            expected_owner_uid=0, acceptance_urls=self.acceptance, test_only=True,
            shared_env_file=self.shared_env, shared_storage_dir=self.shared_storage,
            shared_vendor_dir=self.shared_vendor, basic_auth_file=self.basic_auth_file, mysql_server_uuid_sha256=None,
            integration_qa_reviewer=None, m17_appsec_reviewer=None, test_git_repo=self.git_repo,
            helper_artifact_sha256="e" * 64, php_fpm_uid=0, php_fpm_gid=0,
            protected_acceptance_urls=(self.acceptance[1][0], self.acceptance[2][0]),
        )

    @staticmethod
    def _runtime_files(marketing_text):
        asset_name = "assets/app-old.js" if marketing_text == "old" else "assets/app-new.js"
        vite_manifest = json.dumps({"resources/js/app.js": {"file": asset_name, "isEntry": True}},
                                   sort_keys=True, separators=(",", ":")).encode() + b"\n"
        return {
            "artisan": b"#!/usr/bin/env php\n<?php echo 'Laravel';\n",
            "composer.json": b'{"require":{"php":"^8.5"}}\n',
            "composer.lock": b'{"packages":[]}\n',
            "bootstrap/app.php": b"<?php return new stdClass();\n",
            "public/index.php": b"<?php http_response_code(200);\n",
            "routes/web.php": b"<?php // synthetic marketing routes\n",
            "package-lock.json": b'{"lockfileVersion":3}\n',
            "public/build/manifest.json": vite_manifest,
            f"public/build/{asset_name}": f"synthetic-{marketing_text}".encode(),
            "resources/views/marketing/home.blade.php": marketing_text.encode(),
        }

    @staticmethod
    def _write_tree(base, entries):
        for name, data in entries.items():
            path = base / name
            path.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            path.write_bytes(data)
            os.chmod(path, 0o644)
            os.chown(path, 0, 0)
        for directory in sorted((p for p in base.rglob("*") if p.is_dir()), reverse=True):
            os.chmod(directory, 0o755)
            os.chown(directory, 0, 0)
        os.chmod(base, 0o755)
        os.chown(base, 0, 0)

    def _archive(self, entries, *, app_sha, base_sha):
        archive_entries = dict(entries)
        archive_entries.setdefault("package-lock.json", b'{"lockfileVersion":3}\n')
        if not any(name.startswith("public/build/") for name in archive_entries):
            archive_entries["public/build/manifest.json"] = b'{"resources/js/app.js":{"file":"assets/app.js"}}\n'
            archive_entries["public/build/assets/app.js"] = b"synthetic"
        frontend_manifest = self._frontend_build_metadata(archive_entries)
        manifest = {
            "schema": 1, "profile": PROFILE, "application_sha": app_sha,
            "base_application_sha": base_sha, "php_minimum": "8.5",
            "database_scope": "unchanged", "files_sha256": _canonical_files_hash(file_hashes(archive_entries)),
            "helper_version": BACKEND.HELPER_VERSION, "helper_sha256": self.profile.helper_artifact_sha256,
            "helper_contract": BACKEND.HELPER_CONTRACT, "frontend_build": frontend_manifest,
        }
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w:") as archive:
            for name, data in sorted(archive_entries.items()):
                info = tarfile.TarInfo(name)
                info.size = len(data)
                info.mode = 0o644
                info.uid = 0
                info.gid = 0
                archive.addfile(info, io.BytesIO(data))
            raw = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
            info = tarfile.TarInfo("release-manifest.json")
            info.size = len(raw)
            info.mode = 0o644
            archive.addfile(info, io.BytesIO(raw))
        return buffer.getvalue()

    @staticmethod
    def _frontend_build_metadata(entries):
        frontend_files = {name: digest for name, digest in file_hashes(entries).items()
                          if name.startswith("public/build/")}
        return {
            "node_version": "v22.23.3", "npm_version": "10.9.9",
            "package_lock_sha256": hashlib.sha256(entries["package-lock.json"]).hexdigest(),
            "files_sha256": hashlib.sha256("".join(
                f"{name}\0{digest}\n" for name, digest in sorted(frontend_files.items())).encode()).hexdigest(),
        }

    def _submit(self, entries, *, archive=None, target_sha=None, artifact_sha=None):
        target_sha = self._write_source_tree(entries)
        if archive is None:
            archive = self._archive(entries, app_sha=target_sha, base_sha=self.base_sha)
        raw = archive
        digest = artifact_sha or hashlib.sha256(raw).hexdigest()
        incoming = self.incoming / f"{target_sha}.tar"
        if not incoming.exists():
            incoming.write_bytes(raw)
            os.chmod(incoming, 0o600)
            os.chown(incoming, 1000, 1000)
        staged = stage_artifact(self.profile, invoking_uid=1000, application_sha=target_sha,
                                artifact_sha256=digest)
        if staged.status != "PASS":
            return staged
        return deploy_release(self.profile, invoking_uid=1000, application_sha=target_sha,
                              artifact_sha256=digest)

    def _git(self, args):
        return subprocess.run(["git", "-C", str(self.git_repo), *args], check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5)

    def _write_source_tree(self, entries):
        if getattr(self, "last_source_entries", None) == entries:
            return self.last_source_sha
        for child in self.git_repo.iterdir():
            if child.name == ".git":
                continue
            if child.is_dir():
                import shutil
                shutil.rmtree(child)
            else:
                child.unlink()
        for name, data in entries.items():
            if name.startswith("public/build/"):
                continue
            path = self.git_repo / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        self._git(["add", "-A"])
        self._git(["commit", "--quiet", "--allow-empty", "-m", "synthetic exact release"])
        self.last_source_entries = dict(entries)
        self.last_source_sha = self._git(["rev-parse", "HEAD"]).stdout.decode().strip()
        return self.last_source_sha

    def test_real_locked_activation_writes_observed_receipt_and_repeat_is_noop(self):
        entries = self._runtime_files("new")
        result = self._submit(entries)
        target_sha = self.last_source_sha
        self.assertEqual((result.status, result.reason), ("PASS", "release_accepted"))
        self.assertEqual(os.readlink(self.link), str(self.releases / target_sha))
        self.assertEqual(os.stat(self.shared_env).st_mode & 0o777, 0o640)
        receipt = json.loads(Path(result.receipt_path).read_text())
        self.assertEqual(receipt["acceptance"][0]["observed_status"], 200)
        self.assertEqual([entry["observed_status"] for entry in
                          receipt["unauthenticated_protected_acceptance"]], [401, 401])
        self.assertEqual(receipt["active_sha_after_deploy"], target_sha)
        self.assertEqual(hashlib.sha256(Path(result.receipt_path).read_bytes()).hexdigest(), result.receipt_sha256)
        repeat = self._submit(entries)
        self.assertEqual((repeat.status, repeat.reason), ("NO_OP", "exact_release_already_active"))
        no_op_receipt = json.loads(Path(repeat.receipt_path).read_text())
        self.assertEqual([entry["observed_status"] for entry in
                          no_op_receipt["unauthenticated_protected_acceptance"]], [401, 401])

    def test_published_candidate_is_reverified_and_resumed_after_cache_build_interruption(self):
        entries = self._runtime_files("interrupted-cache-build")
        target_sha = self._write_source_tree(entries)
        archive = self._archive(entries, app_sha=target_sha, base_sha=self.base_sha)
        digest = hashlib.sha256(archive).hexdigest()
        incoming = self.incoming / f"{target_sha}.tar"
        incoming.write_bytes(archive)
        os.chmod(incoming, 0o600)
        os.chown(incoming, 1000, 1000)
        self.assertEqual(stage_artifact(self.profile, invoking_uid=1000, application_sha=target_sha,
                                        artifact_sha256=digest).status, "PASS")

        def interrupted_cache_build(_profile, _path):
            raise SystemExit(91)

        with mock.patch.object(BACKEND, "_prepare_application_bootstrap", side_effect=interrupted_cache_build):
            with self.assertRaises(SystemExit):
                deploy_release(self.profile, invoking_uid=1000, application_sha=target_sha,
                               artifact_sha256=digest)
        final = self.releases / target_sha
        self.assertTrue(final.is_dir())
        self.assertEqual(os.readlink(self.link), str(self.base_path))

        resumed = deploy_release(self.profile, invoking_uid=1000, application_sha=target_sha,
                                 artifact_sha256=digest)
        self.assertEqual((resumed.status, resumed.reason), ("PASS", "release_accepted"))
        self.assertEqual(os.readlink(self.link), str(final))

    @unittest.skipUnless(hasattr(os, "fork"), "process termination test requires Linux fork")
    def test_process_death_after_pointer_switch_is_reconciled_before_success_receipt(self):
        entries = self._runtime_files("interrupted-activation")
        target_sha = self._write_source_tree(entries)
        archive = self._archive(entries, app_sha=target_sha, base_sha=self.base_sha)
        digest = hashlib.sha256(archive).hexdigest()
        incoming = self.incoming / f"{target_sha}.tar"
        incoming.write_bytes(archive)
        os.chmod(incoming, 0o600)
        os.chown(incoming, 1000, 1000)
        self.assertEqual(stage_artifact(self.profile, invoking_uid=1000, application_sha=target_sha,
                                        artifact_sha256=digest).status, "PASS")
        pid = os.fork()
        if pid == 0:
            atomic_switch = BACKEND._atomic_symlink

            def switch_then_die(target, link_path, *, expected_owner_uid):
                atomic_switch(target, link_path, expected_owner_uid=expected_owner_uid)
                if link_path == self.link and target.name == target_sha:
                    os._exit(91)

            BACKEND._atomic_symlink = switch_then_die
            deploy_release(self.profile, invoking_uid=1000, application_sha=target_sha,
                           artifact_sha256=digest)
            os._exit(92)
        _pid, status = os.waitpid(pid, 0)
        self.assertEqual(os.waitstatus_to_exitcode(status), 91)
        self.assertEqual(os.readlink(self.link), str(self.releases / target_sha))

        reconciled = deploy_release(self.profile, invoking_uid=1000, application_sha=target_sha,
                                    artifact_sha256=digest)
        self.assertEqual((reconciled.status, reconciled.reason),
                         ("PASS", "interrupted_activation_reconciled"))
        receipt = json.loads(Path(reconciled.receipt_path).read_text())
        self.assertEqual(receipt["result"], "PASS")
        self.assertEqual(receipt["reason"], "interrupted_activation_reconciled")
        self.assertEqual([entry["observed_status"] for entry in
                          receipt["unauthenticated_protected_acceptance"]], [401, 401])

    def test_application_bootstrap_uses_final_immutable_release_path(self):
        entries = self._runtime_files("final-path-cache")
        original = BACKEND._prepare_application_bootstrap
        observed = []

        def record_path(profile, release_path):
            observed.append(Path(release_path))
            return original(profile, release_path)

        with mock.patch.object(BACKEND, "_prepare_application_bootstrap", side_effect=record_path):
            result = self._submit(entries)
        self.assertEqual(result.status, "PASS")
        self.assertEqual(observed, [self.releases / self.last_source_sha])
        self.assertTrue((observed[0] / "resources/views/marketing/home.blade.php").is_file())

    def test_database_scope_change_is_held_without_activation(self):
        entries = self._runtime_files("new") | {"database/migrations/2030_01_01_test.php": b"<?php\n"}
        result = self._submit(entries)
        self.assertEqual((result.status, result.reason), ("HOLD", "database_change_requires_phase_b"))
        self.assertEqual(os.readlink(self.link), str(self.base_path))

    def test_artifact_hash_mismatch_is_held(self):
        result = self._submit(self._runtime_files("new"), artifact_sha="d" * 64)
        self.assertEqual((result.status, result.reason), ("HOLD", "artifact_hash_mismatch"))
        self.assertEqual(os.readlink(self.link), str(self.base_path))

    def test_frontend_manifest_hash_and_builder_version_are_enforced(self):
        metadata = self._frontend_build_metadata(self.base_files)
        bad_hash = dict(metadata, files_sha256="f" * 64)
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_frontend_build(bad_hash, self.base_path, file_hashes(self.base_files))
        self.assertEqual(str(error.exception), "frontend_asset_manifest_mismatch")
        bad_version = dict(metadata, node_version="v24.0.0")
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_frontend_build(bad_version, self.base_path, file_hashes(self.base_files))
        self.assertEqual(str(error.exception), "frontend_builder_version_unsupported")

    def test_vite_manifest_path_traversal_is_rejected(self):
        vite_path = self.base_path / "public/build/manifest.json"
        vite_path.write_text('{"resources/js/app.js":{"file":"../../outside.js"}}\n', encoding="utf-8")
        entries = dict(self.base_files, **{"public/build/manifest.json": vite_path.read_bytes()})
        metadata = self._frontend_build_metadata(entries)
        with self.assertRaises(BACKEND.DeployRefused) as error:
            BACKEND._verify_frontend_build(metadata, self.base_path, file_hashes(entries))
        self.assertEqual(str(error.exception), "vite_asset_path_invalid")

    def test_wrong_caller_is_held_before_filesystem_mutation(self):
        result = deploy_release(self.profile, invoking_uid=1001, application_sha="b" * 40,
                                artifact_sha256="d" * 64)
        self.assertEqual((result.status, result.reason), ("HOLD", "caller_identity_invalid"))
        self.assertFalse((self.state / "deploy.lock").exists())

    def test_partial_runtime_archive_cannot_replace_full_application(self):
        result = self._submit({"resources/views/marketing/home.blade.php": b"partial"})
        self.assertEqual((result.status, result.reason), ("HOLD", "artifact_source_file_set_mismatch"))
        self.assertEqual(os.readlink(self.link), str(self.base_path))

    def test_symlink_archive_member_is_rejected(self):
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w:") as archive:
            info = tarfile.TarInfo("resources/views/marketing/evil")
            info.type = tarfile.SYMTYPE
            info.linkname = "../../outside"
            archive.addfile(info)
        raw = buffer.getvalue()
        result = self._submit({}, archive=raw)
        self.assertEqual(result.status, "HOLD")
        self.assertEqual(os.readlink(self.link), str(self.base_path))

    def test_failed_live_acceptance_rolls_back_and_writes_fail_receipt(self):
        if self.php_process is not None:
            self.failure_flag.write_text("fail\n")
        else:
            os.environ["WPCG_FIXTURE_FAIL"] = "1"
        try:
            result = self._submit(self._runtime_files("unhealthy"))
        finally:
            os.environ.pop("WPCG_FIXTURE_FAIL", None)
            if self.php_process is not None:
                self.failure_flag.unlink(missing_ok=True)
        self.assertEqual((result.status, result.reason), ("FAIL", "post_deploy_acceptance_failed"))
        self.assertEqual(os.readlink(self.link), str(self.base_path))
        receipt = json.loads(Path(result.receipt_path).read_text())
        self.assertEqual(receipt["result"], "FAIL")
        self.assertEqual(receipt["active_sha_after_recovery"], self.base_sha)

    def test_external_basic_auth_missing_is_rejected(self):
        class UnprotectedHandler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200)
                self.end_headers()
                self.wfile.write(b"synthetic unprotected response")

            def log_message(self, *_args):
                pass

        server = IsolatedHTTPServer(("127.0.0.1", 0), UnprotectedHandler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        origin = f"http://127.0.0.1:{server.server_port}"
        probe_profile = replace(
            self.profile,
            acceptance_urls=((origin + "/up", 200), (origin + "/hu", 200), (origin + "/en", 200)),
            protected_acceptance_urls=(origin + "/hu", origin + "/en"),
        )
        try:
            passed, authenticated, unauthenticated = BACKEND._bounded_acceptance(probe_profile)
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2)
        self.assertEqual(unauthenticated, (200, 200))
        self.assertEqual(authenticated[:3], (200, 200, 200))
        self.assertFalse(passed)

    def test_exchange_that_fails_after_pointer_switch_is_rolled_back(self):
        original = BACKEND._atomic_symlink

        def switch_then_fail(target, link_path, *, expected_owner_uid):
            original(target, link_path, expected_owner_uid=expected_owner_uid)
            if target.name != self.base_sha:
                raise BACKEND.DeployRefused("activation_directory_sync_failed")

        with mock.patch.object(BACKEND, "_atomic_symlink", side_effect=switch_then_fail):
            result = self._submit(self._runtime_files("new"))
        self.assertEqual((result.status, result.reason), ("FAIL", "activation_directory_sync_failed"))
        self.assertEqual(os.readlink(self.link), str(self.base_path))
        receipt = json.loads(Path(result.receipt_path).read_text())
        self.assertEqual(receipt["active_sha_after_recovery"], self.base_sha)

    def test_unexpected_post_activation_exception_is_rolled_back(self):
        original = BACKEND._verify_runtime
        calls = 0

        def fail_candidate_after_switch(profile, active_path):
            nonlocal calls
            calls += 1
            if calls == 2:
                raise RuntimeError("synthetic unexpected runtime probe error")
            return original(profile, active_path)

        with mock.patch.object(BACKEND, "_verify_runtime", side_effect=fail_candidate_after_switch):
            result = self._submit(self._runtime_files("new"))
        self.assertEqual((result.status, result.reason), ("FAIL", "backend_internal_error"))
        self.assertEqual(os.readlink(self.link), str(self.base_path))
        receipt = json.loads(Path(result.receipt_path).read_text())
        self.assertEqual(receipt["active_sha_after_recovery"], self.base_sha)

    def test_locked_concurrent_deploy_is_held(self):
        import fcntl
        lock = self.state / "deploy.lock"
        fd = os.open(lock, os.O_CREAT | os.O_RDWR, 0o600)
        os.chown(lock, 0, 0)
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try:
            result = self._submit(self._runtime_files("new"))
            self.assertEqual((result.status, result.reason), ("HOLD", "deployment_lock_busy"))
            self.assertEqual(os.readlink(self.link), str(self.base_path))
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def test_exact_primary_authorization_precedes_any_build_or_mutation(self):
        result = BACKEND.authorize_release(self.profile, invoking_uid=1000,
                                           application_sha=self.base_sha)
        self.assertEqual((result["result"], result["reason"], result["application_sha"]),
                         ("PASS", "exact_primary_release_authorized", self.base_sha))
        wrong_user = BACKEND.authorize_release(self.profile, invoking_uid=1001,
                                               application_sha=self.base_sha)
        self.assertEqual((wrong_user["result"], wrong_user["reason"]),
                         ("HOLD", "caller_identity_invalid"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
