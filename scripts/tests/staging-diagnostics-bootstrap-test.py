#!/usr/bin/env python3
"""Synthetic tests for the root-owned canonical package bootstrap."""

from __future__ import annotations

import importlib.util
import contextlib
import io
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).parents[2]
BOOTSTRAP_PATH = ROOT / "scripts/staging-diagnostics/provisioning/bootstrap.py"
PACKAGE_FILES = (
    "scripts/staging-diagnostics/dispatch.sh",
    "scripts/staging-diagnostics/provisioning/activation-check.sh",
    "scripts/staging-diagnostics/provisioning/diagnostics-shell.sh",
    "scripts/staging-diagnostics/read-m12-cache.php",
    "scripts/staging-diagnostics/provisioning/run-m12-readback.sh",
    "scripts/staging-diagnostics/provisioning/provision.py",
    "scripts/staging-diagnostics/provisioning/verify.py",
    "scripts/staging-diagnostics/provisioning/rollback.py",
    "scripts/staging-diagnostics/provisioning/bootstrap.py",
)


def load_bootstrap():
    spec = importlib.util.spec_from_file_location("wpcg_bootstrap_under_test", BOOTSTRAP_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class BootstrapTests(unittest.TestCase):
    def setUp(self) -> None:
        if os.name != "posix":
            self.skipTest("Linux filesystem ownership semantics required")
        if os.geteuid() != 0:
            self.skipTest("root-owned package mode verification runs in Debian integration container")
        self.temp = tempfile.TemporaryDirectory(prefix="wpcg-bootstrap-", dir="/var/lib")
        self.root = Path(self.temp.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        for relative in PACKAGE_FILES:
            target = self.repo / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, target)
        self.git("init", "-q")
        self.git("config", "user.name", "Synthetic Bootstrap")
        self.git("config", "user.email", "synthetic@example.invalid")
        self.git("add", ".")
        self.git("commit", "-qm", "reviewed synthetic source")
        self.sha = self.git("rev-parse", "HEAD")
        self.module = load_bootstrap()
        self.module.BASE = self.root / "stage-base"
        self.module.PACKAGE_ROOT = self.module.BASE / "source"
        self.module.STATE_DIR = self.root / "diagnostics-state"
        self.module.BOOTSTRAP_PENDING = self.module.STATE_DIR / "provisioning.bootstrap-pending"

    def tearDown(self) -> None:
        self.temp.cleanup()

    def git(self, *args: str) -> str:
        return subprocess.check_output(["/usr/bin/git", "-C", str(self.repo), *args], text=True).strip()

    def prepare(self) -> Path:
        return self.module.prepare({"repository": str(self.repo), "commit": self.sha, "public_key": "/root/not-read-by-this-test.pub"})

    def test_extracts_exact_committed_blobs_into_root_only_immutable_tree(self) -> None:
        stage = self.prepare()
        self.assertEqual(stage.stat().st_uid, os.geteuid())
        self.assertEqual(stage.stat().st_mode & 0o777, 0o700)
        self.assertEqual((stage / ".wpcaregrid-provenance").stat().st_mode & 0o777, 0o444)
        for relative in PACKAGE_FILES:
            self.assertEqual((stage / relative).read_bytes(), (self.repo / relative).read_bytes())

    def test_dirty_checkout_is_rejected_before_package_creation(self) -> None:
        (self.repo / "untracked.txt").write_text("unreviewed\n", encoding="ascii")
        with self.assertRaises(self.module.BootstrapError):
            self.prepare()
        self.assertFalse((self.module.PACKAGE_ROOT / self.sha).exists())

    def test_rejects_replace_refs_and_alternates(self) -> None:
        self.git("commit", "--allow-empty", "-qm", "replacement commit")
        replacement = self.git("rev-parse", "HEAD")
        self.git("replace", self.sha, replacement)
        self.git("update-ref", "HEAD", self.sha)
        with self.assertRaises(self.module.BootstrapError):
            self.prepare()
        (self.repo / ".git/refs/replace" / self.sha).unlink()
        alternates = self.repo / ".git/objects/info/alternates"
        alternates.write_text("/tmp/external-object-store\n", encoding="ascii")
        with self.assertRaises(self.module.BootstrapError):
            self.prepare()

    def test_rejects_provenance_affecting_local_git_configuration(self) -> None:
        self.git("config", "core.fsmonitor", "true")
        with self.assertRaises(self.module.BootstrapError):
            self.prepare()

    def test_rejects_symlink_in_git_object_store(self) -> None:
        link = self.repo / ".git/objects/aa-untrusted-link"
        link.symlink_to(self.root)
        with self.assertRaises(self.module.BootstrapError):
            self.prepare()

    def test_root_config_requires_exact_fields_safe_metadata_and_unique_keys(self) -> None:
        key = self.root / "diagnostics.pub"
        key.write_text("ssh-ed25519 synthetic-public-key\n", encoding="ascii")
        config = self.root / "bootstrap.json"
        payload = (
            '{"repository":'
            + __import__("json").dumps(str(self.repo))
            + ',"commit":'
            + __import__("json").dumps(self.sha)
            + ',"public_key":'
            + __import__("json").dumps(str(key))
            + '}\n'
        )
        config.write_text(payload, encoding="utf-8")
        config.chmod(0o600)
        with patch.object(self.module, "CONFIG", config):
            self.assertEqual(self.module._read_config()["commit"], self.sha)
            for malformed in ("{", "[]", "{}", payload.replace(self.sha, "not-a-sha")):
                config.write_text(malformed, encoding="utf-8")
                config.chmod(0o600)
                with self.subTest(malformed=malformed[:8]):
                    with self.assertRaises(self.module.BootstrapError):
                        self.module._read_config()
            config.write_text(payload[:-2] + ',"commit":"' + self.sha + '"}\n', encoding="utf-8")
            config.chmod(0o600)
            with self.assertRaises(self.module.BootstrapError):
                self.module._read_config()
            config.unlink()
            config.symlink_to(key)
            with self.assertRaises(self.module.BootstrapError):
                self.module._read_config()

    def test_failed_interrupted_extraction_removes_incomplete_stage(self) -> None:
        original = self.module._blob
        calls = 0

        def interrupted(repository: Path, commit: str, relative: str):
            nonlocal calls
            calls += 1
            if calls == 2:
                raise self.module.BootstrapError("injected")
            return original(repository, commit, relative)

        with patch.object(self.module, "_blob", side_effect=interrupted):
            with self.assertRaises(self.module.BootstrapError):
                self.prepare()
        self.assertFalse((self.module.PACKAGE_ROOT / self.sha).exists())

    def _run_main_with_stage_failure(self, failed_stage: str, failure: BaseException | int, rollback_fails: bool = False):
        key = self.root / "diagnostics.pub"
        key.write_text("ssh-ed25519 synthetic-public-key\n", encoding="ascii")
        key.chmod(0o600)
        config = {"repository": str(self.repo), "commit": self.sha, "public_key": str(key)}
        package = self.root / "immutable-package"
        package.mkdir()
        calls: list[str] = []

        def run_process(command: list[str], **_kwargs: object) -> subprocess.CompletedProcess:
            name = Path(command[3]).name
            calls.append(name)
            if name == failed_stage:
                if isinstance(failure, BaseException):
                    raise failure
                return subprocess.CompletedProcess(command, failure)
            if name == "rollback.py":
                if rollback_fails:
                    raise subprocess.TimeoutExpired(command, 45)
                self.module._remove_bootstrap_journal(self.sha)
            return subprocess.CompletedProcess(command, 0)

        stderr = io.StringIO()
        with (
            patch.object(self.module, "_read_config", return_value=config),
            patch.object(self.module, "prepare", return_value=package.parent),
            patch.object(self.module.subprocess, "run", side_effect=run_process),
            contextlib.redirect_stderr(stderr),
        ):
            result = self.module.main()
        return result, calls, stderr.getvalue()

    def test_provisioner_nonzero_exit_runs_immutable_rollback_and_disables_journal(self) -> None:
        result, calls, error = self._run_main_with_stage_failure("provision.py", 17)
        self.assertEqual(result, 1)
        self.assertEqual(calls, ["provision.py", "rollback.py"])
        self.assertFalse(self.module.BOOTSTRAP_PENDING.exists())
        self.assertEqual(error.strip(), "BOOTSTRAP_FAILED")

    def test_provisioner_timeout_keeps_gate_closed_if_rollback_times_out(self) -> None:
        timeout = subprocess.TimeoutExpired("provision.py", 45)
        result, calls, error = self._run_main_with_stage_failure("provision.py", timeout, rollback_fails=True)
        self.assertEqual(result, 1)
        self.assertEqual(calls, ["provision.py", "rollback.py"])
        self.assertTrue(self.module.BOOTSTRAP_PENDING.exists())
        self.assertEqual(error.strip(), "BOOTSTRAP_FAILED")

    def test_verifier_nonzero_exit_rolls_back_a_completed_provisioner(self) -> None:
        result, calls, _ = self._run_main_with_stage_failure("verify.py", 23)
        self.assertEqual(result, 1)
        self.assertEqual(calls, ["provision.py", "verify.py", "rollback.py"])
        self.assertFalse(self.module.BOOTSTRAP_PENDING.exists())

    def test_verifier_timeout_leaves_a_fail_closed_journal_when_rollback_fails(self) -> None:
        timeout = subprocess.TimeoutExpired("verify.py", 45)
        result, calls, _ = self._run_main_with_stage_failure("verify.py", timeout, rollback_fails=True)
        self.assertEqual(result, 1)
        self.assertEqual(calls, ["provision.py", "verify.py", "rollback.py"])
        self.assertTrue(self.module.BOOTSTRAP_PENDING.exists())

    def test_bootstrap_journal_rejects_existing_or_symlinked_state(self) -> None:
        self.module.STATE_DIR.mkdir(mode=0o711)
        self.module.STATE_DIR.chmod(0o711)
        self.module.BOOTSTRAP_PENDING.symlink_to(self.root / "outside")
        with self.assertRaises(self.module.BootstrapError):
            self.module._write_bootstrap_journal(self.sha)
        self.assertTrue(self.module.BOOTSTRAP_PENDING.is_symlink())

    def test_success_removes_bootstrap_journal_only_after_verifier_success(self) -> None:
        key = self.root / "diagnostics.pub"
        key.write_text("ssh-ed25519 synthetic-public-key\n", encoding="ascii")
        key.chmod(0o600)
        config = {"repository": str(self.repo), "commit": self.sha, "public_key": str(key)}
        package = self.root / "immutable-package"
        package.mkdir()
        observed: list[tuple[str, bool]] = []

        def run_process(command: list[str], **_kwargs: object) -> subprocess.CompletedProcess:
            name = Path(command[3]).name
            observed.append((name, self.module.BOOTSTRAP_PENDING.exists()))
            return subprocess.CompletedProcess(command, 0)

        stdout = io.StringIO()
        with (
            patch.object(self.module, "_read_config", return_value=config),
            patch.object(self.module, "prepare", return_value=package.parent),
            patch.object(self.module.subprocess, "run", side_effect=run_process),
            contextlib.redirect_stdout(stdout),
        ):
            result = self.module.main()

        self.assertEqual(result, 0)
        self.assertEqual(observed, [("provision.py", True), ("verify.py", True)])
        self.assertFalse(self.module.BOOTSTRAP_PENDING.exists())
        self.assertEqual(stdout.getvalue().strip(), "STAGING_DIAGNOSTICS_BOOTSTRAP=PASS")


if __name__ == "__main__":
    unittest.main(verbosity=2)
