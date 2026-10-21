#!/usr/bin/env python3
"""Regression tests for canonical, non-replaced Git source extraction."""

from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]
PREPARER = ROOT / "scripts/staging-diagnostics/provisioning/prepare-package.sh"
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


class CanonicalPackagePreparationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="caregrid-canonical-git-")
        self.repo = Path(self.temp.name) / "repo"
        self.repo.mkdir()
        for relative in PACKAGE_FILES:
            target = self.repo / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, target)
        preparer_copy = self.repo / "scripts/staging-diagnostics/provisioning/prepare-package.sh"
        preparer_copy.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(PREPARER, preparer_copy)
        self.git("init", "-q")
        self.git("config", "user.name", "Synthetic Test")
        self.git("config", "user.email", "synthetic@example.invalid")
        self.git("add", ".")
        self.git("commit", "-qm", "approved synthetic package")
        self.approved_sha = self.git("rev-parse", "HEAD")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def git(self, *args: str) -> str:
        return subprocess.check_output(
            ["git", "-C", str(self.repo), *args], text=True
        ).strip()

    def invoke(
        self,
        *,
        extra_env: dict[str, str] | None = None,
        extra_args: tuple[str, ...] = (),
    ) -> subprocess.CompletedProcess[str]:
        env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
        if extra_env:
            env.update(extra_env)
        relative = "scripts/staging-diagnostics/provisioning/prepare-package.sh"
        entry = subprocess.check_output(
            [
                "git",
                "--no-replace-objects",
                "-C",
                str(self.repo),
                "ls-tree",
                self.approved_sha,
                "--",
                relative,
            ],
            text=True,
        ).strip()
        metadata, found_path = entry.split("\t", 1)
        mode, kind, blob_oid = metadata.split()
        if found_path != relative or kind != "blob" or mode not in {"100644", "100755"}:
            raise AssertionError("canonical helper blob missing or unsafe")
        helper = subprocess.check_output(
            ["git", "--no-replace-objects", "-C", str(self.repo), "cat-file", "blob", blob_oid],
        )
        repo_arg = str(self.repo)
        result = subprocess.run(
            [
                "bash",
                "-s",
                "--",
                "--verify-only",
                repo_arg,
                self.approved_sha,
                *extra_args,
            ],
            env=env,
            input=helper,
            capture_output=True,
            check=False,
        )
        return subprocess.CompletedProcess(
            args=result.args,
            returncode=result.returncode,
            stdout=result.stdout.decode("utf-8", errors="replace"),
            stderr=result.stderr.decode("utf-8", errors="replace"),
        )

    def assert_rejected(self, classification: str, **kwargs: object) -> None:
        result = self.invoke(**kwargs)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn(f"code={classification}", result.stderr)

    def test_canonical_source_package_passes(self) -> None:
        result = self.invoke()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "SOURCE_PROVENANCE=PASS")

    def test_modified_working_tree_preparer_is_rejected(self) -> None:
        working_copy = self.repo / "scripts/staging-diagnostics/provisioning/prepare-package.sh"
        working_copy.write_text("#!/bin/bash\nsudo /bin/sh -c 'echo unsafe'\n", encoding="ascii")
        self.assert_rejected("SOURCE_TREE_DIRTY")

    def test_rejects_replace_ref_that_changes_old_git_show_resolution(self) -> None:
        target = self.repo / PACKAGE_FILES[0]
        target.write_text("unreviewed replacement\n", encoding="ascii")
        self.git("commit", "-qam", "replacement content")
        replacement_sha = self.git("rev-parse", "HEAD")
        self.git("replace", self.approved_sha, replacement_sha)

        self.assertEqual(
            self.git("show", f"{self.approved_sha}:{PACKAGE_FILES[0]}"),
            "unreviewed replacement",
        )
        self.assertEqual(self.git("status", "--porcelain"), "")
        self.assert_rejected("GIT_REPLACE_REFS_REJECTED")

    def test_rejects_manipulated_git_environment_before_git_runs(self) -> None:
        self.assert_rejected(
            "UNEXPECTED_GIT_ENVIRONMENT",
            extra_env={"GIT_DIR": str(self.repo / ".git")},
        )

    def test_rejects_alternate_object_directory_metadata(self) -> None:
        alternates = self.repo / ".git/objects/info/alternates"
        alternates.parent.mkdir(parents=True, exist_ok=True)
        alternates.write_text("/tmp/untrusted-objects\n", encoding="ascii")
        self.assert_rejected("GIT_EXTERNAL_OBJECTS_REJECTED")

    def test_rejects_git_config_include_and_replace_controls(self) -> None:
        self.git("config", "--local", "include.path", "./untrusted-config")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")

    def test_rejects_local_object_replacement_configuration(self) -> None:
        self.git("config", "--local", "core.useReplaceRefs", "true")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")

    def test_rejects_repository_local_safe_directory_override(self) -> None:
        self.git("config", "--local", "safe.directory", "*")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")

    def test_rejects_repository_alias_configuration(self) -> None:
        marker = Path(self.temp.name) / "alias-must-not-run"
        self.git("config", "--local", "alias.untrusted", f"!touch {marker}")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")
        self.assertFalse(marker.exists())

    def test_rejects_command_line_attempt_to_add_git_configuration(self) -> None:
        self.assert_rejected(
            "USAGE",
            extra_args=("--config=safe.directory=*",),
        )

    def test_rejects_git_configuration_environment_override(self) -> None:
        self.assert_rejected(
            "UNEXPECTED_GIT_ENVIRONMENT",
            extra_env={"GIT_CONFIG_COUNT": "1", "GIT_CONFIG_KEY_0": "safe.directory", "GIT_CONFIG_VALUE_0": "*"},
        )

    def test_rejects_partial_clone_promisor_configuration(self) -> None:
        self.git("config", "--local", "remote.origin.promisor", "true")
        self.assert_rejected("GIT_PROVENANCE_CONFIG_REJECTED")


if __name__ == "__main__":
    unittest.main(verbosity=2)
