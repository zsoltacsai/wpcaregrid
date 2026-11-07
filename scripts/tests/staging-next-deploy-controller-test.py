#!/usr/bin/env python3
"""Linux tests for exact-SHA release packaging and fixed CLI scope."""

from __future__ import annotations

import hashlib
import importlib.util
import io
import os
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
SPEC = importlib.util.spec_from_file_location("staging_next_controller", ROOT / "scripts" / "staging_next_deploy_controller.py")
CONTROLLER = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = CONTROLLER
SPEC.loader.exec_module(CONTROLLER)

from scripts import staging_next_deploy_backend as BACKEND


class StagingNextControllerTests(unittest.TestCase):
    def setUp(self):
        if os.name != "posix":
            self.skipTest("git archive packaging is validated on Linux CI")
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-controller-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "source"
        self.repo.mkdir()
        self.incoming = self.root / "incoming"
        self.incoming.mkdir(mode=0o700)
        self._git("init", "--quiet", "-b", CONTROLLER.PRIMARY_BRANCH)
        self._git("config", "user.name", "WP CareGrid test")
        self._git("config", "user.email", "wpcg-test@example.invalid")
        self.entries = {
            ".gitattributes": b"/.github export-ignore\nCHANGELOG.md export-ignore\n.styleci.yml export-ignore\n",
            ".github/workflows/ci.yml": b"name: synthetic workflow\n",
            "CHANGELOG.md": b"synthetic changelog\n",
            ".styleci.yml": b"preset: laravel\n",
            "artisan": b"<?php // synthetic runtime\n",
            "composer.json": b'{"require":{"php":"^8.5"}}\n',
            "composer.lock": b'{"packages":[]}\n',
            "package.json": b'{"name":"wpcg-test","version":"1.0.0","scripts":{"build":"node scripts/build.js"}}\n',
            "package-lock.json": b'{"name":"wpcg-test","version":"1.0.0","lockfileVersion":3,"requires":true,"packages":{"":{"name":"wpcg-test","version":"1.0.0"}}}\n',
            "bootstrap/app.php": b"<?php return new stdClass();\n",
            "public/index.php": b"<?php // entry\n",
            "routes/web.php": b"<?php // routes\n",
            "resources/views/marketing/home.blade.php": b"<h1>synthetic</h1>\n",
            "scripts/build.js": (b"const fs=require('fs');fs.mkdirSync('public/build/assets',{recursive:true});"
                                 b"fs.writeFileSync('public/build/assets/app-test.js','synthetic');"
                                 b"fs.writeFileSync('public/build/manifest.json',JSON.stringify({"
                                 b"'resources/js/app.js':{file:'assets/app-test.js',isEntry:true}}));\n"),
        }
        self._write_commit()
        self.base_sha = self._git("rev-parse", "HEAD").strip()
        self._git("commit", "--quiet", "--allow-empty", "-m", "synthetic application release")
        self.app_sha = self._git("rev-parse", "HEAD").strip()
        CONTROLLER.SOURCE_CHECKOUT = self.repo
        CONTROLLER.INCOMING_DIRECTORY = self.incoming

    def _git(self, *args):
        return subprocess.run(["git", "-C", str(self.repo), *args], check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5,
                              text=True).stdout

    def _write_commit(self):
        for name, raw in self.entries.items():
            path = self.repo / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(raw)
        self._git("add", "-A")
        self._git("commit", "--quiet", "--allow-empty", "-m", "synthetic release")

    def test_package_manifest_is_visible_and_content_matches_git_tree(self):
        artifact_path, artifact_sha = CONTROLLER._package_release(self.app_sha, self.base_sha, test_only=True)
        self.assertEqual(hashlib.sha256(artifact_path.read_bytes()).hexdigest(), artifact_sha)
        with tarfile.open(artifact_path, "r:") as archive:
            members = archive.getmembers()
            names = [member.name for member in members]
            self.assertEqual(names.count("release-manifest.json"), 1)
            manifest = BACKEND._strict_json(archive.extractfile("release-manifest.json").read())
            self.assertEqual(manifest["application_sha"], self.app_sha)
            self.assertEqual(manifest["base_application_sha"], self.base_sha)
            self.assertEqual(manifest["files_sha256"], BACKEND._canonical_files_hash({
                member.name: hashlib.sha256(archive.extractfile(member).read()).hexdigest()
                for member in members if member.name != "release-manifest.json"
            }))
        destination = self.root / "extract"
        destination.mkdir()
        raw = artifact_path.read_bytes()
        files, _manifest = BACKEND._extract_archive(raw, destination, app_sha=self.app_sha,
            base_sha=self.base_sha, artifact_sha=artifact_sha,
            helper_artifact_sha256=CONTROLLER.HELPER_ARTIFACT_SHA256)
        excluded = {".github/workflows/ci.yml", "CHANGELOG.md", ".styleci.yml"}
        self.assertEqual(set(name for name in files if not name.startswith("public/build/")),
                         set(self.entries) - excluded)
        self.assertIn("public/build/manifest.json", files)
        self.assertTrue(BACKEND._git_archive_excludes(".github/workflows/ci.yml"))
        self.assertTrue(BACKEND._git_archive_excludes("CHANGELOG.md"))
        self.assertTrue(BACKEND._git_archive_excludes(".styleci.yml"))

    def test_symlink_in_primary_cannot_be_silently_omitted_from_artifact(self):
        (self.repo / "resources/views/marketing/link.blade.php").symlink_to("home.blade.php")
        self._git("add", "-A")
        self._git("commit", "--quiet", "-m", "unsafe symlink fixture")
        unsafe_sha = self._git("rev-parse", "HEAD").strip()
        with self.assertRaises(CONTROLLER.ControllerHold) as error:
            CONTROLLER._package_release(unsafe_sha, self.base_sha)
        self.assertEqual(error.exception.code, "source_archive_member_invalid")
        self.assertFalse((self.incoming / f"{unsafe_sha}.tar").exists())

    def test_cli_does_not_accept_environment_or_path_overrides(self):
        with mock.patch.object(CONTROLLER, "sync", side_effect=AssertionError("sync must not run")):
            self.assertEqual(CONTROLLER.main(["sync", "--environment=production"]), 3)
            self.assertEqual(CONTROLLER.main(["deploy", "--app-dir=/var/www/wpcaregrid"]), 3)

    def test_exact_active_primary_is_noop_without_build_or_staging(self):
        digest = "a" * 40
        helper_result = {
            "result": "PASS", "application_sha": digest, "helper_version": "1.4.3",
            "helper_contract": "wpcaregrid-staging-next-deploy-v7",
            "helper_artifact_sha256": CONTROLLER.HELPER_ARTIFACT_SHA256,
            "artifact_sha256": "b" * 64,
        }
        def helper(operation, *args):
            if operation == "deploy":
                return {"result": "NO_OP", "reason": "exact_release_already_active",
                        "application_sha": digest, "previous_sha": digest,
                        "artifact_sha256": "b" * 64}
            return helper_result
        with (mock.patch.object(CONTROLLER, "_verify_install_identity"),
              mock.patch.object(CONTROLLER, "_helper", side_effect=helper) as helper_mock,
              mock.patch.object(CONTROLLER, "_latest_primary_sha", return_value=digest),
              mock.patch.object(CONTROLLER, "_package_release", side_effect=AssertionError("build on no-op")),
              mock.patch.object(CONTROLLER, "_git", side_effect=AssertionError("fetch on no-op"))):
            result = CONTROLLER.sync()
        self.assertEqual(result, {
            "result": "NO_OP", "reason": "exact_release_already_active",
            "application_sha": digest, "previous_sha": digest,
            "artifact_sha256": "b" * 64,
        })
        self.assertEqual(helper_mock.call_args_list, [
            mock.call("preflight"), mock.call("deploy", digest, "b" * 64)])


if __name__ == "__main__":
    unittest.main(verbosity=2)
